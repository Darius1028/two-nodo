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
    echo "ERROR: el reinicio falló. Últimos logs:"
    "${COMPOSE[@]}" logs --tail=100 2>/dev/null || true
    exit "$exit_code"
}

trap show_logs_on_error ERR

echo "=============================================="
echo " Reiniciando sistema-record-academico"
echo " Directorio: $PROJECT_DIR"
echo "=============================================="

echo
echo "[1/5] Deteniendo y eliminando contenedores..."
# --rmi local elimina las imágenes construidas localmente por este proyecto.
# No elimina los volúmenes vendor_data ni var_data.
"${COMPOSE[@]}" down --remove-orphans --rmi local

echo
echo "[2/5] Eliminando imágenes temporales sin etiqueta..."
docker image prune -f >/dev/null

echo
echo "[3/5] Reconstruyendo imágenes desde cero..."
"${COMPOSE[@]}" build --no-cache --pull

echo
echo "[4/5] Levantando nuevamente los servicios..."
"${COMPOSE[@]}" up -d --force-recreate --remove-orphans

echo
echo "[5/5] Verificando el estado..."
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