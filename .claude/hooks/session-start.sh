#!/bin/bash
# Prepares a Claude Code on the web container for development: PHP and JS
# dependencies, a SQLite .env, a migrated and seeded database, and built
# assets. Local machines are left alone.
#
# FAKE_CARRIERS stays off: .env values reach the Pest suite, and carrier tests
# fail with it on. Set it only while running the app itself.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(pwd)}"

# The container runs as root, and Composer disables plugins for root unless
# told otherwise. Pest's plugin is what gives `artisan test` --parallel.
export COMPOSER_ALLOW_SUPERUSER=1
if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
  echo 'export COMPOSER_ALLOW_SUPERUSER=1' >> "$CLAUDE_ENV_FILE"
fi

# The web sandbox's GitHub proxy answers 403 to api.github.com zipball
# downloads, so Composer installs from git clones instead. phpstan/phpstan is
# published without a source repository, so seed Composer's download cache
# with a zip built from the locked commit of its public git repository.
seed_phpstan_dist() {
  local reference url cache_dir cache_file work
  reference=$(php -r '$l = json_decode(file_get_contents("composer.lock"), true);
    foreach (array_merge($l["packages"], $l["packages-dev"]) as $p) {
      if ($p["name"] === "phpstan/phpstan") { echo $p["dist"]["reference"]; }
    }')
  [ -n "$reference" ] || return 0
  url="https://api.github.com/repos/phpstan/phpstan/zipball/${reference}"
  cache_dir="$(composer config cache-files-dir 2>/dev/null)/phpstan/phpstan"
  cache_file="${cache_dir}/$(php -r 'echo sha1($argv[1]);' "$url").zip"
  [ -f "$cache_file" ] && return 0

  work=$(mktemp -d)
  git -C "$work" init -q
  git -C "$work" remote add origin https://github.com/phpstan/phpstan
  GIT_LFS_SKIP_SMUDGE=1 git -C "$work" fetch -q --depth 1 origin "$reference"
  mkdir -p "$cache_dir"
  git -C "$work" archive --format=zip --prefix=phpstan/ -o "$cache_file" FETCH_HEAD
  rm -rf "$work"
}

if [ ! -f vendor/autoload.php ] || [ ! -f vendor/pest-plugins.json ] || [ composer.lock -nt vendor/autoload.php ]; then
  seed_phpstan_dist
  composer install --no-interaction --no-progress --prefer-source
fi

npm ci --no-audit --no-fund

if [ ! -f .env ]; then
  cp .env.local.example .env
  php artisan key:generate --no-interaction
fi

if [ ! -f database/database.sqlite ]; then
  touch database/database.sqlite
  php artisan migrate --seed --force --no-interaction
else
  php artisan migrate --force --no-interaction
fi

npm run build
