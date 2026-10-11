# 開発用イメージ。本番では使わない（本番は GitHub Actions 上で静的サイトを生成する）。
FROM composer:2 AS composer

FROM php:8.5-cli-alpine

RUN apk add --no-cache git unzip

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app
