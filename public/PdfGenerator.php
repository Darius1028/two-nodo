<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use App\Security\SecurityContext;
use App\Service\PdfService;

if (ob_get_level() > 0) ob_end_clean();

// FIX: el original no protegía este endpoint porque no era accesible
// directamente (vivía junto al resto de la app). Ahora que public/ es el
// document root de Nginx, cualquiera podía pedir el PDF de cualquier cédula
// sin loguearse. Se aplica la misma política de acceso que workspace.php.
SecurityContext::ensureSession();
if (($_ENV['WORKSPACE_ACCESS_MODE'] ?? 'protected') === 'protected') {
    #SecurityContext::requireRole($_ENV['KEYCLOAK_ROLE_USER'] ?? 'ROLE_USER');
    SecurityContext::requireAuthentication();
} else {
    SecurityContext::requireAuthentication();
}

/*
 * Obtener cédula.
 */
$cedula = isset($_GET['cedula_query'])
    && is_string($_GET['cedula_query'])
        ? trim($_GET['cedula_query'])
        : '';

if ($cedula === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => false,
        'message' => 'Cédula no proporcionada.',
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
 * Obtener años.
 */
$startYear = isset($_GET['start'])
    && is_numeric($_GET['start'])
        ? (int) $_GET['start']
        : 2010;

$endYear = isset($_GET['end'])
    && is_numeric($_GET['end'])
        ? (int) $_GET['end']
        : (int) date('Y');

if ($startYear > $endYear) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => false,
        'message' => 'El año inicial no puede superar al año final.',
    ], JSON_UNESCAPED_UNICODE);

    exit;
}
$options = [
    'start_year' => $startYear,
    'end_year' => $endYear,

    'override_name' => !empty($_GET['name'])
        ? trim((string) $_GET['name'])
        : null,

    'override_email' => !empty($_GET['email'])
        ? trim((string) $_GET['email'])
        : null,

    'override_periodo' => !empty($_GET['periodo'])
        ? trim((string) $_GET['periodo'])
        : null,

    'extra1' => !empty($_GET['extra1'])
        ? trim((string) $_GET['extra1'])
        : null,

    'extra2' => !empty($_GET['extra2'])
        ? trim((string) $_GET['extra2'])
        : null,

    /*
     * Estos valores llegan al JSON enviado al repositorio.
     */
    'sistema' => 'SistemaRecordAcademico',
    'modulo' => 'ExpedienteAcademico',
    'requiere_firmado' => true,
    'requiere_index' => true,
];

/*
 * Reutilizar el access token del usuario autenticado. SecurityContext también
 * lo refresca cuando ha expirado y conserva la sesión actualizada.
 */
$accessToken = SecurityContext::getAccessToken();

if ($accessToken === null) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => false,
        'message' => 'La sesión no contiene un access token válido.',
    ], JSON_UNESCAPED_UNICODE);

    exit;
}
/*
 * IP de origen.
 */
$ipOrigen = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
    && is_string($_SERVER['HTTP_X_FORWARDED_FOR'])
        ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
        : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

try {
    $ipOrigen = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
        && is_string($_SERVER['HTTP_X_FORWARDED_FOR'])
            ? trim(
                explode(
                    ',',
                    $_SERVER['HTTP_X_FORWARDED_FOR']
                )[0]
            )
            : (string) (
                $_SERVER['REMOTE_ADDR']
                ?? '127.0.0.1'
            );

    $options['sistema'] = 'SistemaRecordAcademico';
    $options['modulo'] = 'ExpedienteAcademico';
    $options['requiere_firmado'] = true;
    $options['requiere_index'] = true;

    $pdfService = new PdfService();

    $resultado = $pdfService->generateRecord(
        cedula: $cedula,
        accessToken: $accessToken,
        ipOrigen: $ipOrigen,
        options: $options
    );

    $contenidoPdf = $resultado['contenido_pdf'] ?? null;

    $nombreArchivo = basename(
        (string) (
            $resultado['nombre_archivo']
            ?? 'record_academico.pdf'
        )
    );

    if (
        !is_string($contenidoPdf)
        || $contenidoPdf === ''
    ) {
        throw new RuntimeException(
            'El servicio no devolvió el contenido del PDF.'
        );
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: inline; filename="' .
        $nombreArchivo .
        '"'
    );
    header('Content-Length: ' . strlen($contenidoPdf));
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    echo $contenidoPdf;
    exit;
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    error_log(
        'Error generando o subiendo PDF: '
        . $e->getMessage()
    );

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');

    $appDebug = filter_var(
        $_ENV['APP_DEBUG'] ?? false,
        FILTER_VALIDATE_BOOL
    );

    echo $appDebug
        ? 'Error generando el PDF: ' . $e->getMessage()
        : 'No se pudo generar el documento PDF.';

    exit;
}