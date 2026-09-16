# Almacenamiento compartido para dos nodos

## Objetivo

Los dos nodos de la aplicación deben apuntar al mismo SQL Server, al mismo
clúster S3/MinIO y al mismo SQL Server. Debido a la restricción de infraestructura,
el clúster MinIO se aloja en los mismos dos servidores de la aplicación. Los
datos se conservan en discos dedicados del host, nunca en la capa escribible
del contenedor.

```text
                  Balanceador de la aplicación
                              │
                ┌─────────────┴─────────────┐
                │                           │
       ┌────────▼─────────┐       ┌─────────▼────────┐
       │ Servidor 1       │       │ Servidor 2       │
       │ app + worker     │       │ app + worker     │
       │ MinIO pchquit01dweb14.fj.local │◄──►│ MinIO pchquit01dweb14.fj.local-02 │
       │ discos 1..4      │       │ discos 1..4      │
       └────────┬─────────┘       └─────────┬────────┘
                └─────────────┬─────────────┘
                              │
                         SQL Server
```

No se levantan dos MinIO independientes. Ambos procesos arrancan con la misma
lista de ocho endpoints y forman un único *server pool* distribuido. Cualquier
nodo MinIO presenta los mismos buckets y objetos. La aplicación puede consumir
un endpoint balanceado común o el MinIO local de cada servidor; en ambos casos
debe tratarse del mismo clúster y usar las mismas credenciales y buckets.

El perfil `local-infra` de `docker-compose.yml` es exclusivamente para
desarrollo o pruebas en una máquina. El despliegue productivo está en
`deploy/minio-two-node/compose.yml` y se ejecuta una vez en cada servidor.

## Qué se comparte

| Estado | Destino | Comportamiento |
|---|---|---|
| CSV pendientes | Bucket `MINIO_IMPORT_BUCKET` | Se suben antes de crear el job; se verifica tamaño y SHA-256 al descargar; se eliminan al terminar y tienen lifecycle de respaldo. |
| Membrete y firma | Bucket `MINIO_ASSET_BUCKET` | Objetos inmutables por SHA-256, versionado habilitado y descriptor activo guardado en SQL. Cada nodo mantiene una caché local verificable. |
| Configuración editable | SQL `Academico.ConfiguracionAplicacion` | QR, esquema de columnas y descriptores de assets son iguales en ambos nodos. |
| Cola de importación | SQL `Academico.ImportJob` | Reclamo atómico para impedir que dos workers procesen el mismo trabajo. |
| Historial y alertas | SQL | Sustituye los JSON locales cuando se activan los modos `database`. |
| Sesiones y rate limit | SQL Server | Una sesión y un contador son válidos sin importar qué nodo atienda la siguiente petición. |
| PDF definitivo | Repositorio Documental institucional | No cambia: el proyecto ya lo almacena fuera del contenedor. |

`var/import-work`, `var/cache/assets`, `var/cache/qr` y los temporales de PDF
pueden seguir siendo locales: perderlos sólo obliga a descargar o regenerar el
contenido. Los logs de contenedor deben recolectarse con la plataforma de
observabilidad; no deben considerarse almacenamiento persistente compartido.

## Flujo de importación

1. El nodo que recibe el CSV lo valida y lo sube al bucket de imports.
2. Sólo si el `PUT` termina, inserta el job y sus metadatos S3 en SQL. Si el
   `INSERT` falla, intenta retirar el objeto para evitar huérfanos.
3. Cualquier worker reclama un job mediante una única sentencia SQL con
   `UPDLOCK`, `READPAST` y `UPDATE ... OUTPUT`.
4. El worker adquiere `sp_getapplock` para serializar las importaciones. Esto
   es necesario porque el importador deshabilita y reconstruye índices
   globales de `Academico.RecordAcademico`.
5. Descarga a un temporal local, comprueba tamaño y SHA-256 e importa dentro
   de la transacción existente.
6. En éxito o error terminal retira el objeto. Los errores transitorios S3 se
   reintentan con backoff; el lifecycle elimina cualquier huérfano restante.

`POST /api.php?action=import_csv` ahora responde `202 Accepted` con
`job_id`; el avance se consulta con `import_status` o `import_status_latest`.

## Preparación de MinIO/S3

### Topología incluida para los dos servidores

El archivo `deploy/minio-two-node/compose.yml` define un único clúster con
ocho discos: cuatro discos locales por servidor y paridad `EC:4`. MinIO reparte
cada objeto en fragmentos de datos y paridad; no mantiene una copia completa
del archivo en cada máquina.

Esta topología tiene una limitación deliberada e inevitable con sólo dos
servidores: si uno cae quedan cuatro de ocho discos. Existe quorum de lectura,
pero el quorum de escritura requiere cinco. Durante esa caída los archivos
existentes continúan disponibles para lectura, siempre que no falle otro disco,
pero se bloquean nuevas cargas, eliminaciones y modificaciones hasta recuperar
el servidor. MinIO no sustituye una copia de seguridad y un fallo que destruya
ambos servidores sigue siendo un riesgo aceptado de esta restricción.

Requisitos físicos y de red:

- Cuatro discos o volúmenes de bloque realmente independientes, persistentes y
  de capacidad/tipo equivalente en cada servidor. Cuatro carpetas sobre el
  mismo disco no proporcionan tolerancia y el preflight las rechaza.
- Montajes exclusivos y estables, preferentemente XFS, por ejemplo
  `/mnt/minio/disk1` hasta `/mnt/minio/disk4`.
- Los hostnames `pchquit01dweb14.fj.local` y `pchquit01dweb14.fj.local-02` deben resolver en ambos
  servidores mediante DNS interno o `/etc/hosts`; el puerto TCP 9000 debe estar
  permitido en ambos sentidos entre los servidores y desde el
  balanceador/aplicación.
  La consola se enlaza a `127.0.0.1:9001` para acceder sólo mediante túnel SSH.
- Si se usa F5/VIP, registre ambos puertos 9000 como backends, preserve la
  cabecera `Host` y use `/minio/health/cluster/read` como monitor del pool. El
  quorum de escritura (`/minio/health/cluster`) se supervisa como una alarma
  separada; usarlo para retirar backends también bloquearía las lecturas durante
  la caída de uno de los dos servidores.
- Certificado TLS en `/etc/academic-minio/certs/public.crt`, su clave en
  `private.key` y la CA emisora dentro de `CAs/`. El certificado de cada host
  debe incluir `pchquit01dweb14.fj.local` o `pchquit01dweb14.fj.local-02` en sus SAN.
- Archivos `/etc/academic-minio/secrets/root-user` y `root-password` con el
  mismo contenido en ambos hosts y permisos `0600`. Compose los monta como
  secretos; la credencial no queda en `minio.env` ni en `docker inspect`.
- La hora de ambos hosts debe estar sincronizada.

Configure el hostname una sola vez en cada servidor y registre ambos nombres
en `/etc/hosts` (reemplace las IP por las reales de los nodos):

```bash
# Nodo 1
su -
hostnamectl set-hostname "pchquit01dweb14.fj.local" && exec bash

# Nodo 2
su -
hostnamectl set-hostname "pchquit01dweb14.fj.local-02" && exec bash
```

En **ambos** nodos, agregue estas entradas a `/etc/hosts`:

```text
10.1.13.81  pchquit01dweb14.fj.local
10.1.13.82  pchquit01dweb14.fj.local-02
```

Compruebe la resolución antes de seguir:

```bash
getent hosts pchquit01dweb14.fj.local
getent hosts pchquit01dweb14.fj.local-02
```

Los contenedores Docker no heredan automáticamente estas entradas de
`/etc/hosts`. Si no cuentan con DNS interno que resuelva los dos hostnames,
defina también las IP reales en el `.env` de la aplicación:

```dotenv
APP_RECORD_01_IP=10.1.13.81
APP_RECORD_02_IP=10.1.13.82
```

Al arrancar los contenedores aplique el override, que sólo replica la
resolución de nombres dentro de PHP y el worker; `MINIO_ENDPOINT` sigue usando
`pchquit01dweb14.fj.local` o `pchquit01dweb14.fj.local-02`, no una IP:

```bash
docker compose \
  -f docker-compose.yml \
  -f deploy/minio-two-node/app.compose.yml \
  up -d php-app import-worker nginx
```

Prepare `minio.env` en cada host. Todos los valores coinciden salvo
`MINIO_NODE_NAME`:

```bash
cp deploy/minio-two-node/minio.env.example deploy/minio-two-node/minio.env
# servidor 1: MINIO_NODE_NAME=pchquit01dweb14.fj.local
# servidor 2: MINIO_NODE_NAME=pchquit01dweb14.fj.local-02
deploy/minio-two-node/preflight.sh deploy/minio-two-node/minio.env
```

Una vez que el preflight pase en los dos servidores, inicie ambos procesos en
la misma ventana de despliegue; uno solo no reúne quorum para crear el clúster:

```bash
docker compose \
  --env-file deploy/minio-two-node/minio.env \
  -f deploy/minio-two-node/compose.yml up -d
```

Con un F5/VIP, configure el mismo endpoint en ambos servidores, por ejemplo
`MINIO_ENDPOINT=https://minio.interno.example`. Sin balanceador S3, configure
`https://pchquit01dweb14.fj.local:9000` en la aplicación del servidor 1 y
`https://pchquit01dweb14.fj.local-02:9000` en la del servidor 2. Son URLs diferentes hacia
el mismo clúster; cuando cae un servidor también cae la aplicación que apunta a
su MinIO local.

Se usan dos buckets separados:

- `record-academico-imports`: datos temporales, sin acceso público y con
  expiración predeterminada de siete días.
- `record-academico-assets`: membretes y firmas, sin acceso público y con
  versionado.

El comando idempotente de inicialización crea los buckets, habilita el
versionado y configura el lifecycle:

```bash
docker compose exec php-app php bin/storage-init.php
```

En producción debe ejecutarse una vez con credenciales de aprovisionamiento.
Después conviene entregar a la aplicación una credencial distinta y de mínimo
privilegio. La credencial de runtime necesita listar/comprobar los buckets y
leer, crear y borrar objetos sólo dentro de estos dos buckets. No debe poder
cambiar políticas, lifecycle ni usuarios.

Requisitos del endpoint:

- TLS verificable desde ambos servidores; para una CA privada configure
  `MINIO_CA_BUNDLE`.
- El mismo clúster, buckets y credenciales de runtime en ambos nodos. El nombre
  puede ser un VIP común o el hostname local de cada miembro del clúster.
- Relojes sincronizados por NTP, porque la firma AWS v4 depende de la hora.
- Sin acceso anónimo a los buckets.
- Copias de seguridad y supervisión de capacidad/latencia fuera de la app.

El cliente usa API S3 mediante AWS SDK. Por tanto puede apuntar a MinIO o a un
servicio S3 compatible sin cambiar el código.

## Variables obligatorias en ambos nodos

```dotenv
STORAGE_DRIVER=s3
# Con F5/VIP: el mismo valor en ambos nodos.
MINIO_ENDPOINT=https://minio.interno.example
# Sin F5/VIP: use pchquit01dweb14.fj.local en el servidor 1 y pchquit01dweb14.fj.local-02 en el servidor 2.
MINIO_REGION=us-east-1
MINIO_IMPORT_BUCKET=record-academico-imports
MINIO_ASSET_BUCKET=record-academico-assets
MINIO_ACCESS_KEY=
MINIO_SECRET_KEY=
MINIO_PATH_STYLE=true
MINIO_TLS_VERIFY=true
MINIO_CA_BUNDLE=
IMPORT_WORK_DIR=/var/www/html/var/import-work

CONFIG_STORAGE=database
HISTORY_STORAGE=database
SECURITY_ALERT_STORAGE=database

SESSION_HANDLER=database
RATE_LIMIT_STORE=database
RATE_LIMIT_FAIL_OPEN=false
```

Las claves también pueden suministrarse con `MINIO_ACCESS_KEY_FILE` y
`MINIO_SECRET_KEY_FILE`. Los secretos no deben formar parte de la imagen ni del
repositorio. SQL Server debe usar cifrado en tránsito y en reposo porque la
tabla `Academico.Sesion` contiene tokens de Keycloak.

## Orden de despliegue en producción

La migración SQL es aditiva y transaccional, pero el código nuevo del worker
necesita sus columnas. No despliegue el worker antes de aplicarla.

1. Haga respaldo de SQL, `config/config.json`, membrete y firma actuales.
2. Bloquee temporalmente nuevas importaciones y deje que la cola anterior se
   vacíe. Compruébelo con:

   ```sql
   SELECT estado, COUNT(*) AS cantidad
   FROM Academico.ImportJob
   WHERE estado IN ('PENDIENTE', 'PROCESANDO')
   GROUP BY estado;
   ```

   El worker nuevo conserva compatibilidad con `rutaArchivo` para jobs
   antiguos, pero una ruta local sólo existe en el servidor que recibió la
   carga. Vaciar la cola antes del cambio evita esa ambigüedad.
3. Prepare los ocho discos, TLS y `minio.env`; ejecute el preflight en ambos
   hosts e inicie `deploy/minio-two-node/compose.yml` simultáneamente. Luego
   compruebe conectividad TLS a SQL Server desde ambos servidores.
4. Ejecute una sola vez
   `scripts/20260914_minio_multinode.sql` en la base principal. Con `sqlcmd`,
   una forma habitual es:

   ```bash
   sqlcmd -S servidor -d base -U usuario -i scripts/20260914_minio_multinode.sql -b
   ```

5. Configure las mismas credenciales, buckets y variables compartidas. Si no
   existe un VIP para S3, sólo `MINIO_ENDPOINT` cambia al miembro MinIO local.
6. Ejecute `php bin/storage-init.php` una sola vez con credenciales de
   aprovisionamiento y luego use la credencial restringida de runtime.
7. Despliegue la misma imagen en el nodo A, confirme `/health/ready`, y repita
   en el nodo B.
8. Arranque los workers. Puede haber uno por nodo: la cola y el bloqueo SQL
   impiden procesamiento duplicado/concurrente incompatible.
9. Desde el panel vuelva a cargar membrete y firma si desea activarlos en
   MinIO. Mientras no haya descriptor compartido se usan las copias empaquetadas.
10. Habilite nuevamente las importaciones y realice la prueba cruzada descrita
    abajo.

El script SQL no se ejecuta automáticamente desde el contenedor para evitar
que un despliegue aplique DDL sin una ventana y respaldo controlados.

## Verificación cruzada

1. Abra sesión entrando por el nodo A y fuerce la siguiente petición al nodo
   B; la sesión debe continuar activa.
2. Cargue un CSV por A y compruebe que un worker de B puede completarlo.
3. Cargue un membrete por A y genere un PDF atendido por B.
4. Verifique que `/health/live` devuelve `200` en cada Nginx y que
   `/health/ready` devuelve `200` sólo cuando la configuración multinodo, SQL,
   el esquema, ambos buckets y SQL Server están disponibles.
5. Confirme en SQL que el job conserva `storageBucket`, `objectKey`,
   `sha256`, `tamanoBytes` y el estado final.
6. Confirme que el bucket de imports no tiene el objeto una vez terminado el
   job.

La prueba de integración S3 incluida realiza `HEAD`, `PUT`, `GET`, validación
de contenido y `DELETE`:

```bash
RUN_MINIO_INTEGRATION=1 \
STORAGE_DRIVER=s3 \
php vendor/bin/phpunit --testsuite integration
```

## Operación y fallos

- Si cae un servidor completo en la topología `EC:4`, el clúster queda en modo
  sólo lectura: `/minio/health/cluster/read` continúa en `200` y
  `/minio/health/cluster` pasa a `503`. No intente forzar escrituras ni iniciar
  un clúster nuevo con los discos restantes.
- Compruebe por separado liveness, quorum de lectura y quorum de escritura con
  `deploy/minio-two-node/check-cluster.sh "$MINIO_ENDPOINT"`. El comando devuelve
  un código distinto de cero si cualquiera de los tres no está disponible.
- Al regresar el servidor, espere a que ambos nodos y los ocho discos aparezcan
  en línea y que termine cualquier reparación antes de reactivar cargas.
- Si MinIO/S3 no está disponible, no se aceptan nuevas cargas. Los jobs ya
  encolados se reprograman hasta cinco veces y luego quedan en `ERROR`.
- Si SQL Server no está disponible, las sesiones no pueden continuar. El rate
  limit falla cerrado en producción con `RATE_LIMIT_FAIL_OPEN=false`.
- El readiness devuelve `503`, permitiendo que el balanceador retire el nodo.
- Un objeto de import corrupto o distinto del SHA-256 guardado nunca se
  procesa.
- El lifecycle es una red de seguridad, no el mecanismo principal de borrado.
- Las importaciones quedan serializadas globalmente; añadir workers aumenta
  disponibilidad, no paralelismo, hasta rediseñar el manejo de índices.

## Rollback

Las columnas y tablas nuevas pueden permanecer: son compatibles y no se deben
borrar durante un rollback. Antes de volver a un worker antiguo, bloquee las
subidas y deje terminar todos los jobs que ya tienen `objectKey`, porque el
worker anterior no sabe descargar una URI `s3://`. Con dos nodos no active
`STORAGE_DRIVER=local` como rollback permanente: recrearía el problema de
archivos divergentes.
