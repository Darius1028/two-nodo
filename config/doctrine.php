<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use App\Core\AuditListener;
use App\Doctrine\Type\SqlServerDateTimeType;
use Doctrine\DBAL\Types\Type;

// 1. Cargar variables de entorno
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

if (!Type::hasType(SqlServerDateTimeType::NAME)) {
    Type::addType(
        SqlServerDateTimeType::NAME,
        SqlServerDateTimeType::class
    );
}

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
        'Encrypt'                => 'no',   // <- string 'no', no false
        'TrustServerCertificate' => 'yes',  // <- string 'yes', no true
        'LoginTimeout'           => 30,
    ],
];

$connection = DriverManager::getConnection($connectionParams, $ormConfig);

// Doctrine serializa DATETIME2 como Y-m-d H:i:s.u. Se fija el formato de
// la sesión para que SQL Server no lo interprete según el idioma del login.
$connection->executeStatement('SET DATEFORMAT ymd');

$entityManager = new EntityManager($connection, $ormConfig);

// 4. Auditoría institucional (listener propio, ver AuditListener.php)
$auditListener = new AuditListener();

$entityManager->getEventManager()->addEventListener(
    $auditListener->getSubscribedEvents(),
    $auditListener
);

return $entityManager;