<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use App\Core\AuditListener;

// 1. Cargar variables de entorno
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// 2. Configuración ORM
$isDevMode = ($_ENV['APP_ENV'] ?? 'prod') !== 'prod';
$paths = [__DIR__ . '/../src/Entity'];
$ormConfig = ORMSetup::createAttributeMetadataConfiguration($paths, $isDevMode);

// Cache en producción
if (!$isDevMode) {
    $cacheDir = __DIR__ . '/../var/cache/doctrine';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0775, true);
    }
}

// 3. Conexión SQL Server
$serverName = !empty($_ENV['DB_INSTANCE'])
    ? "{$_ENV['DB_HOST']}\\{$_ENV['DB_INSTANCE']},{$_ENV['DB_PORT']}"
    : "{$_ENV['DB_HOST']},{$_ENV['DB_PORT']}";

$connectionParams = [
    'driver'   => 'pdo_sqlsrv',
    'host'     => $serverName,
    'dbname'   => $_ENV['DB_NAME'] ?? 'PORTAL_APLICATIVOS_CJ',
    'user'     => $_ENV['DB_USER'] ?? 'sa',
    'password' => $_ENV['DB_PASS'] ?? '',
    'charset'  => 'UTF-8',
    'driverOptions' => [
        'Encrypt'                => false,
        'TrustServerCertificate' => true,
        'LoginTimeout'           => 30,
        'CharacterSet'           => 'UTF-8',
    ],
];

$connection = DriverManager::getConnection($connectionParams, $ormConfig);
$entityManager = new EntityManager($connection, $ormConfig);

// 4. Auditoría (listener propio, ver src/Core/AuditListener.php)
$usernameResolver = static function (): string {
    // No se inicia sesión si no existe (ej. corriendo bin/console.php por CLI);
    // en ese caso se audita como 'system'.
    if (session_status() === PHP_SESSION_NONE) {
        return 'system';
    }
    return $_SESSION['keycloak_user']['preferred_username'] ?? 'system';
};

$auditListener = new AuditListener($usernameResolver);
$entityManager->getEventManager()->addEventListener(
    $auditListener->getSubscribedEvents(),
    $auditListener
);

return $entityManager;