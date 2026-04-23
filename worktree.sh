#!/usr/bin/env bash

ROOT_PATH=$1

bun i
composer install
cp "$ROOT_PATH/.env" .env
cp -r "$ROOT_PATH/.pi" .pi
