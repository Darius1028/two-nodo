<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Manejo global de errores -- ver src/Core/ErrorHandler.php
\App\Core\ErrorHandler::register();

use App\Security\SecurityContext;

// Fuerza un intento de login contra Keycloak, sin importar
// WORKSPACE_ACCESS_MODE ni si queda alguna sesión parcial. Es el destino
// del botón "Volver a intentar" en la pantalla de login fallido.
SecurityContext::ensureSession();

// FIX: si el canje del código ya se completó bien y el usuario YA tiene
// sesión (por ejemplo, "Volver a intentar" quedó apuntando acá después de
// un login exitoso), NO hay que volver a mandarlo a Keycloak -- eso es
// justamente lo que generaba el loop infinito login.php -> Keycloak ->
// callback.php -> login.php -> Keycloak -> ... (con el usuario ya con
// sesión SSO activa, Keycloak reautentica en silencio y nunca corta).
if (SecurityContext::getCurrentUser() !== null) {
    header('Location: workspace.php');
    exit;
}

SecurityContext::redirectToKeycloak();