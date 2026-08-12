<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
use App\Core\ErrorHandler;
use App\Security\SecurityContext;
use App\Service\PdfService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Manejo global de errores -- ver src/Core/ErrorHandler.php
ErrorHandler::register();


if (ob_get_level() > 0) {
    ob_end_clean();
}

// Vista previa únicamente -- NO archiva nada en el Repositorio Documental.
// Para eso está api.php?action=archive_pdf, una acción separada y explícita.
//
// FIX: este endpoint no estaba protegido porque antes no era accesible
// directamente (vivía junto al resto de la app). Ahora que public/ es el
// document root de Nginx, cualquiera podía pedir el PDF de cualquier cédula
// sin loguearse. Se aplica la misma política de acceso que workspace.php.
SecurityContext::ensureSession();
if (($_ENV['WORKSPACE_ACCESS_MODE'] ?? 'protected') === 'protected') {
    SecurityContext::requireRole($_ENV['KEYCLOAK_ROLE_USER'] ?? 'SECRE_ACADEMICO');
}

$cedula = isset($_GET['cedula_query']) && is_string($_GET['cedula_query']) ? trim($_GET['cedula_query']) : '';
if ($cedula === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Error: Cédula no proporcionada.');
}

$startYear = isset($_GET['start']) && is_numeric($_GET['start']) ? (int)$_GET['start'] : 2010;
$endYear = isset($_GET['end']) && is_numeric($_GET['end']) ? (int)$_GET['end'] : (int)date('Y');

$options = [
    'start_year' => $startYear,
    'end_year' => $endYear,
    'override_name' => !empty($_GET['name']) ? trim($_GET['name']) : null,
    'override_email' => !empty($_GET['email']) ? trim($_GET['email']) : null,
    'override_periodo' => !empty($_GET['periodo']) ? trim($_GET['periodo']) : null,
    'extra1' => !empty($_GET['extra1']) ? trim($_GET['extra1']) : null,
    'extra2' => !empty($_GET['extra2']) ? trim($_GET['extra2']) : null,
];

try {
    header('Content-Type: application/pdf');
    $pdfService = new PdfService();
    $pdfService->generateRecord($cedula, $options);
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('Error generando PDF: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Error interno: ' . $e->getMessage());
}