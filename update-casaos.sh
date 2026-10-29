#!/bin/bash
# =============================================================
# Actualizar Inventario Taller — instalación existente en CasaOS
# Uso: sudo bash update-casaos.sh
# =============================================================
set -e
REPO="$(cd "$(dirname "$0")" && pwd)"
CONTAINER=inventario2_app

echo "▶ Copiando ficheros directamente al contenedor..."
if docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
    docker cp "$REPO/app/api/config.php"       "$CONTAINER:/var/www/html/api/config.php"
    docker cp "$REPO/app/api/auth.php"         "$CONTAINER:/var/www/html/api/auth.php"
    docker cp "$REPO/app/api/backup.php"       "$CONTAINER:/var/www/html/api/backup.php"
    docker cp "$REPO/app/api/equipos.php"      "$CONTAINER:/var/www/html/api/equipos.php"
    docker cp "$REPO/app/api/movimientos.php"  "$CONTAINER:/var/www/html/api/movimientos.php"
    docker cp "$REPO/app/api/ubicaciones.php"  "$CONTAINER:/var/www/html/api/ubicaciones.php"
    docker cp "$REPO/app/api/catalogos.php"    "$CONTAINER:/var/www/html/api/catalogos.php"
    docker cp "$REPO/app/api/historial.php"     "$CONTAINER:/var/www/html/api/historial.php"
    docker cp "$REPO/app/api/historial_lib.php" "$CONTAINER:/var/www/html/api/historial_lib.php"
    docker cp "$REPO/app/api/almacen_lib.php"   "$CONTAINER:/var/www/html/api/almacen_lib.php"
    docker cp "$REPO/app/api/almacenes.php"     "$CONTAINER:/var/www/html/api/almacenes.php"
    docker cp "$REPO/app/api/.htaccess"        "$CONTAINER:/var/www/html/api/.htaccess"
    docker cp "$REPO/app/index.html"           "$CONTAINER:/var/www/html/index.html"
    docker cp "$REPO/app/img-ies.png"          "$CONTAINER:/var/www/html/img-ies.png"
    echo "   ✅ Ficheros copiados al contenedor"
    # Carpeta del historial CSV (si el contenedor es anterior al volumen, se crea aquí)
    docker exec "$CONTAINER" sh -c 'mkdir -p /var/www/historial && chown www-data:www-data /var/www/historial && chmod 775 /var/www/historial'
else
    echo "   ❌ Contenedor $CONTAINER no está corriendo"
    exit 1
fi

echo ""
echo "▶ Verificando sintaxis PHP..."
for f in auth.php equipos.php movimientos.php ubicaciones.php historial.php historial_lib.php almacen_lib.php almacenes.php; do
    if docker exec "$CONTAINER" php -l "/var/www/html/api/$f" 2>&1 | grep -q "No syntax errors"; then
        echo "   ✅ $f OK"
    else
        echo "   ❌ $f tiene errores de sintaxis:"
        docker exec "$CONTAINER" php -l "/var/www/html/api/$f"
        exit 1
    fi
done

echo ""
echo "✅ Actualización completada."
