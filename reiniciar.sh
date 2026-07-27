#!/usr/bin/env bash
set -Eeuo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

# Detectar Docker Compose
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
    echo "ERROR: la operación falló."

    "${COMPOSE[@]}" logs --tail=100 2>/dev/null || true

    exit "$exit_code"
}

trap show_logs_on_error ERR

mostrar_ayuda() {
    echo "Uso:"
    echo "  $0 normal    Reconstruye php-app y reinicomocia conservando volúmenes."
    echo "  $0 clean     Elimina contenedores, imágenes y volúmenes, y reconstruye todo."
    echo
    echo "También puedes ejecutar:"
    echo "  $0"
    echo "para mostrar el menú."
}

seleccionar_opcion() {
    echo "Seleccione una opción:"
    echo
    echo "  1) Reinicio normal"
    echo "  2) Eliminar todo y reconstruir"
    echo "  3) Cancelar"
    echo

    read -r -p "Opción [1-3]: " opcion

    case "$opcion" in
        1)
            MODO="normal"
            ;;
        2)
            MODO="clean"
            ;;
        3)
            echo "Operación cancelada."
            exit 0
            ;;
        *)
            echo "ERROR: opción no válida."
            exit 1
            ;;
    esac
}

reinicio_normal() {
    echo "=============================================="
    echo " Reinicio normal"
    echo " Proyecto: sistema-record-academico"
    echo " Directorio: $PROJECT_DIR"
    echo "=============================================="

    if [[ composer.json -nt composer.lock ]]; then
        echo "composer.json es más nuevo que composer.lock; sincronizando lock..."
        docker run --rm -v "$PROJECT_DIR:/app" -w /app composer:2 \
            update --lock --no-interaction --no-scripts --ignore-platform-reqs
    fi

    echo
    echo "[1/4] Reconstruyendo imagen php-app..."

    "${COMPOSE[@]}" build php-app

    echo
    echo "[2/4] Levantando o recreando servicios..."

    "${COMPOSE[@]}" up -d \
        --force-recreate \
        --remove-orphans

    echo
    echo "[3/4] Verificando contenedores..."

    sleep 3
    "${COMPOSE[@]}" ps

    echo
    echo "[4/4] Mostrando últimos logs..."

    "${COMPOSE[@]}" logs --tail=30

    echo
    echo "=============================================="
    echo " Reinicio normal completado correctamente"
    echo " Aplicación: http://localhost:8010"
    echo "=============================================="
}

limpieza_completa() {
    echo "=============================================="
    echo " Limpieza completa"
    echo " Proyecto: sistema-record-academico"
    echo " Directorio: $PROJECT_DIR"
    echo "=============================================="

    if [[ composer.json -nt composer.lock ]]; then
        echo "composer.json es más nuevo que composer.lock; sincronizando lock..."
        docker run --rm -v "$PROJECT_DIR:/app" -w /app composer:2 \
            update --lock --no-interaction --no-scripts --ignore-platform-reqs
    fi

    echo
    echo "[1/5] Eliminando contenedores, imágenes y volúmenes..."

    "${COMPOSE[@]}" down \
        --volumes \
        --remove-orphans \
        --rmi all

    echo
    echo "[2/5] Reconstruyendo imágenes sin caché..."

    "${COMPOSE[@]}" build --no-cache

    echo
    echo "[3/5] Levantando nuevamente los servicios..."

    "${COMPOSE[@]}" up -d \
        --force-recreate \
        --remove-orphans

    echo
    echo "[4/5] Verificando contenedores..."

    sleep 5
    "${COMPOSE[@]}" ps

    echo
    echo "[5/5] Mostrando últimos logs..."

    "${COMPOSE[@]}" logs --tail=50

    echo
    echo "=============================================="
    echo " Limpieza y reconstrucción completadas"
    echo " Aplicación: http://localhost:8010"
    echo "=============================================="
}

MODO="${1:-}"

if [[ -z "$MODO" ]]; then
    seleccionar_opcion
fi

case "$MODO" in
    normal|restart|reiniciar)
        reinicio_normal
        ;;
    clean|full|limpiar)
        limpieza_completa
        ;;
    help|-h|--help)
        mostrar_ayuda
        ;;
    *)
        echo "ERROR: modo no válido: $MODO"
        echo
        mostrar_ayuda
        exit 1
        ;;
esac