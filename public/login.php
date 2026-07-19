<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use App\Security\SecurityContext;

// Fuerza un intento de login contra Keycloak, sin importar
// WORKSPACE_ACCESS_MODE ni si queda alguna sesión parcial. Es el destino
// del botón "Volver a intentar" en la pantalla de login fallido.
SecurityContext::ensureSession();
SecurityContext::redirectToKeycloak();
