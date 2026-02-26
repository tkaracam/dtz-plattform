#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="$ROOT/.undo_backups"
LATEST_LINK="$BACKUP_DIR/latest"

if [[ -L "$LATEST_LINK" ]]; then
  ARCHIVE="$BACKUP_DIR/$(readlink "$LATEST_LINK")"
else
  ARCHIVE="$(ls -t "$BACKUP_DIR"/*.tar.gz 2>/dev/null | head -n 1 || true)"
fi

if [[ -z "${ARCHIVE:-}" || ! -f "$ARCHIVE" ]]; then
  echo "Yedek bulunamadi."
  echo "Once: bash tools/save_state.sh"
  exit 1
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

tar -xzf "$ARCHIVE" -C "$TMP_DIR"

# Snapshot'u birebir geri yukle (silinen/eklenen dosyalar dahil)
rsync -a --delete \
  --exclude='.git' \
  --exclude='.undo_backups' \
  "$TMP_DIR"/ "$ROOT"/

echo "Geri yuklendi: $ARCHIVE"
