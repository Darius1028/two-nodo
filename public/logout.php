<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Manejo global de errores -- ver src/Core/ErrorHandler.php
\App\Core\ErrorHandler::register();

use App\Security\SecurityContext;
SecurityContext::logout();