#!/bin/bash
# Dumps the production database to backups/ and keeps the last 14 days.
# Run from anywhere on the server; schedule with cron, e.g. nightly at 2 AM:
#   0 2 * * * /home/rusel/capstone_hris/deploy/backup.sh
# Copy backups/ to another machine too: the server's disk is a single old drive.
set -euo pipefail

cd "$(dirname "$0")/.."
mkdir -p backups
out="backups/hris-$(date +%Y%m%d-%H%M%S).sql.gz"

docker compose --env-file .env.prod -f compose.prod.yaml exec -T mysql \
    sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --no-tablespaces hris' \
    | gzip > "$out" || { rm -f "$out"; echo "backup failed" >&2; exit 1; }

find backups -name 'hris-*.sql.gz' -mtime +14 -delete
echo "wrote $out"
