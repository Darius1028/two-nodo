<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\EntityManagerProvider;
use App\Storage\ObjectStorageFactory;
use App\Storage\StorageConfig;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

header('Content-Type: application/json; charset=utf-8');
$environment = strtolower((string) ($_ENV['APP_ENV'] ?? (getenv('APP_ENV') ?: 'prod')));
$developmentMode = in_array($environment, ['dev', 'development', 'local', 'test'], true);
$checks = [
    'database' => false,
    'schema' => false,
    'objectStorage' => false,
    'multinodeConfig' => $developmentMode,
];

if (!$developmentMode) {
    try {
        $sessionHandler = strtolower((string) ($_ENV['SESSION_HANDLER'] ?? (getenv('SESSION_HANDLER') ?: 'files')));
        $rateLimitStore = strtolower((string) ($_ENV['RATE_LIMIT_STORE'] ?? (getenv('RATE_LIMIT_STORE') ?: 'file')));
        $checks['multinodeConfig'] = StorageConfig::driver() === 's3'
            && $sessionHandler === 'database'
            && $rateLimitStore === 'database'
            && strtolower((string) ($_ENV['CONFIG_STORAGE'] ?? (getenv('CONFIG_STORAGE') ?: 'file'))) === 'database'
            && strtolower((string) ($_ENV['HISTORY_STORAGE'] ?? (getenv('HISTORY_STORAGE') ?: 'file'))) === 'database'
            && strtolower((string) ($_ENV['SECURITY_ALERT_STORAGE'] ?? (getenv('SECURITY_ALERT_STORAGE') ?: 'file'))) === 'database';
    } catch (\Throwable $e) {
        error_log('[health] Configuración multinodo inválida: ' . $e->getMessage());
    }
}

try {
    EntityManagerProvider::get()->getConnection()->fetchOne('SELECT 1');
    $checks['database'] = true;

    $schemaReady = (int) EntityManagerProvider::get()->getConnection()->fetchOne(
        "SELECT CASE WHEN
            COL_LENGTH(N'Academico.ImportJob', N'objectKey') IS NOT NULL
            AND COL_LENGTH(N'Academico.ImportJob', N'workerId') IS NOT NULL
            AND OBJECT_ID(N'Academico.ConfiguracionAplicacion', N'U') IS NOT NULL
            AND OBJECT_ID(N'Academico.HistorialAplicacion', N'U') IS NOT NULL
            AND OBJECT_ID(N'Academico.AlertaSeguridad', N'U') IS NOT NULL
            AND OBJECT_ID(N'Academico.Sesion', N'U') IS NOT NULL
            AND OBJECT_ID(N'Academico.RateLimit', N'U') IS NOT NULL
        THEN 1 ELSE 0 END"
    );
    $checks['schema'] = $schemaReady === 1;
} catch (\Throwable $e) {
    error_log('[health] Base de datos no disponible: ' . $e->getMessage());
}

try {
    $storage = ObjectStorageFactory::create();
    $storage->checkBucket(StorageConfig::importBucket());
    $storage->checkBucket(StorageConfig::assetBucket());
    $checks['objectStorage'] = true;
} catch (\Throwable $e) {
    error_log('[health] Almacenamiento de objetos no disponible: ' . $e->getMessage());
}

$ready = !in_array(false, $checks, true);
http_response_code($ready ? 200 : 503);
echo json_encode(['status' => $ready ? 'ready' : 'not-ready', 'checks' => $checks], JSON_THROW_ON_ERROR);
