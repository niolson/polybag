#!/bin/bash
# Prints the php-fpm pool settings for PHP_FPM_MAX_CHILDREN (default 20), for
# the entrypoint to write after the image's own zz-docker.conf.
#
# Every Livewire round trip holds a worker, and a rate shop or label purchase
# holds one for as long as the carriers take to answer. The image's stock
# pool of 5 starts queueing at about 8-10 people packing at once; 20 served
# 20 packers on two CPUs with room to spare (capacity-audit/01). A worker
# adds about 15-20 MB beyond the memory it shares.
#
# Spare-server counts are derived from the maximum, because php-fpm refuses
# to start when either is larger than it.
set -euo pipefail

max="${PHP_FPM_MAX_CHILDREN:-20}"

if ! [[ "$max" =~ ^[1-9][0-9]*$ ]]; then
    echo "PHP_FPM_MAX_CHILDREN must be a positive whole number, not '${max}'." >&2
    exit 1
fi

least() { echo $(( $1 < $2 ? $1 : $2 )); }

cat <<EOF
[www]
pm = dynamic
pm.max_children = ${max}
pm.start_servers = $(least 4 "$max")
pm.min_spare_servers = $(least 2 "$max")
pm.max_spare_servers = $(least 6 "$max")
EOF
