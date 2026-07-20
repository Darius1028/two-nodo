#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE=(docker-compose)
else
    echo "ERROR: Docker Compose no está disponible."
    exit 1
fi

show_logs_on_error() {
    exit_code=$?

    echo
    echo "ERROR: el reinicio falló."
    "${COMPOSE[@]}" logs --tail=100 2>/dev/null || true

    exit "$exit_code"
}

trap show_logs_on_error ERR

echo "=============================================="
echo " Reiniciando sistema-record-academico"
echo " Directorio: $PROJECT_DIR"
echo "=============================================="

echo
echo "[1/3] Levantando o recreando servicios..."

"${COMPOSE[@]}" up -d \
    --force-recreate \
    --remove-orphans

echo
echo "[2/3] Verificando contenedores..."

sleep 3
"${COMPOSE[@]}" ps

echo
echo "[3/3] Mostrando últimos logs..."

"${COMPOSE[@]}" logs --tail=30

echo
echo "=============================================="
echo " Reinicio completado correctamente"
echo " Aplicación: http://localhost"
echo "=============================================="