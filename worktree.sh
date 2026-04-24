#!/usr/bin/env bash

ROOT_PATH=$1

cp "$ROOT_PATH/.env" .env
cp -r "$ROOT_PATH/.pi" .pi

bun i
composer install
