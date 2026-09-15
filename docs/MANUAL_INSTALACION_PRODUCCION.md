# Manual de instalación — Producción multinodo

## 1. Alcance y arquitectura

Esta guía despliega la aplicación en dos nodos de producción, detrás de un
balanceador, con SQL Server y un clúster MinIO distribuido compartidos.

```text
Usuarios → Balanceador → nodo 1: Nginx + PHP + worker + MinIO
                       → nodo 2: Nginx + PHP + worker + MinIO
                                  ↓
                         SQL Server compartido
```

Ambos nodos deben ejecutar la misma versión de la aplicación y acceder a la
misma base, buckets y credenciales de runtime. El hostname sirve para
trazabilidad: el valor de `hostname` se registra como `nodo` en
`Academico.HistorialAplicacion`.

> El detalle operativo de MinIO, quorum y recuperación está en
> [ALMACENAMIENTO_MULTINODO.md](ALMACENAMIENTO_MULTINODO.md). Esta guía lo
> resume como procedimiento de instalación.

## 2. Requisitos previos

- Dos servidores Linux con Docker Engine y Docker Compose v2.
- Hostnames distintos y estables; por ejemplo `app-record-01` y
  `app-record-02`.
- Un balanceador/VIP para la aplicación y, preferentemente, otro endpoint VIP
  para S3/MinIO.
- SQL Server compartido, con TLS y respaldos operativos.
- Acceso desde ambos nodos a Keycloak, la base externa de roles y el
  Repositorio Documental.
- Cuatro discos o montajes independientes por servidor para MinIO, por ejemplo
  `/mnt/minio/disk1` a `/mnt/minio/disk4`; no use cuatro directorios en el
  mismo disco.
- NTP activo en ambos hosts, firewall que permita TCP 9000 entre nodos y los
  puertos HTTPS requeridos por los servicios externos.
- Certificados TLS válidos para el dominio público de la app y para cada nodo
  MinIO.

Asigne el hostname una vez por host, si aún no está configurado:

```bash
su -
hostnamectl set-hostname "app-record-01" && exec bash
```

En el segundo host ejecute el mismo comando usando `app-record-02`. Después,
en **ambos** servidores, configure `/etc/hosts` con las IP reales:

```text
10.1.13.81  app-record-01
10.1.13.82  app-record-02
```

Reemplace esas IP por las correspondientes a los nodos del clúster y confirme:

```bash
getent hosts app-record-01
getent hosts app-record-02
```

Como Docker no copia automáticamente el `/etc/hosts` del servidor dentro de
los contenedores, si no existe DNS interno agregue las mismas direcciones al
`.env` de cada nodo:

```dotenv
APP_RECORD_01_IP=10.1.13.81
APP_RECORD_02_IP=10.1.13.82
```

## 3. Preparación segura

1. Cree usuarios y credenciales separados para SQL Server, MinIO runtime y
   MinIO aprovisionamiento. La cuenta runtime no debe administrar usuarios,
   políticas ni lifecycle de buckets.
2. Guarde secretos fuera del repositorio, por ejemplo como secretos Docker o
   archivos protegidos con permisos `0600`.
3. Configure TLS para SQL Server, Keycloak, Repositorio Documental y MinIO.
4. Asegure que el balanceador preserve la cabecera `Host`; configure
   `TRUSTED_PROXIES` exclusivamente con sus IP/CIDR.
5. Haga respaldo de SQL Server, `config/config.json` y de los assets actuales
   antes de aplicar cambios de esquema.

## 4. Desplegar MinIO distribuido

En cada nodo, copie y complete el archivo local, que no debe versionarse:

```bash
cp deploy/minio-two-node/minio.env.example deploy/minio-two-node/minio.env
```

Los dos archivos comparten IPs, discos, certificados y secretos; sólo cambia
`MINIO_NODE_NAME` (`app-record-01` en el primer host y `app-record-02` en el
segundo).
Ejecute el preflight en cada servidor:

```bash
deploy/minio-two-node/preflight.sh deploy/minio-two-node/minio.env
```

Cuando ambos preflight finalicen correctamente, arranque el clúster en la misma
ventana de despliegue en los dos hosts:

```bash
docker compose \
  --env-file deploy/minio-two-node/minio.env \
  -f deploy/minio-two-node/compose.yml up -d
```

No utilice el perfil `local-infra` de `docker-compose.yml` en producción: crea
un MinIO local de un solo nodo y no comparte objetos.

## 5. Preparar base de datos y configuración

Primero asegure el esquema base de la aplicación. Luego aplique una sola vez
la migración aditiva de multinodo desde una estación autorizada:

```bash
sqlcmd -S <servidor-sql> -d <base-principal> -U <usuario> \
  -i scripts/20260914_minio_multinode.sql -b
```

Antes de desplegar el worker nuevo, pause importaciones y vacíe la cola:

```sql
SELECT estado, COUNT(*) AS cantidad
FROM Academico.ImportJob
WHERE estado IN ('PENDIENTE', 'PROCESANDO')
GROUP BY estado;
```

No despliegues el worker actualizado antes de la migración; necesita las nuevas
columnas de `Academico.ImportJob`.

## 6. Configurar `.env` por nodo

En cada nodo despliegue el mismo código y cree un `.env` protegido. Los valores
siguientes deben ser iguales en ambos servidores, excepto que
`MINIO_ENDPOINT` puede apuntar al MinIO local:

```dotenv
APP_ENV=prod
APP_DEBUG=false

DB_HOST=<sql-compartido>
DB_PORT=1433
DB_NAME=<base-principal>
DB_USER=<usuario-runtime>
DB_PASS=<secreto>
DB_ENCRYPT=true

KEYCLOAK_SERVER_URL=https://<keycloak>
KEYCLOAK_REALM=<realm>
KEYCLOAK_CLIENT_ID=<cliente>
KEYCLOAK_CLIENT_SECRET=<secreto>
KEYCLOAK_REDIRECT_URI=https://<dominio-publico>/callback.php
KEYCLOAK_CEDULA_CLAIM=cedula
KEYCLOAK_ROLE_ADMIN=ADMIN_ACADEMICO
KEYCLOAK_ROLE_USER=SECRE_ACADEMICO

EXTERNAL_ROLES_DB_HOST=<sql-roles>
EXTERNAL_ROLES_DB_PORT=1433
EXTERNAL_ROLES_DB_NAME=PORTAL_APLICATIVOS_CJ
EXTERNAL_ROLES_DB_USER=<usuario>
EXTERNAL_ROLES_DB_PASS=<secreto>
EXTERNAL_ROLES_APP_ALIAS=SEC-ACAD

STORAGE_DRIVER=s3
MINIO_ENDPOINT=https://<vip-minio-o-miembro-local>
MINIO_IMPORT_BUCKET=record-academico-imports
MINIO_ASSET_BUCKET=record-academico-assets
MINIO_ACCESS_KEY=<clave-runtime>
MINIO_SECRET_KEY=<secreto-runtime>
MINIO_TLS_VERIFY=true
MINIO_CA_BUNDLE=<ruta-a-ca-si-corresponde>

CONFIG_STORAGE=database
HISTORY_STORAGE=database
SECURITY_ALERT_STORAGE=database
SESSION_HANDLER=database
SESSION_NAME=ACADEMICSESSID
RATE_LIMIT_STORE=database
RATE_LIMIT_FAIL_OPEN=false
TRUSTED_PROXIES=<ip-o-cidr-del-balanceador>
```

La clave de firma PAdES, si se habilita, debe estar fuera de `public/`, con
permisos restrictivos y configurada mediante `PADES_CERT_PATH` y
`PADES_CERT_PASSWORD`. No incorpores certificados ni secretos en la imagen.

## 7. Construir y arrancar la aplicación

En el nodo 1, construya la imagen y arranque los servicios:

```bash
docker compose up -d --build php-app import-worker nginx
docker compose ps
```

Si configuraste `APP_RECORD_01_IP` / `APP_RECORD_02_IP`, agrega el override
de resolución al mismo comando:

```bash
docker compose \
  -f docker-compose.yml \
  -f deploy/minio-two-node/app.compose.yml \
  up -d --build php-app import-worker nginx
```

Valide primero el nodo 1:

```bash
curl -fsS https://<nodo-1>/health/live
curl -fsS https://<nodo-1>/health/ready
```

Cuando ready responda correctamente, repita el mismo despliegue en el nodo 2.

Inicialice los buckets una sola vez con credenciales de aprovisionamiento:

```bash
docker compose exec php-app php bin/storage-init.php
```

Luego vuelva a usar la credencial de runtime restringida en `.env` y reinicie
los servicios afectados.

## 8. Verificación antes de abrir tráfico

1. Confirme `200` para `/health/live` y `/health/ready` en ambos nodos.
2. Compruebe inicio de sesión por el balanceador y continuidad de sesión al
   alternar de nodo.
3. Cargue un CSV por un nodo y confirme que el worker del otro puede procesarlo.
4. Cargue membrete/firma por un nodo y genere un PDF desde el otro.
5. Revise la pestaña **Bitácora**: las filas de `HistorialAplicacion` deben
   mostrar el hostname del nodo que ejecutó la acción.
6. Confirme que el bucket de imports elimina el objeto al terminar el job y que
   `storageBucket`, `objectKey`, `sha256` y `tamanoBytes` están registrados en
   `Academico.ImportJob`.
7. Active los checks del balanceador: `/health/live` para disponibilidad de
   Nginx y `/health/ready` para preparación integral.

## 9. Operación y reversión

- Centralice logs de Nginx, PHP y worker; `var/` es local y no es un respaldo.
- Supervise el espacio de los discos MinIO, SQL Server, el estado de la cola y
  las alertas de seguridad.
- En una caída de un host MinIO de la topología de dos servidores hay lectura,
  pero no escritura: no fuerce cargas, eliminaciones o cambios hasta recuperar
  el quorum.
- Para un rollback de código, detenga los workers nuevos antes de ejecutar un
  worker anterior. Un worker antiguo no reconoce una ruta `s3://`.
- La migración SQL es aditiva. No elimine columnas o tablas durante un rollback
  sin una ventana de cambio, respaldo verificado y procedimiento aprobado.

Consulta el runbook [ALMACENAMIENTO_MULTINODO.md](ALMACENAMIENTO_MULTINODO.md)
para el procedimiento detallado de quorum, pruebas cruzadas y recuperación.
