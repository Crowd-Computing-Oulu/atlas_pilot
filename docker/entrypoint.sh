#!/bin/sh
set -e

# Railway's runtime image has BOTH mpm_event and mpm_prefork enabled in
# mods-enabled, which is fatal ("More than one MPM loaded"). Build-time
# a2dismod does not stick on Railway, so force prefork-only here at runtime,
# immediately before launching Apache.
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*

echo "[entrypoint] starting; PORT=${PORT:-<unset>}"
echo "[entrypoint] enabled MPM modules: $(ls /etc/apache2/mods-enabled/ 2>/dev/null | grep -i mpm | tr '\n' ' ')"

# Railway mounts the persistent Volume at /data only at runtime, so fix
# ownership here. Apache runs as www-data and must write the SQLite file.
mkdir -p /data
chown -R www-data:www-data /data || true

# Apache must listen on the port Railway injects via $PORT.
PORT="${PORT:-8080}"
sed -ri "s/^Listen 80\$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

echo "[entrypoint] launching apache2-foreground on port ${PORT}"
exec apache2-foreground
