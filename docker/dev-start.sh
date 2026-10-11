#!/bin/sh
# 開発用コンテナの起動処理。依存の導入・.env の用意・マイグレーションのあと開発サーバーを起動する。
set -eu

composer install --no-interaction --no-progress

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --no-interaction
fi

touch database/database.sqlite
php artisan migrate --no-interaction --force

exec php artisan serve --host=0.0.0.0 --port=8000
