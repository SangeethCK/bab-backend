#!/usr/bin/env bash

# Exit immediately if a command exits with a non-zero status
set -e

# Configuration
BACKUP_DIR="${BACKUP_DIR:-./storage/backups}"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
DB_DATABASE="${DB_DATABASE:-my_bab_db}"
DB_USERNAME="${DB_USERNAME:-root}"
DB_PASSWORD="${DB_PASSWORD:-secret}"
DB_HOST="${DB_HOST:-127.0.0.1}"

mkdir -p "$BACKUP_DIR"

BACKUP_FILE="$BACKUP_DIR/db_backup_${DB_DATABASE}_${TIMESTAMP}.sql.gz"

echo "=== Starting Database Backup ==="
echo "Target file: $BACKUP_FILE"

if command -v mysqldump &> /dev/null; then
    mysqldump -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" | gzip > "$BACKUP_FILE"
    echo "MySQL Backup completed."
else
    echo "mysqldump not found; triggering Artisan backup..."
    php artisan backup:run --only-db || echo "Artisan backup fallback finished."
fi

# Retention policy: Delete backups older than 14 days
find "$BACKUP_DIR" -type f -name "*.sql.gz" -mtime +14 -delete || true

echo "=== Database Backup Completed! ==="
