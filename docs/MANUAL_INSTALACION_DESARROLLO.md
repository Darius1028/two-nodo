# Manual de instalación — Desarrollo

## 1. Propósito

Esta guía prepara una instancia local de un solo nodo para desarrollar y probar
el Sistema de Registro Académico. Usa Docker Compose para Nginx, PHP-FPM y el
worker de importaciones. SQL Server, Keycloak y el Repositorio Documental
pueden ser servicios de desarrollo ya disponibles en la red institucional.

> No use esta configuración para atender usuarios finales ni como nodo de un
> despliegue multinodo.

## 2. Requisitos

- Git.
- Docker Engine y el complemento Docker Compose v2.
- Acceso de red a un SQL Server de desarrollo con permisos para la base de la
  aplicación.
- Un cliente OIDC de desarrollo en Keycloak, con una URI de redirección local.
- Acceso a la base externa de roles o usuarios de prueba ya asignados a los
  roles `ADMIN_ACADEMICO` y/o `SECRE_ACADEMICO`.

Compruebe Docker antes de continuar:

```bash
docker --version
docker compose version
```

## 3. Obtener y configurar el proyecto

```bash
git clone <URL_DEL_REPOSITORIO> sistema-record-academico
cd sistema-record-academico
cp .env.example .env
```

Edite `.env`; nunca lo agregue al repositorio. Como mínimo configure:

```dotenv
APP_ENV=dev
APP_DEBUG=true

DB_HOST=<sql-server-desarrollo>
DB_PORT=1433
DB_INSTANCE=
DB_NAME=record_academico_db
DB_USER=<usuario>
DB_PASS=<secreto>
DB_ENCRYPT=false

KEYCLOAK_SERVER_URL=https://<keycloak-desarrollo>
KEYCLOAK_REALM=<realm>
KEYCLOAK_CLIENT_ID=<cliente>
KEYCLOAK_CLIENT_SECRET=<secreto>
KEYCLOAK_REDIRECT_URI=http://localhost:8010/callback.php
KEYCLOAK_CEDULA_CLAIM=cedula
KEYCLOAK_ROLE_ADMIN=ADMIN_ACADEMICO
KEYCLOAK_ROLE_USER=SECRE_ACADEMICO

EXTERNAL_ROLES_DB_HOST=<sql-server-roles>
EXTERNAL_ROLES_DB_PORT=1433
EXTERNAL_ROLES_DB_NAME=PORTAL_APLICATIVOS_CJ
EXTERNAL_ROLES_DB_USER=<usuario>
EXTERNAL_ROLES_DB_PASS=<secreto>
EXTERNAL_ROLES_APP_ALIAS=SEC-ACAD

HISTORY_STORAGE=file
CONFIG_STORAGE=file
SECURITY_ALERT_STORAGE=file
SESSION_HANDLER=file
RATE_LIMIT_STORE=file
STORAGE_DRIVER=local
```

Si Keycloak está fuera de tu equipo, debe permitir exactamente la URI indicada
en `KEYCLOAK_REDIRECT_URI`. Si trabajas detrás de un proxy, agrega sólo las IP
o CIDR de confianza a `TRUSTED_PROXIES`; no uses un valor abierto.

## 4. Base de datos de desarrollo

En una base vacía, aplique el esquema base con el cliente SQL autorizado por
tu entorno. El script es idempotente:

```bash
sqlcmd -S <servidor> -d record_academico_db -U <usuario> \
  -i database/create_schema.sql -b
```

No apuntes la instancia local a una base productiva. La aplicación puede crear,
modificar, importar y marcar registros como eliminados.

## 5. Arrancar la aplicación

```bash
docker compose up -d --build
docker compose ps
```

La aplicación queda disponible en `http://localhost:8010`. Revisa el estado y
los logs si algún servicio no inicia:

```bash
docker compose logs -f php-app
docker compose logs -f import-worker
docker compose logs -f nginx
```

Comprueba disponibilidad básica:

```bash
curl -i http://localhost:8010/health/live
curl -i http://localhost:8010/health/ready
```

`/health/live` confirma que Nginx responde. `/health/ready` además valida los
servicios requeridos por la configuración seleccionada.

## 6. Opcional: probar MinIO local

Usa esta opción para probar la ruta S3 sin depender de infraestructura externa.
Actualiza temporalmente `.env`:

```dotenv
STORAGE_DRIVER=s3
MINIO_ENDPOINT=http://minio:9000
MINIO_TLS_VERIFY=false
MINIO_ACCESS_KEY=<clave-local>
MINIO_SECRET_KEY=<secreto-local-de-al-menos-8-caracteres>
MINIO_IMPORT_BUCKET=record-academico-imports
MINIO_ASSET_BUCKET=record-academico-assets
```

Después inicia MinIO e inicializa los buckets:

```bash
docker compose --profile local-infra up -d --build minio
docker compose --profile local-infra run --rm storage-init
docker compose --profile local-infra up -d php-app import-worker nginx
```

La consola de MinIO queda ligada sólo a `127.0.0.1:9001`.

## 7. Verificación funcional

1. Abre `http://localhost:8010` e inicia sesión con un usuario de pruebas.
2. Confirma que un administrador llega a `AdminPanel.php` y un secretario a
   `workspace.php`.
3. Desde el panel, consulta la pestaña **Bitácora** y realiza una operación de
   prueba.
4. Importa un CSV de prueba y revisa los logs de `import-worker`.
5. Genera un PDF con datos no sensibles. Si está configurado el Repositorio
   Documental de pruebas, comprueba también su archivado.

## 8. Comandos habituales

```bash
# Abrir una terminal en PHP-FPM
docker compose exec php-app bash

# Instalar dependencias dentro del contenedor
docker compose exec php-app composer install

# Reiniciar la pila
bash reiniciar.sh

# Detener los contenedores sin borrar datos
docker compose down

# Borrar sólo la infraestructura local efímera de desarrollo
docker compose --profile local-infra down -v
```

El último comando elimina los volúmenes Docker del perfil local, incluido el
MinIO de pruebas. No lo ejecute contra infraestructura productiva.

## 9. Antes de compartir cambios

- Retira cualquier `var_dump()` / `die` de depuración, especialmente de
  `SecurityContext::hasRole()`.
- No publiques `.env`, certificados `.p12/.pfx`, secretos ni volcados SQL.
- Mantén `APP_DEBUG=true` sólo en desarrollo.
- Ejecuta las pruebas que correspondan al cambio antes de abrir una revisión.
