# Sistema de Registro Académico

Sistema web para la gestión, consulta y certificación de registros académicos institucionales. Permite importar, administrar y exportar registros de estudiantes, generar PDFs certificados con QR de verificación, y expone una API REST completa. La autenticación se delega a Keycloak mediante OpenID Connect; la **autorización por roles se resuelve desde una base de datos institucional externa** (independiente de Keycloak).

---

## Características principales

- **Gestión de registros** — CRUD completo de registros académicos por cédula, materia, período y año.
- **Importación/Exportación CSV** — Carga masiva con detección automática de separadores y mapeo flexible de columnas.
- **Validación previa de CSV** — Endpoint `validate_csv` que reporta errores antes de importar.
- **Generación de PDFs** — Certificados con membrete, firma y código QR verificable.
- **Archivo en Repositorio Documental** — Integración con el servicio institucional para archivar PDFs con firma digital.
- **Verificación pública de certificados** — Endpoint sin login para validar QR desde portales externos, con rate limiting por IP.
- **API REST JSON** — Endpoints para búsqueda, CRUD, importación CSV, generación y archivo de PDFs.
- **Panel administrativo** — Interfaz HTML para gestión completa con control de acceso por roles.
- **Auditoría automática** — Registro de todos los cambios (INSERT/UPDATE/DELETE) con usuario y timestamp.
- **Validación de datos** — Detección de emails corruptos, cédulas inválidas y calificaciones fuera de rango.
- **Autenticación OAuth2/OIDC** — Integración con Keycloak, soporte para Single Logout y refresh de tokens.
- **Autorización desacoplada** — Los roles **no** provienen del token de Keycloak; se consultan por cédula o username en la base institucional `PORTAL_APLICATIVOS_CJ` (esquema `ADM`).
- **Manejo global de errores** — `ErrorHandler` registra como excepción todo error no controlado; el usuario nunca ve stack traces en producción.
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
│   ├── diagnostico.php        # Diagnóstico de sistema (dev)
│   └── assets/                # Imágenes (membrete, firma)
├── src/
│   ├── Core/
│   │   ├── EntityManagerProvider.php  # Bootstrap de Doctrine
│   │   ├── AuditListener.php          # Listener de auditoría
│   │   ├── ErrorHandler.php           # Manejador global de excepciones
│   │   └── RequestContext.php         # IP real del cliente (proxies)
│   ├── Entity/                # Entidades Doctrine (AcademicRecord, Audit)
│   ├── Security/
│   │   ├── KeycloakClient.php         # Cliente OIDC
│   │   ├── SecurityContext.php        # Sesión, callback, logout, refresh
│   │   ├── RoleProvider.php           # Roles desde base institucional externa
│   │   └── RateLimiter.php            # Rate limiting por IP (archivo)
│   └── Service/
│       ├── CsvService.php             # Importación/exportación CSV
│       ├── PdfService.php             # Generación y archivo de PDFs
│       ├── ErrorFinder.php            # Detección de errores en datos
│       ├── ConfigService.php          # Lectura de config.json
│       └── KeycloakTokenService.php   # Token de servicio (client_credentials)
├── templates/
│   └── includes/header.php    # Header HTML compartido
├── config/
│   ├── doctrine.php           # Configuración de Doctrine
│   └── config.json            # Configuración de la aplicación
├── bin/
│   └── console.php            # Consola de comandos Doctrine
├── docker/
│   ├── Dockerfile             # PHP 8.2-FPM con driver SQL Server
│   └── nginx.conf             # Configuración de Nginx
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
| `EXTERNAL_ROLES_APP_ALIAS` | Alias de esta app en `ADM.Aplicativo` | `RECORD_ACADEMICO` |

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
  "letterhead_image": "assets/letterhead.png",
  "signature_image": "assets/signature.png",
  "column_schema": [ ... ]
}
```

- **`qr_enabled`** — Activa el código QR en los PDFs generados.
- **`qr_base_url`** — URL base para los QR de verificación. Se concatena la cédula y es la misma URL que apunta al endpoint público `verify_certificate`.
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

La aplicación estará disponible en `http://localhost`.

### Servicios Docker

| Servicio | Imagen | Puerto | Descripción |
|---|---|---|---|
| `nginx` | nginx:alpine | `80` | Servidor web / proxy inverso |
| `php-app` | (Dockerfile local) | `9000` (interno) | PHP 8.2-FPM con driver SQL Server |

### Base de datos

El sistema utiliza **Doctrine Migrations**. Para crear o actualizar el esquema:

```bash
# Ejecutar migraciones pendientes
docker-compose exec php-app vendor/bin/doctrine-migrations migrate

# Ver estado de migraciones
docker-compose exec php-app vendor/bin/doctrine-migrations status
```

Las tablas que gestiona el sistema son:

- `academic_records` — Registros académicos principales.
- `academic_records_audit` — Log de auditoría de cambios.

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
| `DELETE` | `?action=delete_record&id=1` | Eliminar un registro | ADMIN |
| `POST` | `?action=import_csv` | Importar registros desde CSV | ADMIN |
| `POST` | `?action=validate_csv` | Validar CSV sin importar | ADMIN |
| `GET` | `?action=export_csv&year=2024` | Exportar registros a CSV | ADMIN |
| `GET` | `?action=check_errors&year=2024` | Reporte de errores en datos | ADMIN |
| `GET` | `?action=error_summary&year=2024` | Resumen de errores por tipo | ADMIN |
| `GET` | `?action=get_history&limit=50` | Bitácora de operaciones | ADMIN |
| `POST` | `?action=archive_pdf` | Generar PDF y archivarlo en Repositorio Documental | USER |

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
- La variable `APP_DEBUG=false` es obligatoria en producción.
- En producción, configurar `DB_ENCRYPT=true` y `TrustServerCertificate=false` en la conexión SQL Server.

---

## Licencia

Uso interno institucional. Todos los derechos reservados.