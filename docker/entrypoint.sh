#!/usr/bin/env bash
set -euo pipefail

APP_STORAGE="/var/www/html/api/storage"
DATA_ROOT="${DATA_ROOT:-/var/data}"
DATA_STORAGE="${DATA_ROOT}/storage"

mkdir -p "${DATA_STORAGE}"
chown -R www-data:www-data "${DATA_ROOT}" || true
chmod -R 775 "${DATA_STORAGE}" || true

if [ -d "${APP_STORAGE}" ] && [ ! -L "${APP_STORAGE}" ]; then
  rm -rf "${APP_STORAGE}"
fi

ln -sfn "${DATA_STORAGE}" "${APP_STORAGE}"

if [ ! -f "${DATA_STORAGE}/.htaccess" ]; then
  cat > "${DATA_STORAGE}/.htaccess" <<'HT'
Require all denied
HT
fi

exec apache2-foreground
