#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="$ROOT/.undo_backups"
mkdir -p "$BACKUP_DIR"

TS="$(date +%Y%m%d_%H%M%S)"
ARCHIVE="$BACKUP_DIR/${TS}.tar.gz"

tar -czf "$ARCHIVE" \
  --exclude='.undo_backups' \
  --exclude='.git' \
  -C "$ROOT" .

ln -sfn "$(basename "$ARCHIVE")" "$BACKUP_DIR/latest"

echo "Yedek alindi: $ARCHIVE"
echo "Geri alma: bash tools/undo_last.sh"
