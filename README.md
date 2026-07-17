# Sistema de Registro Académico

Sistema web para la gestión, consulta y certificación de registros académicos institucionales. Permite importar, administrar y exportar registros de estudiantes, generar PDFs certificados con QR de verificación, y expone una API REST completa. La autenticación se delega a Keycloak mediante OpenID Connect.

---

## Características principales

- **Gestión de registros** — CRUD completo de registros académicos por cédula, materia, período y año.
- **Importación/Exportación CSV** — Carga masiva con detección automática de separadores y mapeo flexible de columnas.
- **Generación de PDFs** — Certificados con membrete, firma y código QR verificable.
- **API REST JSON** — Endpoints para búsqueda, CRUD, importación CSV y generación de PDFs.
- **Panel administrativo** — Interfaz HTML para gestión completa con control de acceso por roles.
- **Auditoría automática** — Registro de todos los cambios (INSERT/UPDATE/DELETE) con usuario y timestamp.
- **Validación de datos** — Detección de emails corruptos, cédulas inválidas y calificaciones fuera de rango.
- **Autenticación OAuth2/OIDC** — Integración con Keycloak, soporte para Single Logout y refresh de tokens.

---

## Stack tecnológico

| Componente | Tecnología |
|---|---|
| Lenguaje | PHP 8.2+ |
| ORM | Doctrine ORM 2.x / DBAL 3.x |
| Base de datos | Microsoft SQL Server |
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
│   ├── logout.php             # Single Logout
│   └── assets/                # Imágenes (membrete, firma)
├── src/
│   ├── Core/                  # EntityManager y AuditListener
│   ├── Entity/                # Entidades Doctrine (AcademicRecord, Audit)
│   ├── Security/              # Sesión y cliente Keycloak
│   └── Service/               # CSV, PDF, Config y ErrorFinder
├── config/
│   ├── doctrine.php           # Configuración de Doctrine
│   └── config.json            # Configuración de la aplicación
├── docker/
│   ├── Dockerfile             # PHP 8.2-FPM con driver SQL Server
│   └── nginx.conf             # Configuración de Nginx
├── docker-compose.yml
├── composer.json
├── .env.example
└── var/                       # Cache y logs (generado en instalación)
    ├── cache/
    └── log/
```

---

## Requisitos previos

- Docker y Docker Compose instalados.
- Acceso de red a una instancia de **Microsoft SQL Server** (2016 o superior).
- Servidor **Keycloak** configurado con un realm y client para esta aplicación.

---

## Configuración

### 1. Variables de entorno

Copia el archivo de ejemplo y edítalo con tus valores:

```bash
cp .env.example .env
```

| Variable | Descripción | Ejemplo |
|---|---|---|
| `APP_ENV` | Entorno (`dev` o `prod`) | `prod` |
| `APP_DEBUG` | Mostrar errores (`true`/`false`) | `false` |
| `DB_HOST` | Host de SQL Server | `10.1.27.25` |
| `DB_PORT` | Puerto de SQL Server | `1433` |
| `DB_INSTANCE` | Instancia SQL Server | `SQLEXPRESS` |
| `DB_NAME` | Nombre de la base de datos | `academic_records` |
| `DB_USER` | Usuario de la base de datos | `sa` |
| `DB_PASS` | Contraseña | `secret` |
| `KEYCLOAK_SERVER_URL` | URL base de Keycloak | `https://auth.ejemplo.com` |
| `KEYCLOAK_REALM` | Nombre del realm | `academico` |
| `KEYCLOAK_CLIENT_ID` | Client ID configurado en Keycloak | `record-academico-php` |
| `KEYCLOAK_REDIRECT_URI` | URL de callback tras login | `https://app.ejemplo.com/callback.php` |
| `KEYCLOAK_ROLE_ADMIN` | Rol Keycloak con acceso de administrador | `ROLE_ADMIN` |
| `KEYCLOAK_ROLE_USER` | Rol Keycloak con acceso de usuario | `ROLE_USER` |
| `WORKSPACE_ACCESS_MODE` | `protected` (requiere login) o `public` | `protected` |

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
- **`qr_base_url`** — URL base para los QR de verificación (se concatena la cédula).
- **`letterhead_image`** — Ruta a la imagen del membrete (relativa a `public/`).
- **`signature_image`** — Ruta a la imagen de la firma (relativa a `public/`).
- **`column_schema`** — Define las columnas disponibles para importación CSV y visualización en PDF.

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

Todos los endpoints se acceden en `/api.php`. La autenticación se gestiona por sesión (misma cookie que la interfaz web).

### Endpoints disponibles

| Método | Acción | Descripción | Rol requerido |
|---|---|---|---|
| `GET` | `?action=me` | Usuario autenticado actual | USER |
| `GET` | `?action=search&cedula=XXXX` | Buscar registros por cédula | USER |
| `GET` | `?action=list_records&year=2024` | Listar registros paginados | USER |
| `GET` | `?action=generate_pdf&cedula=XXXX` | Vista previa de PDF | USER |
| `POST` | `?action=insert_record` | Crear un registro | ADMIN |
| `PUT` | `?action=update_record` | Actualizar un registro | ADMIN |
| `DELETE` | `?action=delete_record&id=1` | Eliminar un registro | ADMIN |
| `POST` | `?action=import_csv` | Importar registros desde CSV | ADMIN |
| `GET` | `?action=export_csv&year=2024` | Exportar registros a CSV | ADMIN |
| `GET` | `?action=check_errors&year=2024` | Reporte de errores en datos | ADMIN |

**Paginación:** Los listados aceptan `limit` (1-500, default 50) y `offset`.

---

## Flujo de autenticación

```
Usuario → workspace.php → (sin sesión) → Keycloak login
                                              ↓
                                       callback.php
                                              ↓
                          Intercambio código → tokens JWT
                                              ↓
                             Sesión PHP (usuario + tokens)
                                              ↓
                        workspace.php con datos del usuario
```

El token de acceso se refresca automáticamente al expirar. El logout en `/logout.php` dispara el Single Logout (SLO) en Keycloak.

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
- Control de acceso basado en roles de Keycloak (`ROLE_ADMIN`, `ROLE_USER`).
- La variable `APP_DEBUG=false` es obligatoria en producción para no exponer errores.
- En producción, configurar `TrustServerCertificate=false` y `Encrypt=true` en la conexión SQL Server.

---

## Licencia

Uso interno institucional. Todos los derechos reservados.