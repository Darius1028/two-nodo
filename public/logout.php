<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
use App\Core\ErrorHandler;
use App\Security\SecurityContext;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Manejo global de errores -- ver src/Core/ErrorHandler.php
ErrorHandler::register();

SecurityContext::logout();