#!/usr/bin/env sh
# Genera un certificado auto-firmado (.p12) para probar firma PAdES en dev.
# NO usar en producción: los lectores PDF mostrarán "identidad desconocida"
# porque el certificado no está emitido por una CA de confianza.
#
# Uso:
#   sh tools/generate_dev_cert.sh                       # defaults
#   OUT_DIR=./secrets PASSWORD=xxx sh tools/generate_dev_cert.sh
set -eu

OUT_DIR="${OUT_DIR:-./secrets}"
CN="${CN:-Sistema Record Academico DEV}"
ORG="${ORG:-Institucion DEV}"
COUNTRY="${COUNTRY:-EC}"
DAYS="${DAYS:-365}"
PASSWORD="${PASSWORD:-changeit}"

mkdir -p "$OUT_DIR"

TMP_KEY="$(mktemp)"
TMP_CRT="$(mktemp)"
trap 'rm -f "$TMP_KEY" "$TMP_CRT"' EXIT

openssl req -x509 -newkey rsa:2048 \
  -keyout "$TMP_KEY" \
  -out "$TMP_CRT" \
  -days "$DAYS" \
  -nodes \
  -subj "/CN=$CN/O=$ORG/C=$COUNTRY"

openssl pkcs12 -export \
  -inkey "$TMP_KEY" \
  -in "$TMP_CRT" \
  -out "$OUT_DIR/dev.p12" \
  -name "$CN" \
  -passout "pass:$PASSWORD"

echo ""
echo "OK - Certificado generado: $OUT_DIR/dev.p12"
echo ""
echo "Añade a tu .env:"
echo "  PADES_SIGN_ENABLED=true"
echo "  PADES_CERT_PATH=$OUT_DIR/dev.p12"
echo "  PADES_CERT_PASSWORD=$PASSWORD"
echo "  PADES_SIGNER_NAME=\"Escuela de la Función Judicial\""
echo "  PADES_SIGNER_REASON=\"Certificación de expediente académico\""
echo "  PADES_SIGNER_LOCATION=\"Quito, EC\""