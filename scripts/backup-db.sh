#!/bin/bash
#
# Database backup script for PolyBag
# Dumps all tenant databases, compresses, and uploads to S3-compatible storage.
#
# Usage:
#   ./scripts/backup-db.sh                  # Backup all databases
#   ./scripts/backup-db.sh polybag_acme     # Backup a specific database
#
# Setup:
#   1. Install AWS CLI: apt install awscli
#   2. Create /opt/shared/backup.env with S3 credentials (see server-setup.md)
#   3. Add to root crontab: 0 3 * * * /opt/tenants/<any-tenant>/scripts/backup-db.sh >> /var/log/polybag-backup.log 2>&1
#

set -euo pipefail

# Load config
BACKUP_ENV="/opt/shared/backup.env"
if [ ! -f "$BACKUP_ENV" ]; then
    echo "ERROR: $BACKUP_ENV not found. See server-setup.md for setup instructions."
    exit 1
fi
source "$BACKUP_ENV"

# Defaults
S3_BUCKET="${S3_BUCKET:-polybag}"
S3_ENDPOINT="${S3_ENDPOINT:-https://hel1.your-objectstorage.com}"
S3_PREFIX="${S3_PREFIX:-backups/db}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
MYSQL_CONTAINER="${MYSQL_CONTAINER:-shared-mysql}"
DATE=$(date +%Y-%m-%d_%H%M%S)
TMPDIR=$(mktemp -d)

cleanup() {
    rm -rf "$TMPDIR"
}
trap cleanup EXIT

# Get MySQL root password
MYSQL_ROOT_PASS=$(docker exec "$MYSQL_CONTAINER" printenv MYSQL_ROOT_PASSWORD 2>/dev/null)
if [ -z "$MYSQL_ROOT_PASS" ]; then
    # Try from shared .env
    MYSQL_ROOT_PASS=$(grep '^MYSQL_ROOT_PASSWORD=' /opt/shared/.env 2>/dev/null | cut -d= -f2-)
fi

if [ -z "$MYSQL_ROOT_PASS" ]; then
    echo "ERROR: Could not determine MySQL root password."
    exit 1
fi

# Determine which databases to back up
if [ -n "${1:-}" ]; then
    DATABASES="$1"
else
    DATABASES=$(docker exec "$MYSQL_CONTAINER" mysql -uroot -p"$MYSQL_ROOT_PASS" -N -e \
        "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'polybag%'" 2>/dev/null)
fi

if [ -z "$DATABASES" ]; then
    echo "ERROR: No databases found to back up."
    exit 1
fi

# Export S3 credentials for AWS CLI
export AWS_ACCESS_KEY_ID="$S3_ACCESS_KEY"
export AWS_SECRET_ACCESS_KEY="$S3_SECRET_KEY"

TOTAL=0
FAILED=0

for DB in $DATABASES; do
    FILENAME="${DB}_${DATE}.sql.gz"
    FILEPATH="${TMPDIR}/${FILENAME}"

    echo "Backing up ${DB}..."

    if docker exec "$MYSQL_CONTAINER" mysqldump -uroot -p"$MYSQL_ROOT_PASS" \
        --single-transaction --routines --triggers "$DB" 2>/dev/null | gzip > "$FILEPATH"; then

        SIZE=$(du -h "$FILEPATH" | cut -f1)

        if aws s3 cp "$FILEPATH" "s3://${S3_BUCKET}/${S3_PREFIX}/${FILENAME}" \
            --endpoint-url "$S3_ENDPOINT" --quiet 2>/dev/null; then
            echo "  Uploaded ${FILENAME} (${SIZE})"
            TOTAL=$((TOTAL + 1))
        else
            echo "  ERROR: Failed to upload ${FILENAME}"
            FAILED=$((FAILED + 1))
        fi
    else
        echo "  ERROR: Failed to dump ${DB}"
        FAILED=$((FAILED + 1))
    fi
done

# Prune old backups
if [ "$RETENTION_DAYS" -gt 0 ]; then
    CUTOFF=$(date -d "-${RETENTION_DAYS} days" +%Y-%m-%d 2>/dev/null || date -v-${RETENTION_DAYS}d +%Y-%m-%d)
    echo "Pruning backups older than ${CUTOFF}..."

    aws s3 ls "s3://${S3_BUCKET}/${S3_PREFIX}/" --endpoint-url "$S3_ENDPOINT" 2>/dev/null | while read -r LINE; do
        FILE_DATE=$(echo "$LINE" | awk '{print $NF}' | grep -oP 'polybag[^_]*_\K\d{4}-\d{2}-\d{2}' || true)
        FILENAME=$(echo "$LINE" | awk '{print $NF}')

        if [ -n "$FILE_DATE" ] && [[ "$FILE_DATE" < "$CUTOFF" ]]; then
            aws s3 rm "s3://${S3_BUCKET}/${S3_PREFIX}/${FILENAME}" --endpoint-url "$S3_ENDPOINT" --quiet 2>/dev/null
            echo "  Deleted ${FILENAME}"
        fi
    done
fi

echo "Backup complete. ${TOTAL} succeeded, ${FAILED} failed."

if [ "$FAILED" -gt 0 ]; then
    exit 1
fi
