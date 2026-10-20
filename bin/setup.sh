#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
command -v openssl >/dev/null
command -v docker >/dev/null
docker compose version >/dev/null
if [ ! -e .env ]; then
    umask 077
    cp .env.example .env
    app_key=$(openssl rand -base64 32)
    db_password=$(openssl rand -hex 24)
    root_password=$(openssl rand -hex 24)
    sed -i "s|^APP_KEY=.*|APP_KEY=base64:$app_key|;s|^DB_PASSWORD=.*|DB_PASSWORD=$db_password|;s|^MYSQL_ROOT_PASSWORD=.*|MYSQL_ROOT_PASSWORD=$root_password|" .env
fi
docker compose up -d --build --wait
docker compose exec app php artisan migrate --force
