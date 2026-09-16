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

# Este script está destinado al entorno local, cuya infraestructura incluye
# MinIO y el inicializador idempotente de buckets.
COMPOSE_LOCAL=("${COMPOSE[@]}" --profile local-infra)

show_logs_on_error() {
    exit_code=$?

    echo
    echo "ERROR: la operación falló."

    "${COMPOSE_LOCAL[@]}" logs --tail=100 2>/dev/null || true

    exit "$exit_code"
}

trap show_logs_on_error ERR

mostrar_ayuda() {
    echo "Uso:"
    echo "  $0 normal         Reconstruye la aplicación, conserva volúmenes e inicia MinIO local."
    echo "  $0 clean [--yes]  Elimina contenedores, imágenes y volúmenes, y reconstruye todo."
    echo
    echo "También puedes ejecutar:"
    echo "  $0"
    echo "para mostrar el menú."
}

verificar_salud() {
    echo
    echo "Verificando disponibilidad..."
    "${COMPOSE_LOCAL[@]}" exec -T php-app \
        curl --fail --silent --show-error --retry 10 --retry-delay 1 \
        http://nginx/health/live >/dev/null
    "${COMPOSE_LOCAL[@]}" exec -T php-app \
        curl --fail --silent --show-error --retry 10 --retry-delay 1 \
        http://nginx/health/ready >/dev/null
    echo "Health checks correctos."
}

inicializar_storage() {
    echo
    echo "Inicializando buckets de MinIO..."
    "${COMPOSE_LOCAL[@]}" run --rm storage-init
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
    echo "[1/5] Reconstruyendo imagen php-app..."

    "${COMPOSE_LOCAL[@]}" build php-app

    echo
    echo "[2/5] Levantando o recreando servicios e infraestructura local..."

    "${COMPOSE_LOCAL[@]}" up -d \
        --force-recreate \
        --remove-orphans \
        minio php-app import-worker nginx

    echo
    echo "[3/5] Configurando almacenamiento local..."
    inicializar_storage

    echo
    echo "[4/5] Verificando contenedores..."

    "${COMPOSE_LOCAL[@]}" ps
    verificar_salud

    echo
    echo "[5/5] Mostrando últimos logs..."

    "${COMPOSE_LOCAL[@]}" logs --tail=30

    echo
    echo "=============================================="
    echo " Reinicio normal completado correctamente"
    echo " Aplicación: http://localhost:8010"
    echo "=============================================="
}

limpieza_completa() {
    if [[ "${CONFIRM_CLEAN:-}" != "--yes" ]]; then
        echo "ADVERTENCIA: se eliminarán los volúmenes locales, incluidos los datos de MinIO."
        read -r -p "Escribe ELIMINAR para continuar: " confirmacion
        if [[ "$confirmacion" != "ELIMINAR" ]]; then
            echo "Operación cancelada."
            return 0
        fi
    fi

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
    echo "[1/6] Eliminando contenedores, imágenes y volúmenes..."

    "${COMPOSE_LOCAL[@]}" down \
        --volumes \
        --remove-orphans \
        --rmi all

    echo
    echo "[2/6] Reconstruyendo imágenes sin caché..."

    "${COMPOSE_LOCAL[@]}" build --no-cache php-app

    echo
    echo "[3/6] Levantando nuevamente los servicios e infraestructura local..."

    "${COMPOSE_LOCAL[@]}" up -d \
        --force-recreate \
        --remove-orphans \
        minio php-app import-worker nginx

    echo
    echo "[4/6] Configurando almacenamiento local..."
    inicializar_storage

    echo
    echo "[5/6] Verificando contenedores..."

    "${COMPOSE_LOCAL[@]}" ps
    verificar_salud

    echo
    echo "[6/6] Mostrando últimos logs..."

    "${COMPOSE_LOCAL[@]}" logs --tail=50

    echo
    echo "=============================================="
    echo " Limpieza y reconstrucción completadas"
    echo " Aplicación: http://localhost:8010"
    echo "=============================================="
}

MODO="${1:-}"
CONFIRM_CLEAN="${2:-}"

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
