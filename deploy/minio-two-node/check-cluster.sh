#!/usr/bin/env bash
set -euo pipefail

endpoint="${1:-${MINIO_ENDPOINT:-}}"
[[ -n "${endpoint}" ]] || {
    echo "Uso: $0 https://minio.interno.example" >&2
    exit 64
}
endpoint="${endpoint%/}"

curl_options=(
    --silent
    --show-error
    --output /dev/null
    --write-out "%{http_code}"
    --connect-timeout 5
    --max-time 15
    --head
)

if [[ -n "${MINIO_CA_BUNDLE:-}" ]]; then
    curl_options+=(--cacert "${MINIO_CA_BUNDLE}")
elif [[ "${MINIO_TLS_VERIFY:-true}" == "false" ]]; then
    curl_options+=(--insecure)
fi

probe() {
    local label="$1"
    local path="$2"
    local status

    status="$(curl "${curl_options[@]}" "${endpoint}${path}" || true)"
    printf '%-18s HTTP %s\n' "${label}" "${status:-000}"
    [[ "${status}" == "200" ]]
}

result=0
probe "Proceso" "/minio/health/live" || result=1
probe "Quorum lectura" "/minio/health/cluster/read" || result=1
probe "Quorum escritura" "/minio/health/cluster" || result=1

exit "${result}"
