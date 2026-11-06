#!/usr/bin/env bash
# Runs the module test suite against a local fake bunny.net API.
# Requires: php 8.x with pdo_sqlite and curl, composer, node (for JS syntax checks).
set -euo pipefail
cd "$(dirname "$0")"
[ -d vendor ] || composer install -q
mkdir -p .tmp
php -S 127.0.0.1:8977 fake-bunny.php > /dev/null 2>&1 &
FAKE_PID=$!
trap 'kill $FAKE_PID 2>/dev/null' EXIT
sleep 1
php run.php
