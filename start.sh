#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
export PATH="${HOME}/.local/bin:${PATH}"

PORT="${PORT:-8081}"
HOST="${HOST:-127.0.0.1}"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP not found. Install php-cli or ensure ~/.local/bin/php is on PATH." >&2
  exit 1
fi

echo "Starting FarmWise at http://${HOST}:${PORT}"
exec php -S "${HOST}:${PORT}" router.php
