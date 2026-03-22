#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

HOST="${HOST:-0.0.0.0}"
PORT="${PORT:-8080}"

find_primary_ipv4() {
  if command -v ifconfig >/dev/null 2>&1; then
    ifconfig | awk '
      /^[a-z0-9]+:/ { iface=$1; sub(":", "", iface) }
      /inet / {
        ip=$2
        if (iface == "en0" && ip != "127.0.0.1") {
          print ip
          exit
        }
      }
    '
  fi
}

require_php() {
  if ! command -v php >/dev/null 2>&1; then
    echo "PHP bulunamadi. PHP 8.2+ kurup tekrar deneyin."
    exit 1
  fi
}

require_php

LOCAL_IP="$(find_primary_ipv4 || true)"

echo "DTZ local server baslatiliyor"
echo "Proje: ${PROJECT_ROOT}"
echo "Host: ${HOST}"
echo "Port: ${PORT}"
echo
echo "Mac Safari:"
echo "http://127.0.0.1:${PORT}/index.html"
echo "http://127.0.0.1:${PORT}/admin.html"
echo
if [[ -n "${LOCAL_IP}" ]]; then
  echo "iPhone / ayni Wi-Fi:"
  echo "http://${LOCAL_IP}:${PORT}/index.html"
  echo "http://${LOCAL_IP}:${PORT}/admin.html"
  echo
else
  echo "Yerel IP otomatik bulunamadi. iPhone icin Mac IP adresinizi manuel kontrol edin."
  echo
fi

echo "Durdurmak icin Ctrl+C"
echo

cd "${PROJECT_ROOT}"
exec php -S "${HOST}:${PORT}" -t .
