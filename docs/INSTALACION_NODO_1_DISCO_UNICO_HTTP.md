# Instalación del nodo 1: un disco por servidor y MinIO compartido por HTTP

## Alcance

Esta guía utiliza el perfil `deploy/minio-two-node/compose-single-disk.yml`
del proyecto. Supone dos servidores Linux, un disco por servidor y un único
clúster MinIO compartido por HTTP en la red privada. La aplicación pública
utiliza HTTPS mediante un balanceador.

Si MinIO ya existe como servicio externo, omita los pasos 3 a 6 y utilice su
endpoint en la configuración de la aplicación. Coordine con su administrador
la creación de buckets y credenciales del paso 9.

El perfil crea dos directorios en el mismo disco de cada servidor. Es una
configuración de contingencia, no una garantía de alta disponibilidad. El
preflight valida la configuración, pero no certifica que esta disposición sea
aceptada por MinIO en su entorno. Valide el arranque real antes de continuar y
mantenga respaldos externos recuperables. Si un nodo cae, las escrituras pueden
quedar bloqueadas por falta de quorum.

## 1. Requisitos y ubicación del proyecto

Requisitos previos:

- Docker Engine y Docker Compose v2 instalados.
- Acceso `sudo` y herramientas `curl`, `getent` y `findmnt`.
- Mismo código de la aplicación en ambos servidores.
- SQL Server compartido con el esquema base de la aplicación instalado.
- Acceso a Keycloak, base externa de roles y Repositorio Documental.
- Balanceador con HTTPS público y relojes de los servidores sincronizados.

Verifique Docker:

```bash
docker --version
docker compose version
```

Ejecute los comandos siguientes desde la raíz del proyecto, reemplazando la
ruta por la ubicación real:

```bash
cd /ruta/al/proyecto
```

Esta instalación usa las siguientes direcciones, que están en subredes
distintas y deben tener enrutamiento entre sí:

| Nodo | Hostname | IP |
| --- | --- | --- |
| 1 | `pchquit01dweb14.fj.local` | `10.1.13.51` |
| 2 | `pchquit01dweb14.fj.local-02` | `10.11.244.157` |

Reemplace todos los valores entre `<...>` antes de ejecutar comandos o iniciar
servicios. Esta guía supone una instalación nueva; no sobrescriba archivos
`.env` ni reutilice directorios de datos de una instalación existente.

## 2. Hostname y conectividad

Configure el nodo 1:

```bash
sudo hostnamectl set-hostname pchquit01dweb14.fj.local
sudo nano /etc/hosts
```

Asegure que DNS interno resuelva ambos nombres en los dos servidores. Como
respaldo, añada estas entradas en `/etc/hosts` de **ambos** nodos:

```text
10.1.13.51    pchquit01dweb14.fj.local
10.11.244.157 pchquit01dweb14.fj.local-02
```

Compruebe la resolución y la conectividad bidireccional. Ejecute las pruebas
remotas desde cada nodo (no confunda el ping al propio hostname con una prueba
entre nodos):

```bash
getent hosts pchquit01dweb14.fj.local
getent hosts pchquit01dweb14.fj.local-02

# Nodo 1
ping -c 3 pchquit01dweb14.fj.local-02

# Nodo 2
ping -c 3 pchquit01dweb14.fj.local
```

Configure el firewall de acuerdo con esta conectividad:

| Puerto | Acceso necesario |
| --- | --- |
| TCP 9000 | Entre ambos nodos y desde clientes internos autorizados de MinIO |
| TCP 8010 | Desde el balanceador hacia la aplicación |
| TCP 1433 o el configurado | Desde la aplicación hacia SQL Server |
| Puertos de los servicios externos | Desde la aplicación hacia Keycloak, roles y Repositorio Documental |

El puerto TCP `9000` es imprescindible en los dos sentidos; ICMP/ping correcto
no demuestra que esté permitido. En el nodo 2 se usa UFW con política de
entrada `deny`, por lo que agregue una regla restringida al nodo 1:

```bash
# Nodo 2: permitir MinIO desde el nodo 1, sin exponerlo a otras redes.
sudo ufw allow proto tcp from 10.1.13.51 to any port 9000
sudo ufw status numbered
```

Si el nodo 1 también tiene firewall de entrada restrictivo, permita de forma
equivalente TCP `9000` desde `10.11.244.157`. Cuando MinIO esté arriba en los
dos nodos, compruebe el puerto desde ambos sentidos:

```bash
# Nodo 1
nc -vz pchquit01dweb14.fj.local-02 9000

# Nodo 2
nc -vz pchquit01dweb14.fj.local 9000
```

Ambos comandos deben indicar `succeeded` o `Connected`. Un `TIMEOUT`,
`no route to host` en los logs de MinIO o un ping exitoso con TCP fallido
indican una regla de firewall o ACL de red pendiente.

MinIO por HTTP no cifra los datos en tránsito. Este procedimiento supone una
red privada controlada. La consola se mantiene en `127.0.0.1:9001`.

## 3. Directorios persistentes de MinIO

```bash
sudo mkdir -p /var/lib/academic-minio/data1
sudo mkdir -p /var/lib/academic-minio/data2
sudo mkdir -p /etc/academic-minio/certs
sudo install -d -m 700 /etc/academic-minio/secrets
```

Los dos directorios de datos deben estar vacíos antes del primer inicio y
pertenecer al mismo filesystem. Si el disco de datos está montado en otra ruta,
cree allí los directorios y adapte `MINIO_DATA_1` y `MINIO_DATA_2`.

El Compose exige el directorio `certs` aunque se use HTTP. Déjelo vacío en este
despliegue. No convierta un clúster existente de ocho discos a este perfil
reutilizando directamente sus datos.

## 4. Credenciales administrativas de MinIO

```bash
sudo nano /etc/academic-minio/secrets/root-user
sudo nano /etc/academic-minio/secrets/root-password
```

Escriba únicamente el usuario en el primer archivo y una contraseña robusta
en el segundo, sin nombres de variables. Luego proteja los archivos:

```bash
sudo chmod 600 /etc/academic-minio/secrets/root-user
sudo chmod 600 /etc/academic-minio/secrets/root-password
```

El nodo 2 debe utilizar exactamente las mismas credenciales administrativas.
No las guarde en Git. La aplicación utilizará una cuenta de runtime distinta.

## 5. Configuración de MinIO para disco único y HTTP

Si todavía no existe el archivo de configuración local:

```bash
cp deploy/minio-two-node/minio.single-disk.env.example \
   deploy/minio-two-node/minio.single-disk.env
```

Edítelo:

```bash
nano deploy/minio-two-node/minio.single-disk.env
```

Contenido para el nodo 1:

```dotenv
MINIO_NODE_NAME=pchquit01dweb14.fj.local
MINIO_CLUSTER_SCHEME=http
MINIO_CERTS_DIR=/etc/academic-minio/certs
MINIO_CONSOLE_ADDRESS=127.0.0.1:9001
MINIO_ROOT_USER_FILE=/etc/academic-minio/secrets/root-user
MINIO_ROOT_PASSWORD_FILE=/etc/academic-minio/secrets/root-password
MINIO_DATA_1=/var/lib/academic-minio/data1
MINIO_DATA_2=/var/lib/academic-minio/data2
```

Valide con permisos para leer los secretos:

```bash
sudo bash deploy/minio-two-node/preflight-single-disk.sh \
  deploy/minio-two-node/minio.single-disk.env
```

## 6. Arranque del clúster MinIO

```bash
sudo docker compose \
  --env-file deploy/minio-two-node/minio.single-disk.env \
  -f deploy/minio-two-node/compose-single-disk.yml \
  up -d
```

**Dependencia del nodo 2:** prepare e inicie MinIO en el segundo servidor
durante la misma ventana. Use el hostname `pchquit01dweb14.fj.local-02`, las mismas entradas
de resolución y credenciales, y cambie en su archivo de configuración:

```dotenv
MINIO_NODE_NAME=pchquit01dweb14.fj.local-02
```

El nodo 1 solo no reúne quorum para inicializar el clúster. Revise estado y logs:

```bash
sudo docker compose \
  --env-file deploy/minio-two-node/minio.single-disk.env \
  -f deploy/minio-two-node/compose-single-disk.yml ps

sudo docker compose \
  --env-file deploy/minio-two-node/minio.single-disk.env \
  -f deploy/minio-two-node/compose-single-disk.yml logs --tail=100 minio
```

Con ambos nodos iniciados:

```bash
# En cada nodo: comprueba que su proceso responde.
curl -i --max-time 5 http://127.0.0.1:9000/minio/health/live

# En cualquiera de los nodos: comprueba que el clúster puede leer.
curl -i --max-time 5 http://127.0.0.1:9000/minio/health/cluster/read

# Comprueba los dos endpoints por nombre.
curl -i --max-time 5 http://pchquit01dweb14.fj.local:9000/minio/health/cluster/read
curl -i --max-time 5 http://pchquit01dweb14.fj.local-02:9000/minio/health/cluster/read
```

Espere `HTTP/1.1 200 OK` en todas las pruebas. Después revise los logs recientes
en ambos nodos:

```bash
sudo docker logs --since 2m academic_minio_distributed
```

No deben persistir `i/o timeout`, `no route to host`, `drive not found` ni
`Waiting for a minimum of 2 drives`. Durante la primera formación pueden
aparecer mensajes transitorios sobre `pool.bin` o `rebalance.bin`; sólo son
aceptables si el arranque posterior informa `All MinIO sub-systems initialized
successfully` y las comprobaciones HTTP devuelven 200. Si MinIO rechaza los
directorios por compartir disco o no obtiene quorum, no continúe con
producción: revise los logs y la topología. Un preflight exitoso no sustituye
esta comprobación.

## 7. Preparar la base de datos compartida

Haga respaldo antes de modificar una base existente. Si está actualizando una
instalación, pause nuevas importaciones y deje vaciar la cola antes de cambiar
los workers.

Los manuales mencionan `database/create_schema.sql`, pero ese archivo no está
presente en esta copia del repositorio. Para una base vacía, obtenga y aplique
primero el esquema base autorizado. La migración siguiente exige que exista
`Academico.ImportJob` y no reemplaza el esquema base.

Desde una estación con `sqlcmd`, aplique una sola vez en la base compartida:

```bash
sqlcmd \
  -S '<SERVIDOR_SQL>,1433' \
  -d '<BASE_PRINCIPAL>' \
  -U '<USUARIO_MIGRACION>' \
  -i scripts/20260914_minio_multinode.sql \
  -b
```

No inicie el worker nuevo antes de que la migración termine correctamente.

## 8. Configurar la aplicación del nodo 1

Si todavía no existe `.env`:

```bash
cp .env.example .env
chmod 600 .env
```

```bash
nano .env
```

Complete todas las variables existentes de SQL Server, Keycloak, base externa
de roles, QR y Repositorio Documental con los valores de producción. No deje
los endpoints de desarrollo del ejemplo.

Configure almacenamiento y estado compartido:

```dotenv
APP_ENV=prod
APP_DEBUG=false

DB_HOST=<SERVIDOR_SQL>
DB_PORT=1433
DB_NAME=<BASE_PRINCIPAL>
DB_USER=<USUARIO_APP>
DB_PASS='<CONTRASEÑA>'
DB_ENCRYPT=true

APP_RECORD_01_IP=10.1.13.51
APP_RECORD_02_IP=10.11.244.157

STORAGE_DRIVER=s3
MINIO_ENDPOINT=http://pchquit01dweb14.fj.local:9000
MINIO_REGION=us-east-1
MINIO_IMPORT_BUCKET=record-academico-imports
MINIO_ASSET_BUCKET=record-academico-assets
MINIO_ACCESS_KEY=academic_app
MINIO_SECRET_KEY='una-clave-local-segura'
MINIO_PATH_STYLE=true
MINIO_TLS_VERIFY=false
MINIO_IMPORT_EXPIRATION_DAYS=7
IMPORT_WORK_DIR=/var/www/html/var/import-work

CONFIG_STORAGE=database
HISTORY_STORAGE=database
SECURITY_ALERT_STORAGE=database
SESSION_HANDLER=database
SESSION_NAME=ACADEMICSESSID
SESSION_TTL=3600
RATE_LIMIT_STORE=database
RATE_LIMIT_FAIL_OPEN=false

TRUSTED_PROXIES=<IP_O_CIDR_DEL_BALANCEADOR>
KEYCLOAK_REDIRECT_URI=https://<DOMINIO_PUBLICO>/callback.php
```

`MINIO_ACCESS_KEY=academic_app` es la cuenta de runtime que usará PHP y el
worker; no es la cuenta root definida en
`/etc/academic-minio/secrets/root-user`. Cree esta cuenta y asígnele permisos
limitados a los buckets de imports y assets. `una-clave-local-segura` es un
valor inicial para esta instalación: reemplácelo por un secreto único antes de
exponer el entorno fuera de una red controlada.

`http://` selecciona el protocolo. `MINIO_TLS_VERIFY=false` por sí solo no
convierte una conexión HTTPS en HTTP. No use `http://minio:9000`, que corresponde
al servicio local de desarrollo.

Si utiliza MinIO externo, sustituya `MINIO_ENDPOINT` por su URL S3 real. Si usa
un VIP de MinIO, ambos nodos pueden apuntar a ese mismo endpoint. Los buckets y
las credenciales de runtime deben ser los mismos en ambos nodos.

## 9. Construir e inicializar almacenamiento

Defina este atajo en la terminal actual, desde la raíz del proyecto:

```bash
dc() {
  sudo docker compose \
    -f docker-compose.yml \
    -f deploy/minio-two-node/app.compose.yml \
    "$@"
}
```

El override agrega la resolución de ambos hostnames dentro de PHP y del worker.
Si abre otra terminal, vuelva a definir la función.

Construya la imagen:

```bash
dc build php-app
```

Inicialice los buckets una sola vez con credenciales de aprovisionamiento.
El siguiente comando solicita temporalmente las credenciales root definidas en
`root-user` y `root-password`, sin escribirlas en `.env`. Sólo durante este
comando sustituyen a `academic_app` y su secreto; al terminar, `.env` continúa
siendo la fuente de credenciales runtime para PHP y el worker:

```bash
read -r -p "Usuario de aprovisionamiento MinIO: " MINIO_ACCESS_KEY
read -r -s -p "Secreto de aprovisionamiento MinIO: " MINIO_SECRET_KEY
echo
export MINIO_ACCESS_KEY MINIO_SECRET_KEY

sudo --preserve-env=MINIO_ACCESS_KEY,MINIO_SECRET_KEY docker compose \
  -f docker-compose.yml \
  -f deploy/minio-two-node/app.compose.yml \
  run --rm --no-deps \
  -e MINIO_ACCESS_KEY \
  -e MINIO_SECRET_KEY \
  php-app php bin/storage-init.php

unset MINIO_ACCESS_KEY MINIO_SECRET_KEY
```

La política local de `sudo` debe permitir preservar estas dos variables. Si no
lo permite, el administrador debe ejecutar el aprovisionamiento mediante el
mecanismo de secretos autorizado.

El script crea los buckets, habilita versionado de assets y configura la
expiración de imports. No crea usuarios ni políticas de MinIO.

Antes de continuar, el administrador de MinIO debe crear la cuenta de runtime
indicada en `.env`, con permisos para comprobar/listar los dos buckets y leer,
crear y eliminar sus objetos. Esa cuenta no debe administrar usuarios,
políticas ni lifecycle. No deje credenciales administrativas en `.env`.

## 10. Arrancar la aplicación y el worker

```bash
dc up -d php-app nginx
dc ps

curl -fsS http://127.0.0.1:8010/health/live
curl -fsS http://127.0.0.1:8010/health/ready
```

Ambos endpoints deben responder HTTP `200`. El primero comprueba Nginx; el
segundo revisa las dependencias de la aplicación. Si falla readiness, consulte
los logs antes de incorporar el nodo al balanceador.

Con las comprobaciones correctas, arranque el worker:

```bash
dc up -d import-worker
dc logs --tail=100 php-app import-worker nginx
```

No active el perfil `local-infra` en este despliegue. El Dockerfile instala las
dependencias PHP de producción durante la construcción. Si reutiliza un volumen
`vendor_data` de una versión anterior, debe actualizar sus dependencias según
`composer.lock` antes de validar el despliegue.

## 11. Balanceador y pruebas finales

Configure como backend del nodo 1:

```text
http://IP_NODO_1:8010
```

Use `/health/ready` como monitor de preparación y preserve la cabecera `Host`.
El dominio público debe servir HTTPS y coincidir con el callback registrado en
Keycloak.

Antes de abrir tráfico, ajuste en `docker/nginx.conf` los rangos
`set_real_ip_from` y las redes administrativas a su infraestructura. La
configuración incluida contiene rangos privados amplios. Si edita el archivo
con Nginx ya iniciado, valide y recargue:

```bash
dc exec nginx nginx -t
dc exec nginx nginx -s reload
```

Verifique:

1. Inicio de sesión por el dominio público.
2. Carga y procesamiento de un CSV.
3. Carga de membrete/firma y generación de PDF.
4. Ausencia de errores persistentes en PHP, worker y MinIO.
5. Respaldos externos y supervisión del espacio del disco.

Cuando la aplicación del nodo 2 esté desplegada, compruebe continuidad de sesión
al alternar nodos y procesamiento desde un nodo de archivos cargados por el
otro. En ese servidor puede usar `MINIO_ENDPOINT=http://pchquit01dweb14.fj.local-02:9000`:
ambos endpoints deben pertenecer al mismo clúster.

## Referencias del proyecto

- [Manual de producción](MANUAL_INSTALACION_PRODUCCION.md).
- [Perfil de disco único](../deploy/minio-two-node/SINGLE_DISK.md).
- [Almacenamiento multinodo](ALMACENAMIENTO_MULTINODO.md).

Esta guía adapta el perfil de disco único a HTTP privado. Los otros manuales
incluyen ejemplos de TLS y de cuatro discos por servidor; no mezcle esos
perfiles con los comandos de esta guía.
