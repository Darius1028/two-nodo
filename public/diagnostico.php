<?php
declare(strict_types=1);

/**
 * Script de diagnóstico -- prueba cada conexión por separado (DB principal,
 * DB de roles externa, Keycloak) sin pasar por el flujo completo de login.
 *
 * USO: http://localhost/diagnostico.php  (o la ruta que corresponda en tu
 * vhost de XAMPP/Laragon)
 *
 * IMPORTANTE: este archivo es solo para desarrollo. Borralo o protegelo con
 * SecurityContext::requireRole() antes de acercarte a producción -- expone
 * si las conexiones funcionan o no, información que no debería ser pública.
 */

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

header('Content-Type: text/plain; charset=utf-8');

function check(string $label, callable $fn): void
{
    echo str_pad("[$label]", 35);
    try {
        $result = $fn();
        echo "OK";
        if ($result !== null) {
            echo "  -> $result";
        }
        echo "\n";
    } catch (\Throwable $e) {
        echo "FALLÓ\n";
        echo "    Clase:   " . get_class($e) . "\n";
        echo "    Mensaje: " . $e->getMessage() . "\n\n";
    }
}

echo "=== Diagnóstico de conexiones — " . date('Y-m-d H:i:s') . " ===\n\n";

// 1. Variables de entorno mínimas presentes
check('Variables .env cargadas', function () {
    $required = [
        'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
        'KEYCLOAK_SERVER_URL', 'KEYCLOAK_REALM', 'KEYCLOAK_CLIENT_ID', 'KEYCLOAK_CLIENT_SECRET',
        'EXTERNAL_ROLES_DB_HOST', 'EXTERNAL_ROLES_DB_NAME', 'EXTERNAL_ROLES_DB_USER',
    ];
    $missing = array_filter($required, static fn($v) => !isset($_ENV[$v]) || $_ENV[$v] === '');
    if (!empty($missing)) {
        throw new \RuntimeException('Faltan: ' . implode(', ', $missing));
    }
    return count($required) . ' variables presentes';
});

// 2. Extensión pdo_sqlsrv cargada
check('Extensión pdo_sqlsrv', function () {
    if (!extension_loaded('pdo_sqlsrv')) {
        throw new \RuntimeException('No está cargada. Revisá php.ini y reiniciá Apache/Nginx.');
    }
    return phpversion('pdo_sqlsrv');
});

// 3. Conexión a la base principal (academic_records)
check('DB principal (academic_records)', function () {
    $em = App\Core\EntityManagerProvider::get();
    $result = $em->getConnection()->fetchOne('SELECT 1');
    return "conectado, SELECT 1 = $result";
});

// 4. Conexión a la base externa de roles
check('DB externa de roles', function () {
    $ref = new ReflectionClass(App\Security\RoleProvider::class);
    $method = $ref->getMethod('getConnection');
    $method->setAccessible(true);
    $conn = $method->invoke(null);
    $result = $conn->fetchOne('SELECT 1');
    return "conectado, SELECT 1 = $result";
});

// 5. Discovery de Keycloak (sin hacer login todavía)
check('Keycloak discovery document', function () {
    $url = rtrim($_ENV['KEYCLOAK_SERVER_URL'], '/')
        . '/realms/' . $_ENV['KEYCLOAK_REALM']
        . '/.well-known/openid-configuration';

    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $ctx);

    if ($response === false) {
        throw new \RuntimeException("No se pudo conectar a $url");
    }
    $data = json_decode($response, true);
    if (!isset($data['authorization_endpoint'])) {
        throw new \RuntimeException("Respuesta inesperada desde $url: " . substr($response, 0, 200));
    }
    return "issuer = {$data['issuer']}";
});

// 6. Cliente OIDC se puede instanciar (valida secret/config, sin redirigir)
check('KeycloakClient::get()', function () {
    $oidc = App\Security\KeycloakClient::get();
    return 'instancia creada correctamente (secret configurado)';
});

echo "\n=== Fin del diagnóstico ===\n";
