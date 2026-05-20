#!/usr/bin/env bash

ROOT_PATH=$1

cp "$ROOT_PATH/.env" .env
cp -r "$ROOT_PATH//storage/keys" ./storage/keys

bun i
composer install
