#!/bin/sh
# Web dev server inside Docker (see compose.yaml at the repo root).
set -e

# node_modules is a named volume: install on first start, and again whenever
# package-lock.json changes.
lock_hash=$(sha1sum package-lock.json | cut -d' ' -f1)
if [ "$(cat node_modules/.lock-hash 2>/dev/null)" != "$lock_hash" ]; then
    npm ci
    echo "$lock_hash" > node_modules/.lock-hash
fi

exec npm run dev -- --host 0.0.0.0 --port 5173 --strictPort
