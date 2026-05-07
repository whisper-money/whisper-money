#!/usr/bin/env bash

ROOT_PATH=$1

cp "$ROOT_PATH/.env" .env

bun i
composer install
