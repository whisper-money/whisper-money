#!/usr/bin/env bash

ROOT_PATH=$1

bun i
composer install
cp "$ROOT_PATH/.env" .env
