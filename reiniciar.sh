#!/usr/bin/env bash
set -Eeuo pipefail

# Siempre ejecuta el proceso desde la carpeta donde está este script.
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

# Compatibilidad con Docker Compose v2 y docker-compose clásico.
if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE=(docker-compose)
else
    echo "ERROR: Docker Compose no está instalado o no está disponible en PATH."
    exit 1
fi

show_logs_on_error() {
    exit_code=$?

    echo
    echo "ERROR: el despliegue falló."
    echo "Últimos logs disponibles:"
    "${COMPOSE[@]}" logs --tail=100 2>/dev/null || true

    exit "$exit_code"
}

trap show_logs_on_error ERR

echo "=============================================="
echo " Reiniciando sistema-record-academico"
echo " Directorio: $PROJECT_DIR"
echo "=============================================="

echo
echo "[1/6] Deteniendo y eliminando contenedores..."

# --rmi local elimina las imágenes construidas localmente.
# No elimina los volúmenes vendor_data ni var_data.
"${COMPOSE[@]}" down \
    --remove-orphans \
    --rmi local

echo
echo "[2/6] Eliminando imágenes temporales sin etiqueta..."
docker image prune -f >/dev/null

echo
echo "[3/6] Reconstruyendo imágenes desde cero..."
"${COMPOSE[@]}" build \
    --no-cache \
    --pull

echo
echo "[4/6] Levantando nuevamente los servicios..."
"${COMPOSE[@]}" up -d \
    --force-recreate \
    --remove-orphans

echo
echo "[5/6] Actualizando dependencias de Composer en vendor_data..."

# El volumen vendor_data persiste entre reconstrucciones.
# Por eso se ejecuta composer install dentro del contenedor para sincronizar
# /var/www/html/vendor con composer.lock.
"${COMPOSE[@]}" exec -T php-app composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist

echo
echo "Verificando dependencia symfony/cache..."

if "${COMPOSE[@]}" exec -T php-app \
    composer show symfony/cache >/dev/null 2>&1; then

    CACHE_VERSION="$(
        "${COMPOSE[@]}" exec -T php-app \
            composer show symfony/cache \
            --format=json |
        php -r '
            $data = json_decode(stream_get_contents(STDIN), true);
            echo $data["versions"][0] ?? "instalada";
        ' 2>/dev/null || echo "instalada"
    )"

    echo "OK: symfony/cache está instalado: ${CACHE_VERSION}"
else
    echo
    echo "ERROR: symfony/cache no está registrado en composer.json/composer.lock."
    echo
    echo "Ejecuta una sola vez:"
    echo
    echo "  ${COMPOSE[*]} exec php-app composer require symfony/cache"
    echo
    echo "Después vuelve a ejecutar este script."
    exit 1
fi

echo
echo "Regenerando autoload optimizado..."

"${COMPOSE[@]}" exec -T php-app \
    composer dump-autoload \
    --no-dev \
    --optimize \
    --classmap-authoritative \
    --no-interaction

echo
echo "[6/6] Verificando el estado..."
sleep 3

"${COMPOSE[@]}" ps

echo
echo "Últimos logs de los servicios:"
"${COMPOSE[@]}" logs --tail=30

echo
echo "=============================================="
echo " Despliegue completado correctamente"
echo " Aplicación: http://localhost"
echo "=============================================="