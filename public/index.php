<?php
// public/index.php
require_once __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
\App\Core\ErrorHandler::register();

use App\Security\SecurityContext;
SecurityContext::ensureSession();

$user = SecurityContext::getCurrentUser();

if ($user === null) {
    // Sin sesión → mandar a login
    SecurityContext::redirectToKeycloak();
    exit;
}

// Ya autenticado → elegir destino según roles
$adminRole = $_ENV['KEYCLOAK_ROLE_ADMIN'] ?? 'ADMIN_ACADEMICO';
$userRole  = $_ENV['KEYCLOAK_ROLE_USER']  ?? 'SECRE_ACADEMICO';

$hasAdmin = SecurityContext::hasRole($adminRole);
$hasUser  = SecurityContext::hasRole($userRole);

if ($hasAdmin) {
    header('Location: AdminPanel.php');
} elseif ($hasUser) {
    header('Location: workspace.php');
} else {
    // Autenticado pero sin roles de la app → error 403 via ErrorHandler
    throw new \App\Exception\AppException(
        "El usuario '{$user['preferred_username']}' no tiene ningún rol asignado para esta aplicación."
    );
}