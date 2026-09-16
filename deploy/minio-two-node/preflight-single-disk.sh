#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${1:-${SCRIPT_DIR}/minio.single-disk.env}"

fail() { echo "ERROR: $*" >&2; exit 1; }
warn() { echo "ADVERTENCIA: $*" >&2; }

[[ -r "${ENV_FILE}" ]] || fail "No se puede leer ${ENV_FILE}."
set -a
# shellcheck disable=SC1090
source "${ENV_FILE}"
set +a

for name in MINIO_NODE_NAME MINIO_ROOT_USER_FILE MINIO_ROOT_PASSWORD_FILE MINIO_CERTS_DIR MINIO_DATA_1 MINIO_DATA_2; do
    [[ -n "${!name:-}" ]] || fail "Falta ${name} en ${ENV_FILE}."
done

case "${MINIO_NODE_NAME}" in
    pchquit01dweb14.fj.local|pchquit01dweb14.fj.local-02) ;;
    *) fail "MINIO_NODE_NAME debe ser pchquit01dweb14.fj.local o pchquit01dweb14.fj.local-02." ;;
esac
[[ "$(hostnamectl --static)" == "${MINIO_NODE_NAME}" ]] || fail "El hostname local no coincide con MINIO_NODE_NAME."

for cluster_host in pchquit01dweb14.fj.local pchquit01dweb14.fj.local-02; do
    getent hosts "${cluster_host}" >/dev/null || fail "${cluster_host} no resuelve."
done
for secret_file in "${MINIO_ROOT_USER_FILE}" "${MINIO_ROOT_PASSWORD_FILE}"; do
    [[ -s "${secret_file}" ]] || fail "El secreto ${secret_file} no existe o está vacío."
done
for data_dir in "${MINIO_DATA_1}" "${MINIO_DATA_2}"; do
    [[ -d "${data_dir}" ]] || fail "${data_dir} no existe o no es un directorio."
done

source_1="$(findmnt -rn -o SOURCE --target "${MINIO_DATA_1}")"
source_2="$(findmnt -rn -o SOURCE --target "${MINIO_DATA_2}")"
[[ -n "${source_1}" && "${source_1}" == "${source_2}" ]] || fail "Este perfil requiere MINIO_DATA_1 y MINIO_DATA_2 en el mismo filesystem."
warn "Los dos directorios usan ${source_1}: no existe tolerancia a una falla de ese disco."
warn "Verifique una copia de seguridad externa antes de iniciar producción."

docker compose --env-file "${ENV_FILE}" -f "${SCRIPT_DIR}/compose-single-disk.yml" config --quiet
echo "Preflight correcto para ${MINIO_NODE_NAME}; perfil de disco único confirmado."
