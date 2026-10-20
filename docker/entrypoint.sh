#!/bin/bash
set -e

# Carpeta del historial CSV: asegurar que Apache (www-data) puede escribir
mkdir -p /var/www/historial
chown www-data:www-data /var/www/historial 2>/dev/null || true
chmod 775 /var/www/historial 2>/dev/null || true

# Inicializar la BD en background (no bloquea Apache)
/usr/local/bin/init-db.sh &

# Arrancar Apache en foreground
exec apache2-foreground
