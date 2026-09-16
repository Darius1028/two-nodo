# Sistema de Registro Académico

Sistema web para la gestión, consulta y certificación de registros académicos institucionales. Permite importar, administrar y exportar registros de estudiantes, generar PDFs certificados con QR de verificación, y expone una API REST completa. La autenticación se delega a Keycloak mediante OpenID Connect; la **autorización por roles se resuelve desde una base de datos institucional externa** (independiente de Keycloak).

> **Estado del proyecto:** activo. Solo hay dos rutas de instalación
> documentadas: [desarrollo local con MinIO](docs/MANUAL_INSTALACION_DESARROLLO.md)
> y [dos nodos con un disco por servidor y HTTP privado](docs/INSTALACION_NODO_1_DISCO_UNICO_HTTP.md).

---

## Características principales

- **Gestión de registros** — CRUD completo de registros académicos por cédula, materia, período y año. La eliminación es **soft delete**: los registros pasan a `estado = 'X'` y son invisibles en consultas, pero permanecen en la base para auditoría.
- **Importación CSV asíncrona y compartida** — El nodo receptor valida y sube el archivo a S3/MinIO; cualquier worker puede reclamarlo desde la cola SQL, comprobar su SHA-256 e importarlo en streaming. Soporta archivos de hasta 128 MB.
- **Exportación CSV** — Descarga de registros por año o por cédula (excluye registros con `estado = 'X'`).
- **Validación previa de CSV** — Endpoint `validate_csv` que reporta errores de columnas antes de importar.
- **Generación de PDFs** — Certificados con membrete, firma y código QR verificable. Al pulsar "Generar PDF" el archivo se descarga automáticamente y se archiva en el Repositorio Documental en un solo paso.
- **Firma digital PDF (PKCS#7 / adbe.pkcs7.detached)** — Al activar `PADES_SIGN_ENABLED=true` cada PDF generado incrusta una firma criptográfica detached en una revisión incremental (sin alterar membrete, firma gráfica ni QR). Cualquier modificación posterior invalida la firma. Los lectores PDF muestran el panel de firmas con el estado de verificación.
- **Visor de PDF integrado** — Renderizado en `<canvas>` con PDF.js (alojado localmente, sin CDN); evita que el visor nativo del navegador exponga botones de descarga o envío a Google Drive.
- **QR de verificación con caché local** — La imagen QR se genera una vez por cédula y se almacena en `var/cache/qr/` (TTL 30 días). Peticiones posteriores no hacen llamadas de red al servidor de QR externo.
- **Generación y archivo documental en un solo paso** — Al generar el PDF se envía al servicio institucional, se guarda su UUID y se audita la operación.
- **Verificación pública de certificados** — Endpoint sin login para validar QR desde portales externos, con rate limiting por IP.
- **API REST JSON** — Endpoints para búsqueda, CRUD, importación CSV, generación y archivo de PDFs.
- **Panel administrativo** — Interfaz HTML para gestión completa con control de acceso por roles.
- **Auditoría estilo Envers** — Cada INSERT/UPDATE/DELETE (individual y masivo) genera un snapshot en `AcademicoAUD.RecordAcademico_AUD` vinculado a una revisión en `AUD.REVINFO`. Los registros AUD nunca se borran al eliminar el registro principal.
- **Inspección de contenido CSV** — Antes de importar (y en la validación previa) se escanea el archivo en busca de XSS almacenado (`<script>`, `javascript:`, `onerror`) e inyección de fórmulas CSV (`=`, `+`, `-`, `@` al inicio de celda). Un archivo sospechoso es bloqueado y dispara una alerta de seguridad de nivel 9.
- **Alertas de seguridad compartidas** — `SecurityAlertService` persiste eventos en SQL en producción y los emite por `error_log` con prefijo `[SEGURIDAD][NIVEL N]` para el SIEM.
- **Validación de datos** — Detección de emails corruptos, cédulas inválidas y calificaciones fuera de rango.
- **Autenticación OAuth2/OIDC** — Integración con Keycloak, soporte para Single Logout y refresh de tokens.
- **Autorización desacoplada** — Los roles **no** provienen del token de Keycloak; se consultan por cédula o username en la base institucional `PORTAL_APLICATIVOS_CJ` (esquema `ADM`).
- **Manejo global de errores** — `ErrorHandler` registra como excepción todo error no controlado; el usuario nunca ve stack traces en producción.
- **Página de error 403** — Página personalizada con redirección al sistema cuando se intenta acceder directamente a recursos protegidos.
- **Estado multinodo** — Sesiones y rate limiting en SQL Server; configuración editable, historial y alertas en SQL Server.
- **Archivos compartidos** — CSV pendientes, membretes y firmas en buckets privados S3/MinIO; el disco local sólo conserva temporales y cachés regenerables.

---

## Stack tecnológico

| Componente | Tecnología |
|---|---|
| Lenguaje | PHP 8.2+ |
| ORM | Doctrine ORM 2.x / DBAL 3.x |
| Base de datos principal | Microsoft SQL Server |
| Base de datos de roles | Microsoft SQL Server (`PORTAL_APLICATIVOS_CJ`) |
| Autenticación | Keycloak (OpenID Connect) |
| Generación PDF | FPDF |
| Firma digital PDF | OpenSSL (CMS/PKCS#7 detached) via `openssl_cms_sign` |
| Visor PDF | PDF.js (alojado localmente) |
| Servidor web | Nginx |
| Runtime | PHP-FPM |
| Contenedores | Docker / Docker Compose |
| Archivos compartidos | API S3 compatible (MinIO) mediante AWS SDK for PHP |
| Sesiones y rate limiting | SQL Server |

---

## Estructura del proyecto

```
sistema-records/
├── public/                    # Document root
│   ├── index.php              # Redirección a workspace.php
│   ├── workspace.php          # Interfaz de consulta pública/protegida
│   ├── api.php                # API REST
│   ├── AdminPanel.php         # Panel administrativo
│   ├── PdfGenerator.php       # Generador de PDFs
│   ├── callback.php           # Callback OAuth2 de Keycloak
│   ├── login.php              # Página de login
│   ├── logout.php             # Single Logout
│   ├── 403.html               # Página de error de acceso denegado
│   ├── diagnostico.php        # Diagnóstico de sistema (dev)
│   ├── .htaccess              # Redirige errores 403 a 403.html
│   └── assets/                # Imágenes (membrete, firma)
├── src/
│   ├── Core/
│   │   ├── EntityManagerProvider.php  # Bootstrap de Doctrine
│   │   ├── AuditListener.php          # Listener de auditoría (postPersist/postUpdate/preRemove)
│   │   ├── ErrorHandler.php           # Manejador global de excepciones
│   │   └── RequestContext.php         # IP real del cliente con soporte TRUSTED_PROXIES (previene bypass de rate limit)
│   ├── Doctrine/
│   │   └── Type/
│   │       └── SqlServerDateTimeType.php  # Tipo DATETIME compatible con SQL Server
│   ├── Entity/
│   │   ├── AcademicRecord.php             # Entidad principal (Academico.RecordAcademico)
│   │   ├── AcademicRecordAudit.php        # Entidad AUD readOnly — patrón @Audited de Envers
│   │   └── RevisionInfo.php               # Entidad de revisión (AUD.REVINFO)
│   ├── Exception/
│   │   ├── AppException.php               # Base de excepciones de la aplicación
│   │   ├── ValidationException.php        # Error de validación de datos de entrada
│   │   ├── SystemException.php            # Error de sistema (archivo ilegible, etc.)
│   │   ├── NotFoundException.php          # Recurso no encontrado
│   │   └── InvalidConfigurationException.php # Configuración inválida
│   ├── Security/
│   │   ├── KeycloakClient.php         # Cliente OIDC
│   │   ├── SecurityContext.php        # Sesión, callback, logout, refresh, idUsuario lazy
│   │   ├── RoleProvider.php           # Roles e idUsuario desde base institucional externa
│   │   ├── RateLimiter.php            # Rate limiting por IP (SQL Server en multinodo)
│   │   └── SecurityAlertService.php   # Registro compartido de alertas
│   └── Service/
│       ├── AcademicRecordAuditService.php  # Escribe en AUD via DBAL (individual)
│       ├── CsvService.php                  # Fachada: importación/exportación/validación CSV (delega en las tres clases siguientes)
│       ├── CsvContentInspector.php         # Validación de encabezados, inspección XSS/fórmulas y reglas por columna
│       ├── CsvImportProcessor.php          # Inserción por lotes (60 filas/batch) con auditoría e índices
│       ├── CsvRowMapper.php                # Mapeo de fila CSV a parámetros de inserción de AcademicRecord
│       ├── PdfService.php                  # Generación y archivo de PDFs
│       ├── PadesSignerService.php          # Firma digital PDF (CMS detached, incremental update)
│       ├── RepositorioDocumentalService.php # Cliente del Repositorio Documental
│       ├── ErrorFinder.php                 # Detección de errores en datos
│       ├── ConfigService.php               # Configuración compartida en SQL
│       ├── ImportFileStorage.php           # CSV en S3/MinIO
│       ├── ImportJobService.php            # Encolado transaccional compensado
│       ├── AssetStorageService.php          # Membrete/firma versionados
│       └── KeycloakTokenService.php        # Token de servicio (client_credentials)
├── templates/
│   └── includes/header.php    # Header HTML compartido
├── config/
│   ├── doctrine.php           # Configuración de Doctrine
│   └── config.json            # Configuración de la aplicación
├── scripts/
│   └── 20260914_minio_multinode.sql # Migración aditiva para dos nodos
├── bin/
│   ├── console.php            # Consola de comandos Doctrine
│   ├── import-worker.php      # Worker de la cola compartida
│   └── storage-init.php       # Buckets, versionado y lifecycle
├── docs/
│   ├── MANUAL_INSTALACION_DESARROLLO.md # Desarrollo local con MinIO
│   └── INSTALACION_NODO_1_DISCO_UNICO_HTTP.md # Dos nodos, un disco y HTTP privado
├── deploy/minio-two-node/
│   ├── compose-single-disk.yml # Un miembro MinIO por servidor, pool compartido
│   ├── minio.single-disk.env.example # Configuración del perfil de dos nodos
│   ├── app.compose.yml      # Resuelve los hostnames del clúster dentro de Docker
│   ├── preflight-single-disk.sh # Valida hostname, resolución y Compose
│   └── check-cluster.sh     # Comprueba quorum de lectura/escritura
├── docker/
│   ├── Dockerfile             # PHP 8.2-FPM con driver SQL Server
│   ├── nginx.conf             # Configuración de Nginx (128M max body)
│   └── uploads.ini            # Límites PHP: 128M upload, 512M memory_limit
├── docker-compose.yml
├── composer.json
├── reiniciar.sh               # Script para reiniciar contenedores
├── .env.example
└── var/                       # Cache y logs (generado en instalación)
    ├── cache/
    └── log/
```

---

## Requisitos previos

- Docker y Docker Compose instalados.
- Acceso de red a una instancia de **Microsoft SQL Server** (2016 o superior) para la base de datos principal.
- Acceso de red a la base institucional **`PORTAL_APLICATIVOS_CJ`** (SQL Server) para resolución de roles.
- Servidor **Keycloak** configurado con un realm y client para esta aplicación.
- Para dos nodos, un disco persistente por host para el clúster **MinIO
  distribuido**, un **SQL Server compartido** accesible desde ambos y una red
  privada entre los nodos.

---

## Configuración

### 1. Variables de entorno

Copia el archivo de ejemplo y edítalo con tus valores:

```bash
cp .env.example .env
```

#### Base de datos principal

| Variable | Descripción | Ejemplo |
|---|---|---|
| `APP_ENV` | Entorno (`dev` o `prod`) | `prod` |
| `APP_DEBUG` | Mostrar errores (`true`/`false`) | `false` |
| `DB_HOST` | Host de SQL Server | `10.1.27.25` |
| `DB_PORT` | Puerto de SQL Server | `1433` |
| `DB_INSTANCE` | Instancia SQL Server | `SQLEXPRESS` |
| `DB_NAME` | Nombre de la base de datos | `record_academico_db` |
| `DB_USER` | Usuario de la base de datos | `sa` |
| `DB_PASS` | Contraseña | `secret` |
| `DB_ENCRYPT` | Cifrar conexión (`true`/`false`) | `false` |
| `DB_LOGIN_TIMEOUT` | Timeout de login en segundos | `30` |

#### Keycloak (autenticación)

| Variable | Descripción | Ejemplo |
|---|---|---|
| `KEYCLOAK_SERVER_URL` | URL base de Keycloak | `https://auth.ejemplo.com` |
| `KEYCLOAK_REALM` | Nombre del realm | `cj-funcionarios` |
| `KEYCLOAK_CLIENT_ID` | Client ID configurado en Keycloak | `record-academico-php` |
| `KEYCLOAK_CLIENT_SECRET` | Secret del client de Keycloak | `abc123` |
| `KEYCLOAK_REDIRECT_URI` | URL de callback tras login | `https://app.ejemplo.com/callback.php` |
| `KEYCLOAK_ROLE_ADMIN` | Nombre del rol de administrador | `ADMIN_ACADEMICO` |
| `KEYCLOAK_ROLE_USER` | Nombre del rol de usuario | `SECRE_ACADEMICO` |
| `KEYCLOAK_CEDULA_CLAIM` | Claim en el token que contiene la cédula | `cedula` |
| `WORKSPACE_ACCESS_MODE` | `protected` (requiere login) o `public` | `protected` |
| `TRUSTED_PROXIES` | IPs o CIDRs del proxy/balanceador que habla directo con PHP-FPM (separados por coma). Solo cuando `REMOTE_ADDR` coincide con uno de estos valores se confían `X-Forwarded-For` / `X-Real-IP`. Si queda vacío se usa siempre `REMOTE_ADDR`. | `10.10.10.10,172.16.0.0/16` |

#### Base de datos externa de roles (`PORTAL_APLICATIVOS_CJ`)

Los roles **no** vienen del token de Keycloak. Se consultan en una base institucional cruzando por cédula (normalizada sin guiones) o por username de AD, lo que primero coincida.

| Variable | Descripción | Ejemplo |
|---|---|---|
| `EXTERNAL_ROLES_DB_HOST` | Host del SQL Server de roles | `10.1.27.25` |
| `EXTERNAL_ROLES_DB_PORT` | Puerto | `1433` |
| `EXTERNAL_ROLES_DB_INSTANCE` | Instancia SQL Server (omitir si no aplica) | `DESA02` |
| `EXTERNAL_ROLES_DB_NAME` | Base de datos | `PORTAL_APLICATIVOS_CJ` |
| `EXTERNAL_ROLES_DB_USER` | Usuario | `USR_ADM_DES_ALL` |
| `EXTERNAL_ROLES_DB_PASS` | Contraseña | `secret` |
| `EXTERNAL_ROLES_APP_ALIAS` | Alias de esta app en `ADM.Aplicativo` | `SEC-ACAD` |

#### QR de verificación

| Variable | Descripción | Ejemplo |
|---|---|---|
| `QR_BASE_URL` | URL base para los QR de verificación (se concatena la cédula) | `https://tu-dominio.com/verificar-record/` |

> Esta variable tiene prioridad sobre `qr_base_url` en `config.json`. Úsala para cambiar la URL por entorno sin necesidad de modificar archivos del proyecto.

#### Repositorio Documental

| Variable | Descripción |
|---|---|
| `REPOSITORIO_DOCUMENTAL_URL` | URL del endpoint para archivar documentos PDF |

#### MinIO/S3 y estado compartido

Las configuraciones de MinIO dependen de la ruta elegida: en desarrollo el
endpoint interno es `http://minio:9000`; en dos nodos se usa el hostname del
miembro MinIO local por HTTP privado. El HTTPS público termina en el
balanceador de la aplicación; MinIO no se publica a Internet.

| Variable | Descripción | Valor de producción |
|---|---|---|
| `STORAGE_DRIVER` | Backend de objetos | `s3` |
| `MINIO_ENDPOINT` | Endpoint MinIO de la ruta elegida | ver la guía correspondiente |
| `MINIO_IMPORT_BUCKET` | Bucket privado para CSV temporales | `record-academico-imports` |
| `MINIO_ASSET_BUCKET` | Bucket privado y versionado para assets PDF | `record-academico-assets` |
| `MINIO_ACCESS_KEY` / `MINIO_SECRET_KEY` | Credencial S3 de runtime | secreto externo |
| `CONFIG_STORAGE` | Configuración mutable | `database` |
| `HISTORY_STORAGE` | Historial de operaciones | `database` |
| `SECURITY_ALERT_STORAGE` | Alertas persistentes | `database` |
| `SESSION_HANDLER` | Backend de sesiones PHP | `database` |
| `RATE_LIMIT_STORE` | Backend global de contadores | `database` |

Antes de activar estos valores ejecute la migración SQL aditiva
`scripts/20260914_minio_multinode.sql` y el inicializador
`php bin/storage-init.php`.

#### Firma digital PDF (opcional)

Al activar la firma, cada PDF generado (tanto en descarga directa como en archivado al Repositorio Documental) se firma criptográficamente en una revisión incremental antes de entregarse. La configuración es exclusivamente por `.env`; no se lee de `config.json`.

| Variable | Descripción | Ejemplo |
|---|---|---|
| `PADES_SIGN_ENABLED` | Activar firma (`true`/`false`). Si es `false` u omitida, no se firma. | `true` |
| `PADES_CERT_PATH` | Ruta al certificado en formato PKCS#12 (`.p12` / `.pfx`) | `/var/secrets/dev.p12` |
| `PADES_CERT_PASSWORD` | Contraseña del `.p12` | `changeit` |
| `PADES_SIGNER_NAME` | Nombre del firmante (aparece en el panel de firmas del lector) | `Escuela de la Función Judicial` |
| `PADES_SIGNER_REASON` | Motivo de la firma | `Certificación de expediente académico` |
| `PADES_SIGNER_LOCATION` | Ubicación | `Quito, EC` |

> El archivo `.p12` debe estar fuera del document root (`public/`) y con permisos restrictivos (`chmod 600`). Nunca commitearlo al repositorio.

Para generar un certificado auto-firmado de pruebas:

```bash
sh tools/generate_dev_cert.sh
# genera secrets/dev.p12; copia las líneas PADES_* que imprime al .env
```

Ver la sección [Firma digital PDF](#firma-digital-pdf) para detalles sobre alcance, verificación y limitaciones.

### 2. Configuración de la aplicación

El archivo `config/config.json` contiene los valores empaquetados de arranque:

```json
{
  "qr_enabled": true,
  "qr_base_url": "https://tu-dominio.com/verificar-record/",
  "letterhead_image": "assets/letterhead.png",
  "signature_image": "assets/signature.png",
  "column_schema": [ ... ]
}
```

- **`qr_enabled`** — Activa el código QR en los PDFs generados.
- **`qr_base_url`** — URL base de fallback para los QR. Si está definida `QR_BASE_URL` en el entorno, esta es ignorada.
- **`letterhead_image`** — Ruta legada/valor de bootstrap del membrete.
- **`signature_image`** — Ruta legada/valor de bootstrap de la firma.
- **`column_schema`** — Define las columnas disponibles para importación CSV y visualización en PDF. Cada columna tiene `key`, `label`, `width` y `visible`.

Con `CONFIG_STORAGE=database`, los valores editables se leen de
`Academico.ConfiguracionAplicacion`; el JSON queda como fallback versionado con
la aplicación. Así una modificación realizada en un nodo es visible en el otro.

### 3. Assets

Las imágenes incluidas en `public/assets/` sirven sólo como bootstrap:

```
public/assets/letterhead.png
public/assets/signature.png
```

Las cargas posteriores desde el panel aceptan PNG o JPEG, se normalizan a PNG,
se guardan con clave inmutable por SHA-256 en `MINIO_ASSET_BUCKET` y se activan
mediante la configuración SQL. Nginx bloquea su descarga directa desde
`/assets/letterhead.png` y `/assets/signature.png`.

---

## Instalación y despliegue

Elige una única ruta y sigue la guía completa; no combines sus archivos `.env`,
comandos Compose ni directorios de datos.

| Entorno | Guía canónica | MinIO |
|---|---|---|
| Desarrollo local | [Manual de desarrollo](docs/MANUAL_INSTALACION_DESARROLLO.md) | Contenedor local, perfil `local-infra` |
| Dos nodos | [Instalación de nodo 1](docs/INSTALACION_NODO_1_DISCO_UNICO_HTTP.md) | Clúster compartido, un disco por servidor y HTTP privado |

### Servicios Docker

| Servicio | Imagen | Puerto           | Descripción |
|---|---|------------------|---|
| `nginx` | nginx:alpine | `8010:80`        | Servidor web / proxy inverso |
| `php-app` | (Dockerfile local) | `9000` (interno) | PHP 8.2-FPM con driver SQL Server |
| `import-worker` | `academic-php:8.2-dev` | — | Consume la cola SQL y descarga CSV desde S3 |
| `minio` | MinIO fijado por versión | `127.0.0.1:9000/9001` | Sólo perfil local `local-infra` |
| `storage-init` | `academic-php:8.2-dev` | — | Inicializador idempotente de buckets, sólo perfil local |

La guía local incluye el perfil `local-infra`, la inicialización de buckets y
la verificación del servicio. La guía de dos nodos incluye la red privada,
preflight, inicialización y validación cruzada.

### Límites de subida y memoria

El archivo `docker/uploads.ini` configura PHP para importaciones CSV grandes:

```ini
upload_max_filesize = 128M
post_max_size       = 128M
memory_limit        = 512M
max_execution_time  = 300
max_input_time      = 300
```

Nginx también está configurado con `client_max_body_size 128M` en `docker/nginx.conf`. Ambos valores deben ser coherentes.

> **Nota sobre memoria:** El import CSV usa streaming (`openStreamUtf8`): abre el archivo directo sin cargarlo en RAM. El pico de memoria es de ~5 MB para archivos UTF-8 independientemente del tamaño del archivo. El `memory_limit = 512M` cubre PHP-FPM, Doctrine y los batches de INSERT.

### Resolución de IP real del cliente

Nginx está configurado con el módulo `ngx_http_realip_module` para resolver la IP real detrás de proxies o redes internas. Los rangos `set_real_ip_from` cubren redes privadas (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`) y la red Docker interna. Ajustar en `docker/nginx.conf` si el entorno tiene rangos distintos.

`RequestContext::getClientIp()` aplica la siguiente lógica con soporte de `TRUSTED_PROXIES`:

1. Si `REMOTE_ADDR` **no** está en `TRUSTED_PROXIES` (o la lista está vacía) → se usa `REMOTE_ADDR` directamente. Las cabeceras `X-Forwarded-For` / `X-Real-IP` se descartan porque el cliente podría haberlas inventado (previene bypass de rate limit).
2. Si `REMOTE_ADDR` **sí** está en `TRUSTED_PROXIES` → se recorre la cadena `X-Forwarded-For` de derecha a izquierda, saltando los proxies confiables, hasta encontrar la primera IP que no lo sea (IP del cliente real).
3. Si la cadena XFF no aportó una IP de cliente (vacía o todas eran proxies) → se usa `X-Real-IP` como segunda opción.

Configurar `TRUSTED_PROXIES` con las IPs reales del F5 / balanceador / Nginx corporativo para que la resolución de IP sea correcta en auditoría y rate limiting.

Las columnas `ipCrea` / `ipModifica` son `VARCHAR(45)` para soportar tanto IPv4 como IPv6.

---

## Auditoría (patrón Envers)

El sistema implementa auditoría automática equivalente al patrón `@Audited` de Hibernate Envers.

### Flujo de escritura

| Operación | Ruta | Tabla AUD |
|---|---|---|
| INSERT individual | `postPersist` → `AcademicRecordAuditService` | escribe REVTYPE=0 |
| UPDATE individual | `postUpdate` → `AcademicRecordAuditService` | escribe REVTYPE=1 |
| Eliminación individual | `markAsDeleted()` → `flush()` → `postUpdate` | escribe REVTYPE=1 con `estado='X'` |
| Eliminación masiva por año | `UPDATE` SQL nativo directo | no pasa por ORM; queda en `logHistory` |
| Import CSV masivo | `CsvService::bulkInsertAudit` | escribe REVTYPE=0 en batch |

### Campos de trazabilidad del operador

Todos los INSERT/UPDATE/DELETE — incluido el import CSV masivo — registran quién realizó la operación:

| Campo | Origen |
|---|---|
| `idPersonaCrea` / `idPersonaModifica` | `ADM.Usuario.id` del usuario autenticado (`RoleProvider::getUsuarioId`) |
| `ipCrea` / `ipModifica` | IP real del cliente (`RequestContext::getClientIp`) |
| `equipoCrea` / `equipoModifica` | Hostname resuelto por DNS inverso de la IP |

`SecurityContext::getCurrentUserId()` resuelve el ID de forma **lazy**: si la sesión no tiene `idUsuario` (por ejemplo, el usuario no cerró sesión tras un despliegue), lo consulta en la base externa y lo guarda en sesión para la misma visita.

### Garantías

- Los registros de `AcademicoAUD.RecordAcademico_AUD` **nunca se modifican ni eliminan** al operar sobre la tabla principal — no existe FK hacia `Academico.RecordAcademico`.
- Toda escritura AUD usa la **misma transacción** DBAL que la operación principal: si algo falla, el rollback deshace tanto el dato como la auditoría.
- `AcademicRecordAudit` tiene `readOnly: true` en Doctrine: el ORM nunca hace flush sobre la tabla AUD.
- La entidad `AcademicRecordAudit` es la única fuente de verdad del mapeo de columnas (`fromRecord()`, `fromRawRow()`, `toInsertArray()`).

### Equivalencia con Hibernate Envers

| Java / Hibernate Envers | Este proyecto |
|---|---|
| `@Audited` en la entidad | `AuditListener` registrado en `doctrine.php` |
| Interceptor de eventos | `AuditListener` (postPersist / postUpdate / preRemove) |
| Crea copia interna | `AcademicRecordAudit::fromRecord()` |
| Inserta vía JDBC SQL | `AcademicRecordAuditService` via DBAL |
| `@RevisionEntity` / `REVINFO` | `RevisionInfo` / `AUD.REVINFO` |
| Tabla `_AUD` read-only por ORM | `readOnly: true` en `#[ORM\Entity]` |

---

## Firma digital PDF

La firma se implementa como una **revisión incremental** sobre el PDF ya generado por FPDF, sin tocar la estructura visual. La capa criptográfica (CMS/PKCS#7 detached) se añade en un objeto de firma con `SubFilter /adbe.pkcs7.detached` y se registra en el `AcroForm` del documento.

### Flujo interno

```
PdfService::outputPdfBytes()
        │
        ├── FPDF Output('S')                        → bytes del PDF sin firmar
        │
        └── if PADES_SIGN_ENABLED:
            PadesSignerService::sign($bytes)
                │
                ├── Lee .p12 (openssl_pkcs12_read)
                ├── Parsea xref/trailer/Catalog originales
                ├── Construye incremento con:
                │     - Objeto Sig con placeholder /Contents (16384 hex) y /ByteRange
                │     - Field /FT /Sig
                │     - AcroForm /SigFlags 3
                │     - Nuevo Catalog con /AcroForm
                │     - Nuevo xref + trailer con /Prev
                ├── Calcula /ByteRange real y lo patchea (in-place)
                ├── openssl_cms_sign de los bytes cubiertos (DER, detached, binary)
                └── Inserta el DER hex en /Contents
```

### Verificación

Los lectores PDF (Adobe Reader, Foxit, etc.) muestran el panel de firmas con estado:
- **Verde** — Firma criptográficamente válida y certificado de confianza (CA reconocida).
- **Amarillo** — Firma válida pero identidad no verificada (típico con certificados auto-firmados o CAs no instaladas).
- **Rojo** — Documento alterado tras la firma.

Verificación programática (línea de comandos):

```bash
# Extraer /ByteRange y /Contents del PDF firmado, luego:
openssl cms -verify -inform DER -in sig.der -content data.bin -binary -noverify
```

### Alcance y limitaciones

Esta implementación es una **firma PDF PKCS#7** compatible con la especificación histórica de Adobe (`adbe.pkcs7.detached`). Es válida y verificable en cualquier lector PDF conforme al spec ISO 32000-1.

No cumple estrictamente ETSI EN 319 142 (PAdES-B-B):
- **Falta el atributo firmado `SigningCertificateV2`** — `openssl_cms_sign` no lo genera nativamente.
- **Sin timestamp TSA** — no es PAdES-B-T. Para añadirlo se requiere un segundo incremental update con la respuesta de un servidor TSA sobre el hash de la firma.
- **Sin información de validación embebida (LTV)** — la firma no es validable a largo plazo tras vencer o revocar el certificado.

Si se requiere PAdES estricta, TSA o LTV, considerar:
- **SetaPDF-Signer** (comercial, del mismo autor de FPDF) — soporte nativo de PAdES-B/T/LT/LTA.
- Delegación a un servicio de firma institucional (DSS local, Firma-e, Uanataca, etc.).

### Tests

```bash
# Todos los tests
php vendor/phpunit/phpunit/phpunit

# Solo firma
php vendor/phpunit/phpunit/phpunit --filter PadesSignerServiceTest
```

`PadesSignerServiceTest` genera un `.p12` auto-firmado, firma un PDF mínimo y verifica: estructura del PDF resultante, coherencia de `/ByteRange`, presencia del OID CMS SignedData en `/Contents`, y verificación criptográfica real con `openssl_cms_verify`.

En Windows los tests requieren un `openssl.cnf` accesible; se autodetecta en rutas comunes (XAMPP, Git for Windows) o vía `OPENSSL_CONF`. En Docker/Linux no hace falta configurarlo.

---

## API REST

Todos los endpoints se acceden en `/api.php`. La autenticación se gestiona por sesión (misma cookie que la interfaz web), excepto `verify_certificate` que es público.

**CORS** habilitado para `https://escuela.funcionjudicial.gob.ec`.

### Endpoints disponibles

| Método | Acción | Descripción | Rol requerido |
|---|---|---|---|
| `GET` | `?action=me` | Usuario autenticado actual | USER |
| `GET` | `?action=search&cedula=XXXX` | Buscar registros por cédula | USER |
| `GET` | `?action=get_record&id=1` | Obtener un registro por ID | USER |
| `GET` | `?action=list_records&year=2024` | Listar registros paginados | USER |
| `GET` | `?action=get_years` | Años con registros disponibles | USER |
| `GET` | `?action=generate_pdf&cedula=XXXX` | Info previa a generar PDF | USER |
| `GET` | `?action=verify_certificate&cedula=XXXX` | Verificación pública (para QR, sin login). Devuelve `nombre` con nombre completo (nombre + apellido sin duplicar). | — |
| `GET` | `?action=get_config` | Configuración pública del sistema | — |
| `POST` | `?action=insert_record` | Crear un registro | ADMIN |
| `PUT` | `?action=update_record` | Actualizar un registro | ADMIN |
| `DELETE` | `?action=delete_record&id=1` | Soft-delete de un registro (`estado='X'`) | ADMIN |
| `POST` | `?action=import_csv` | Validar, almacenar y encolar CSV; responde `202` con `job_id` | ADMIN |
| `GET` | `?action=import_status&job_id=1` | Consultar progreso/resultado de un job | ADMIN |
| `GET` | `?action=import_status_latest` | Consultar el último job del usuario | ADMIN |
| `POST` | `?action=validate_csv` | Validar CSV sin importar | ADMIN |
| `GET` | `?action=export_csv&year=2024` | Exportar registros a CSV | ADMIN |
| `GET` | `?action=check_errors&year=2024` | Reporte de errores en datos | ADMIN |
| `GET` | `?action=error_summary&year=2024` | Resumen de errores por tipo | ADMIN |
| `GET` | `?action=get_history&limit=50` | Bitácora de operaciones | ADMIN |
| `POST` | `?action=archive_pdf` | Generar, archivar y registrar el PDF (incluye auditoría) | USER |

**Paginación:** Los listados aceptan `limit` (1–500, default 50) y `offset`.

**Importación:** es asíncrona. El cliente debe conservar el `job_id` y consultar
`import_status`; recibir `202` confirma que el archivo quedó durablemente
encolado, no que sus filas ya fueron importadas.

**Rate limiting:** `verify_certificate` admite máximo 15 solicitudes por minuto
por IP. En producción los contadores son globales mediante SQL Server.

---

## Flujo de autenticación y autorización

```
Usuario → workspace.php → (sin sesión) → Keycloak login
                                              ↓
                                       callback.php
                                              ↓
                          Intercambio código → tokens JWT
                                              ↓
                     RoleProvider::getRolesForUser(cédula, username)
                     (consulta ADM.UsuarioRol en PORTAL_APLICATIVOS_CJ)
                                              ↓
                            Sesión PHP (usuario + tokens + external_roles)
                                              ↓
                        workspace.php con datos del usuario
```

- El token de acceso se refresca automáticamente al expirar; al refrescar también se actualizan los roles desde la base externa.
- El logout en `/logout.php` dispara el Single Logout (SLO) en Keycloak.
- La cédula se normaliza (se eliminan guiones y no-dígitos) antes de consultar la base de roles, para tolerar formatos distintos entre Keycloak/LDAP y `ADM.Persona.identificacion`.

### Token de servicio (Repositorio Documental)

La acción `archive_pdf` requiere un token propio de la aplicación (flujo `client_credentials`) para llamar al Repositorio Documental. Esto requiere que el cliente de Keycloak tenga **"Service Accounts Enabled"** activado.

Antes de desplegar esta funcionalidad en una base que aún no tenga las tablas
documentales, ejecutar `scripts/20260723_documento_academico.sql`.

---

## Comandos útiles

```bash
# Ver logs de la aplicación
docker compose logs -f php-app

# Ver logs de Nginx
docker compose logs -f nginx

# Acceder al contenedor PHP
docker compose exec php-app bash

# Instalar dependencias manualmente
docker compose exec php-app composer install

# Ver logs del worker
docker compose logs -f import-worker

# Reiniciar contenedores (script de conveniencia)
bash reiniciar.sh

# Detener los servicios
docker compose down

# Sólo en desarrollo: detener y eliminar también datos locales MinIO/vendor
docker compose --profile local-infra down -v
```

---

## Seguridad

- Acceso a archivos sensibles (`.env`, `.json`, `.lock`) bloqueado en Nginx.
- Tokens CSRF en todos los formularios POST del panel administrativo.
- Escape de HTML en todas las salidas para prevenir XSS.
- Control de acceso basado en roles resueltos desde la base institucional (`RoleProvider`), no desde el token de Keycloak.
- `ErrorHandler` impide que stack traces o rutas internas lleguen al usuario final en producción.
- Rate limiting por IP global mediante SQL Server en despliegues multinodo; el modo archivo queda sólo para desarrollo de un nodo.
- `TRUSTED_PROXIES` — solo se confían las cabeceras `X-Forwarded-For` / `X-Real-IP` cuando `REMOTE_ADDR` es un proxy declarado; evita que un cliente externo resetee el rate limit falsificando esas cabeceras.
- Inspección de contenido CSV en importación y validación previa: bloquea XSS almacenado (`<script>`, `javascript:`, `onerror`) e inyección de fórmulas CSV. Archivos sospechosos disparan `SecurityAlertService` con nivel 9.
- `SecurityAlertService` — registra alertas en SQL con `SECURITY_ALERT_STORAGE=database` y en `error_log` con prefijo `[SEGURIDAD][NIVEL N]` para SIEM; el JSON local queda como modo de desarrollo.
- Sesiones PHP compartidas y bloqueadas en SQL Server, por lo que no se requiere afinidad de sesión en el balanceador.
- CSV y assets PDF privados en S3/MinIO, con comprobación SHA-256 antes de su consumo.
- Página de error 403 personalizada (`public/403.html`) con redirección al sistema; no expone rutas internas.
- Los diálogos de confirmación usan modales propios y las notificaciones usan toasts en página (no `window.alert()` / `window.confirm()` nativos), evitando que el diálogo del SO exponga la IP o URL interna del servidor.
- El visor de PDF usa PDF.js sobre `<canvas>` (sin iframe con visor nativo), eliminando los botones de descarga y "Guardar en Google Drive" del navegador. La descarga se controla exclusivamente desde el botón "Generar PDF".
- La eliminación de registros es **soft delete** (`estado = 'X'`): los datos nunca se borran físicamente, garantizando trazabilidad completa.
- Jerarquía de excepciones (`AppException` → `ValidationException`, `SystemException`, `NotFoundException`, `InvalidConfigurationException`) para distinguir errores de validación de errores de sistema sin exponer detalles técnicos al usuario.
- La variable `APP_DEBUG=false` es obligatoria en producción.
- En producción, configurar `DB_ENCRYPT=true` y `TrustServerCertificate=false` en la conexión SQL Server.

---

## Licencia

Uso interno institucional. Todos los derechos reservados.
