#!/bin/sh
set -e

mkdir -p \
  /app/storage/framework/cache \
  /app/storage/framework/sessions \
  /app/storage/framework/views \
  /app/storage/logs \
  /app/bootstrap/cache

chmod -R 775 /app/storage /app/bootstrap/cache

exec supervisord -c /app/docker/supervisord.conf