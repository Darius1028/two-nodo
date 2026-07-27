# Sistema de Registro Académico

Sistema web para la gestión, consulta y certificación de registros académicos institucionales. Permite importar, administrar y exportar registros de estudiantes, generar PDFs certificados con QR de verificación, y expone una API REST completa. La autenticación se delega a Keycloak mediante OpenID Connect; la **autorización por roles se resuelve desde una base de datos institucional externa** (independiente de Keycloak).

> **Estado del proyecto:** activo. Última sesión de cambios: soft-delete de registros, visor PDF con PDF.js, caché local de QR, descarga directa de PDFs y mejoras al script de reinicio.

---

## Características principales

- **Gestión de registros** — CRUD completo de registros académicos por cédula, materia, período y año. La eliminación es **soft delete**: los registros pasan a `estado = 'X'` y son invisibles en consultas, pero permanecen en la base para auditoría.
- **Importación CSV en streaming** — Carga masiva sin cargar el archivo en RAM; detecta separador y encoding automáticamente. Soporta archivos de hasta 128 MB sin crashear el servidor.
- **Exportación CSV** — Descarga de registros por año o por cédula (excluye registros con `estado = 'X'`).
- **Validación previa de CSV** — Endpoint `validate_csv` que reporta errores de columnas antes de importar.
- **Generación de PDFs** — Certificados con membrete, firma y código QR verificable. Al pulsar "Generar PDF" el archivo se descarga automáticamente y se archiva en el Repositorio Documental en un solo paso.
- **Visor de PDF integrado** — Renderizado en `<canvas>` con PDF.js (alojado localmente, sin CDN); evita que el visor nativo del navegador exponga botones de descarga o envío a Google Drive.
- **QR de verificación con caché local** — La imagen QR se genera una vez por cédula y se almacena en `var/cache/qr/` (TTL 30 días). Peticiones posteriores no hacen llamadas de red al servidor de QR externo.
- **Generación y archivo documental en un solo paso** — Al generar el PDF se envía al servicio institucional, se guarda su UUID y se audita la operación.
- **Verificación pública de certificados** — Endpoint sin login para validar QR desde portales externos, con rate limiting por IP.
- **API REST JSON** — Endpoints para búsqueda, CRUD, importación CSV, generación y archivo de PDFs.
- **Panel administrativo** — Interfaz HTML para gestión completa con control de acceso por roles.
- **Auditoría estilo Envers** — Cada INSERT/UPDATE/DELETE (individual y masivo) genera un snapshot en `AcademicoAUD.RecordAcademico_AUD` vinculado a una revisión en `AUD.REVINFO`. Los registros AUD nunca se borran al eliminar el registro principal.
- **Validación de datos** — Detección de emails corruptos, cédulas inválidas y calificaciones fuera de rango.
- **Autenticación OAuth2/OIDC** — Integración con Keycloak, soporte para Single Logout y refresh de tokens.
- **Autorización desacoplada** — Los roles **no** provienen del token de Keycloak; se consultan por cédula o username en la base institucional `PORTAL_APLICATIVOS_CJ` (esquema `ADM`).
- **Manejo global de errores** — `ErrorHandler` registra como excepción todo error no controlado; el usuario nunca ve stack traces en producción.
- **Página de error 403** — Página personalizada con redirección al sistema cuando se intenta acceder directamente a recursos protegidos.
- **Rate limiting** — Protección por IP en endpoints públicos mediante archivo con file locking (sin Redis ni Memcached).

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
| Visor PDF | PDF.js (alojado localmente) |
| Servidor web | Nginx |
| Runtime | PHP-FPM |
| Contenedores | Docker / Docker Compose |

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
│   │   └── RequestContext.php         # IP real del cliente (X-Real-IP → X-Forwarded-For → REMOTE_ADDR)
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
│   │   └── RateLimiter.php            # Rate limiting por IP (archivo)
│   └── Service/
│       ├── AcademicRecordAuditService.php  # Escribe en AUD via DBAL (individual)
│       ├── CsvService.php                  # Importación/exportación CSV en streaming
│       ├── PdfService.php                  # Generación y archivo de PDFs
│       ├── RepositorioDocumentalService.php # Cliente del Repositorio Documental
│       ├── ErrorFinder.php                 # Detección de errores en datos
│       ├── ConfigService.php               # Lectura de config.json y env
│       └── KeycloakTokenService.php        # Token de servicio (client_credentials)
├── templates/
│   └── includes/header.php    # Header HTML compartido
├── config/
│   ├── doctrine.php           # Configuración de Doctrine
│   └── config.json            # Configuración de la aplicación
├── database/
│   └── create_schema.sql      # Script de creación de schemas y tablas (idempotente)
├── bin/
│   └── console.php            # Consola de comandos Doctrine
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

### 2. Configuración de la aplicación

El archivo `config/config.json` controla opciones específicas de la aplicación:

```json
{
  "qr_enabled": true,
  "qr_base_url": "https://tu-dominio.com/verificar-record/",
  "letterhead_image": "pdfjs/letterhead.png",
  "signature_image": "pdfjs/signature.png",
  "column_schema": [ ... ]
}
```

- **`qr_enabled`** — Activa el código QR en los PDFs generados.
- **`qr_base_url`** — URL base de fallback para los QR. Si está definida `QR_BASE_URL` en el entorno, esta es ignorada.
- **`letterhead_image`** — Ruta a la imagen del membrete (relativa a `public/`).
- **`signature_image`** — Ruta a la imagen de la firma (relativa a `public/`).
- **`column_schema`** — Define las columnas disponibles para importación CSV y visualización en PDF. Cada columna tiene `key`, `label`, `width` y `visible`.

### 3. Assets

Coloca los archivos de membrete y firma en `public/assets/`:

```
public/assets/letterhead.png
public/assets/signature.png
```

---

## Instalación y despliegue

### Con Docker Compose (recomendado)

```bash
# 1. Clonar el repositorio
git clone <url-del-repositorio>
cd sistema-records

# 2. Configurar variables de entorno
cp .env.example .env
# Editar .env con los valores de tu entorno

# 3. Construir e iniciar los contenedores
docker-compose up -d --build

# 4. Verificar que los servicios estén corriendo
docker-compose ps
```

La aplicación estará disponible en `http://localhost:8010`.

> El puerto del host es **8010** (no 80) para evitar conflictos con otros servicios locales. El contenedor Nginx escucha en el puerto 80 internamente.

### Servicios Docker

| Servicio | Imagen | Puerto           | Descripción |
|---|---|------------------|---|
| `nginx` | nginx:alpine | `8010:80`        | Servidor web / proxy inverso |
| `php-app` | (Dockerfile local) | `9000` (interno) | PHP 8.2-FPM con driver SQL Server |

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

`RequestContext::getClientIp()` aplica la siguiente prioridad:
1. `X-Forwarded-For` (balanceador/proxy corporativo externo)
2. `HTTP_X_REAL_IP` (seteado explícitamente por nginx vía fastcgi)
3. `REMOTE_ADDR` (fallback directo)

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
| `GET` | `?action=verify_certificate&cedula=XXXX` | Verificación pública (para QR, sin login) | — |
| `GET` | `?action=get_config` | Configuración pública del sistema | — |
| `POST` | `?action=insert_record` | Crear un registro | ADMIN |
| `PUT` | `?action=update_record` | Actualizar un registro | ADMIN |
| `DELETE` | `?action=delete_record&id=1` | Soft-delete de un registro (`estado='X'`) | ADMIN |
| `POST` | `?action=import_csv` | Importar registros desde CSV | ADMIN |
| `POST` | `?action=validate_csv` | Validar CSV sin importar | ADMIN |
| `GET` | `?action=export_csv&year=2024` | Exportar registros a CSV | ADMIN |
| `GET` | `?action=check_errors&year=2024` | Reporte de errores en datos | ADMIN |
| `GET` | `?action=error_summary&year=2024` | Resumen de errores por tipo | ADMIN |
| `GET` | `?action=get_history&limit=50` | Bitácora de operaciones | ADMIN |
| `POST` | `?action=archive_pdf` | Generar, archivar y registrar el PDF (incluye auditoría) | USER |

**Paginación:** Los listados aceptan `limit` (1–500, default 50) y `offset`.

**Rate limiting:** `verify_certificate` admite máximo 15 solicitudes por minuto por IP.

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
docker-compose logs -f php-app

# Ver logs de Nginx
docker-compose logs -f nginx

# Acceder al contenedor PHP
docker-compose exec php-app bash

# Instalar dependencias manualmente
docker-compose exec php-app composer install

# Ver historial de operaciones
docker-compose exec php-app cat var/log/historial.json

# Reiniciar contenedores (script de conveniencia)
bash reiniciar.sh

# Detener los servicios
docker-compose down

# Detener y eliminar volúmenes
docker-compose down -v
```

---

## Seguridad

- Acceso a archivos sensibles (`.env`, `.json`, `.lock`) bloqueado en Nginx.
- Tokens CSRF en todos los formularios POST del panel administrativo.
- Escape de HTML en todas las salidas para prevenir XSS.
- Control de acceso basado en roles resueltos desde la base institucional (`RoleProvider`), no desde el token de Keycloak.
- `ErrorHandler` impide que stack traces o rutas internas lleguen al usuario final en producción.
- Rate limiting por IP en endpoints públicos (sin Redis: archivo con file locking).
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
