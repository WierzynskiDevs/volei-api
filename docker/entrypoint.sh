#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Render injeta PORT; fora do Render (teste local do container) cai no EXPOSE.
export PORT="${PORT:-8080}"

php artisan config:clear >/dev/null

case "${1:-web}" in
    web)
        php artisan migrate --force
        php artisan config:cache
        php artisan route:cache
        php artisan event:cache

        envsubst '${PORT}' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf

        exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
        ;;
    queue)
        # Disparado pelo Render Cron Job (CLAUDE.md §20: fila processa
        # assíncrono; sem worker contínuo grátis, o cron é quem drena a fila).
        # `--stop-when-empty` encerra o processo assim que não há mais job,
        # em vez de ficar residente — essencial porque o Cron Job é cobrado
        # (ou limitado) por tempo de execução.
        exec php artisan queue:work --stop-when-empty --max-time=50 --tries=3
        ;;
    *)
        exec "$@"
        ;;
esac
