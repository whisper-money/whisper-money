#!/usr/bin/env bash

ROOT_PATH=$1

cp "$ROOT_PATH/.env" .env
cp -r "$ROOT_PATH/storage/keys" ./storage/keys

# Passport OAuth signing keys (for the MCP OAuth server). Reuse the main repo's
# keys if present, otherwise generate a fresh pair after deps are installed.
cp "$ROOT_PATH"/storage/oauth-*.key ./storage/ 2>/dev/null || true

bun i
composer install

[ -f ./storage/oauth-private.key ] || php artisan passport:keys
