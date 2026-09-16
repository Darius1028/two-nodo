#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${1:-${SCRIPT_DIR}/minio.env}"

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

warn() {
    echo "ADVERTENCIA: $*" >&2
}

[[ -r "${ENV_FILE}" ]] || fail "No se puede leer ${ENV_FILE}."

set -a
# El archivo pertenece al administrador del host y debe contener sólo variables.
# shellcheck disable=SC1090
source "${ENV_FILE}"
set +a

required=(
    MINIO_NODE_NAME
    MINIO_ROOT_USER_FILE
    MINIO_ROOT_PASSWORD_FILE
    MINIO_CERTS_DIR
    MINIO_DISK_1
    MINIO_DISK_2
    MINIO_DISK_3
    MINIO_DISK_4
)

for name in "${required[@]}"; do
    [[ -n "${!name:-}" ]] || fail "Falta ${name} en ${ENV_FILE}."
done

for secret_file in "${MINIO_ROOT_USER_FILE}" "${MINIO_ROOT_PASSWORD_FILE}"; do
    [[ -r "${secret_file}" ]] || fail "No se puede leer el secreto ${secret_file}."
    [[ -s "${secret_file}" ]] || fail "El secreto ${secret_file} está vacío."
done

root_password="$(<"${MINIO_ROOT_PASSWORD_FILE}")"
[[ ${#root_password} -ge 8 ]] \
    || fail "La contraseña administrativa de MinIO debe tener al menos 8 caracteres."
unset root_password

case "${MINIO_NODE_NAME}" in
    pchquit01dweb14.fj.local|pchquit01dweb14.fj.local-02) ;;
    *) fail "MINIO_NODE_NAME debe ser pchquit01dweb14.fj.local o pchquit01dweb14.fj.local-02." ;;
esac

host_shortname="$(hostnamectl --static)"
[[ "${host_shortname}" == "${MINIO_NODE_NAME}" ]] \
    || fail "El hostname local (${host_shortname}) no coincide con MINIO_NODE_NAME (${MINIO_NODE_NAME})."

for cluster_host in pchquit01dweb14.fj.local pchquit01dweb14.fj.local-02; do
    getent hosts "${cluster_host}" >/dev/null \
        || fail "${cluster_host} no resuelve. Configure DNS interno o /etc/hosts en ambos nodos."
done

case "${MINIO_CLUSTER_SCHEME:-https}" in
    http|https) ;;
    *) fail "MINIO_CLUSTER_SCHEME debe ser http o https." ;;
esac

declare -A disk_sources=()
for number in 1 2 3 4; do
    variable="MINIO_DISK_${number}"
    disk="${!variable}"

    [[ -d "${disk}" ]] || fail "${disk} no existe o no es un directorio."
    [[ -w "${disk}" ]] \
        || warn "${disk} no permite escritura al usuario actual; verifique el acceso desde el contenedor."
    mountpoint -q "${disk}" \
        || fail "${disk} no es un punto de montaje independiente."

    source_device="$(findmnt -rn -o SOURCE --target "${disk}")"
    [[ -n "${source_device}" ]] || fail "No se pudo identificar el dispositivo de ${disk}."
    [[ -z "${disk_sources[${source_device}]:-}" ]] \
        || fail "${disk} y ${disk_sources[${source_device}]} usan ${source_device}."
    disk_sources["${source_device}"]="${disk}"

    filesystem="$(findmnt -rn -o FSTYPE --target "${disk}")"
    [[ "${filesystem}" == "xfs" ]] \
        || warn "${disk} usa ${filesystem}; MinIO recomienda XFS."
done

if [[ "${MINIO_CLUSTER_SCHEME:-https}" == "https" ]]; then
    [[ -r "${MINIO_CERTS_DIR}/public.crt" ]] \
        || fail "Falta ${MINIO_CERTS_DIR}/public.crt."
    [[ -r "${MINIO_CERTS_DIR}/private.key" ]] \
        || fail "Falta ${MINIO_CERTS_DIR}/private.key."
    [[ -d "${MINIO_CERTS_DIR}/CAs" ]] \
        || fail "Falta el directorio de autoridades ${MINIO_CERTS_DIR}/CAs."

    if command -v openssl >/dev/null 2>&1; then
        openssl x509 -in "${MINIO_CERTS_DIR}/public.crt" \
            -noout -checkhost "${MINIO_NODE_NAME}" >/dev/null \
            || fail "El certificado no contiene el SAN ${MINIO_NODE_NAME}."
    else
        warn "No se encontró 'openssl'; no se validó el SAN del certificado."
    fi
else
    warn "MinIO se iniciará sin TLS; no use este modo en producción."
fi

docker compose \
    --env-file "${ENV_FILE}" \
    -f "${SCRIPT_DIR}/compose.yml" \
    config --quiet

echo "Preflight correcto para ${MINIO_NODE_NAME}."
