#!/usr/bin/env bash
# Sync platform-service permissions on api.lebytek.com and re-issue token for lebytek.com
set -euo pipefail

API_DIR=/home/lebytek-api/htdocs/api.lebytek.com
BO_DIR=/home/lebytek/htdocs/lebytek.com

cd "$API_DIR"

echo "==> Sync roles/permissions + platform service user"
sudo -u lebytek-api php artisan db:seed --class=RolesAndPermissionsSeeder --force
sudo -u lebytek-api php artisan db:seed --class=WaapiServiceSeeder --force
sudo -u lebytek-api php artisan permission:cache-reset

echo "==> Issue new platform token (copy output into lebytek.com LEBYTEK_API_TOKEN)"
sudo -u lebytek-api php artisan integration:issue-waapi-token --revoke

echo "==> lebytek.com mail config (must be SMTP in production)"
grep -E '^MAIL_' "$BO_DIR/.env" 2>/dev/null || echo "WARN: no MAIL_* vars in $BO_DIR/.env — emails go to storage/logs only (MAIL_DRIVER=log)"
