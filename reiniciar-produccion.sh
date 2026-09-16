#!/usr/bin/env bash
set -Eeuo pipefail

# Despliegue seguro de la aplicación en un nodo de producción. No administra
# MinIO: el clúster se opera por separado con deploy/minio-two-node/.

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

ENV_FILE="$PROJECT_DIR/.env"
COMPOSE_FILES=(-f docker-compose.yml -f deploy/minio-two-node/app.compose.yml)

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose "${COMPOSE_FILES[@]}")
elif command -v sudo >/dev/null 2>&1 && sudo -n docker compose version >/dev/null 2>&1; then
    COMPOSE=(sudo docker compose "${COMPOSE_FILES[@]}")
else
    fail "Docker Compose no está disponible para el usuario actual. Ejecute el script con sudo o configure acceso a Docker."
fi

show_logs_on_error() {
    local exit_code=$?
    echo >&2
    echo "ERROR: el despliegue falló. Últimos logs:" >&2
    "${COMPOSE[@]}" logs --tail=100 php-app import-worker nginx 2>/dev/null || true
    exit "$exit_code"
}
trap show_logs_on_error ERR

require_setting() {
    local name="$1"
    local expected="$2"
    grep -Eq "^[[:space:]]*${name}=${expected}[[:space:]]*(#.*)?$" "$ENV_FILE" \
        || fail "${name} debe ser ${expected} en .env para desplegar producción."
}

validate_production_config() {
    [[ -r "$ENV_FILE" ]] || fail "No se puede leer $ENV_FILE."
    require_setting APP_ENV prod
    require_setting APP_DEBUG false
    require_setting STORAGE_DRIVER s3
    require_setting CONFIG_STORAGE database
    require_setting HISTORY_STORAGE database
    require_setting SECURITY_ALERT_STORAGE database
    require_setting SESSION_HANDLER database
    require_setting RATE_LIMIT_STORE database
    require_setting RATE_LIMIT_FAIL_OPEN false
    [[ -n "$(grep -E '^[[:space:]]*MINIO_ENDPOINT=.' "$ENV_FILE" || true)" ]] \
        || fail "MINIO_ENDPOINT debe estar definido en .env."
    [[ -n "$(grep -E '^[[:space:]]*APP_RECORD_01_IP=.' "$ENV_FILE" || true)" ]] \
        || fail "APP_RECORD_01_IP debe estar definido en .env."
    [[ -n "$(grep -E '^[[:space:]]*APP_RECORD_02_IP=.' "$ENV_FILE" || true)" ]] \
        || fail "APP_RECORD_02_IP debe estar definido en .env."
}

verify_health() {
    echo "Verificando disponibilidad de la aplicación..."
    "${COMPOSE[@]}" exec -T php-app \
        curl --fail --silent --show-error --retry 12 --retry-delay 2 \
        http://nginx/health/live >/dev/null
    "${COMPOSE[@]}" exec -T php-app \
        curl --fail --silent --show-error --retry 12 --retry-delay 2 \
        http://nginx/health/ready >/dev/null
    echo "Health checks correctos."
}

deploy() {
    echo "=============================================="
    echo " Despliegue de aplicación — producción"
    echo " Proyecto: sistema-record-academico"
    echo " Nodo: $(hostname -f 2>/dev/null || hostname)"
    echo "=============================================="

    validate_production_config
    "${COMPOSE[@]}" config --quiet

    echo "[1/6] Construyendo imagen de PHP..."
    "${COMPOSE[@]}" build php-app

    echo "[2/6] Sincronizando dependencias PHP con composer.lock..."
    "${COMPOSE[@]}" run --rm --no-deps php-app \
        composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

    echo "[3/6] Recreando PHP-FPM y Nginx..."
    "${COMPOSE[@]}" up -d --force-recreate php-app nginx

    echo "[4/6] Validando readiness antes de iniciar el worker..."
    verify_health

    echo "[5/6] Recreando worker de importaciones..."
    "${COMPOSE[@]}" up -d --force-recreate import-worker

    echo "[6/6] Estado y logs recientes..."
    "${COMPOSE[@]}" ps
    "${COMPOSE[@]}" logs --tail=50 php-app import-worker nginx

    echo "=============================================="
    echo " Despliegue completado correctamente"
    echo " MinIO no fue modificado por este script."
    echo "=============================================="
}

show_help() {
    echo "Uso: $0 deploy --confirm-production"
    echo
    echo "Despliega sólo php-app, nginx e import-worker en un nodo de producción."
    echo "No ejecuta operaciones sobre el clúster MinIO ni elimina volúmenes."
}

case "${1:-}" in
    deploy)
        [[ "${2:-}" == "--confirm-production" ]] \
            || fail "Confirme el despliegue con: $0 deploy --confirm-production"
        deploy
        ;;
    help|-h|--help|'')
        show_help
        ;;
    *)
        show_help >&2
        exit 1
        ;;
esac
