# PixelForge

[![CI](https://github.com/javonwebster/pixelforge/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/javonwebster/pixelforge/actions/workflows/ci.yml)

Image generation on Pixelforge, billed at Pixelforge's cost plus 25%.

Live: https://pixelforge.bebettuh.com

Work in progress. The full write-up (how money works, how it stays correct, decisions and trade-offs) is coming.

## Running locally

```bash
docker compose -f compose.local.yaml up -d   # Postgres 16 on 127.0.0.1:5433
composer setup
composer dev
```

Tests run against Postgres (`pixelforge_test`), never SQLite:

```bash
php artisan test
```
