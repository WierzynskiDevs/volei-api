#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Railway (e antes, Render) injeta PORT; localmente cai no EXPOSE.
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
    worker)
        # Serviço "worker" do Railway (ADR 0019) — processo residente, não
        # "drena e sai": diferente do Cron Job do Render que a ADR 0012 usava,
        # o Railway sustenta um worker contínuo de verdade.
        # `--max-time=3600` reinicia o processo a cada hora (higiene de
        # memória de worker PHP de longa duração); a queda é coberta pelo
        # restartPolicyType=ALWAYS do railway.worker.toml.
        exec php artisan queue:work --tries=3 --max-time=3600
        ;;
    *)
        exec "$@"
        ;;
esac
