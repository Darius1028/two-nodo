<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use App\Core\EntityManagerProvider;
use App\Entity\AcademicRecord;
use App\Security\SecurityContext;
use App\Service\ConfigService;
use App\Service\CsvService;
use App\Service\ErrorFinder;

SecurityContext::ensureSession();

define('METHOD_NOT_ALLOWED', 'Method not allowed');
define('YEAR_ID_REQUIRED', 'Year and ID parameters required');

$allowedOrigins = ['https://escuela.funcionjudicial.gob.ec'];
if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$getInt = static function (array $source, string $key, int $default = 0): int {
    $val = $source[$key] ?? $default;
    return is_numeric($val) ? (int)$val : $default;
};
$getString = static function (array $source, string $key, string $default = ''): string {
    $val = $source[$key] ?? $default;
    return is_string($val) ? trim($val) : $default;
};

$rawInput = file_get_contents('php://input');
$input = is_string($rawInput) ? (json_decode($rawInput, true) ?? []) : [];
if (!is_array($input)) $input = [];

$action = $getString($_GET, 'action', $getString($input, 'action'));

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

/** Helper: exige rol de admin y responde 401/403 en JSON en vez de HTML (para uso en API). */
$requireAdminJson = static function () use ($respond): array {
    $user = SecurityContext::getCurrentUser();
    if ($user === null) {
        $respond(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    $adminRole = $_ENV['KEYCLOAK_ROLE_ADMIN'] ?? 'ROLE_ADMIN';
    if (!SecurityContext::hasRole($adminRole)) {
        $respond(['success' => false, 'error' => 'Forbidden'], 403);
    }
    return $user;
};

try {
    switch ($action) {
        case 'me':
            $user = SecurityContext::getCurrentUser();
            if ($user === null) {
                $respond(['success' => false, 'error' => 'Not authenticated'], 401);
            }
            $respond(['success' => true, 'user' => [
                'username' => $user['preferred_username'] ?? '',
                'email'    => $user['email'] ?? '',
                'name'     => $user['name'] ?? '',
                'roles'    => $user['client_roles'] ?? [],
            ]]);

        case 'search':
            $cedula = $getString($_GET, 'cedula', $getString($input, 'cedula'));
            if ($cedula === '') {
                $respond(['success' => false, 'error' => 'Cedula required'], 400);
            }
            $em = EntityManagerProvider::get();
            $qb = $em->createQueryBuilder()
                ->select('r')->from(AcademicRecord::class, 'r')
                ->where('r.cedula = :cedula')->setParameter('cedula', $cedula)
                ->orderBy('r.origen_tabla', 'DESC');
            $records = array_map(static fn(AcademicRecord $r) => $r->toArray(), $qb->getQuery()->getResult());
            $respond(['success' => true, 'cedula' => $cedula, 'count' => count($records), 'records' => $records]);

        case 'get_record':
            $id = $getInt($_GET, 'id', $getInt($input, 'id', 0));
            if ($id <= 0) {
                $respond(['success' => false, 'error' => YEAR_ID_REQUIRED], 400);
            }
            $em = EntityManagerProvider::get();
            $record = $em->getRepository(AcademicRecord::class)->find($id);
            if ($record === null) {
                $respond(['success' => false, 'error' => 'Record not found'], 404);
            }
            $respond(['success' => true, 'record' => $record->toArray()]);

        case 'list_records':
            $year   = $getInt($_GET, 'year', $getInt($input, 'year', (int)date('Y')));
            $limit  = max(1, min(500, $getInt($_GET, 'limit', $getInt($input, 'limit', 50))));
            $offset = max(0, $getInt($_GET, 'offset', $getInt($input, 'offset', 0)));

            $em = EntityManagerProvider::get();
            $qb = $em->createQueryBuilder()
                ->select('r')->from(AcademicRecord::class, 'r')
                ->where('r.origen_tabla = :year')->setParameter('year', (string)$year)
                ->orderBy('r.id', 'DESC')
                ->setMaxResults($limit)->setFirstResult($offset);
            $records = array_map(static fn(AcademicRecord $r) => $r->toArray(), $qb->getQuery()->getResult());

            $countQb = $em->createQueryBuilder()
                ->select('COUNT(r.id)')->from(AcademicRecord::class, 'r')
                ->where('r.origen_tabla = :year')->setParameter('year', (string)$year);
            $total = (int)$countQb->getQuery()->getSingleScalarResult();

            $respond([
                'success' => true, 'year' => $year, 'total' => $total,
                'limit' => $limit, 'offset' => $offset, 'records' => $records,
            ]);

        case 'insert_record':
            $requireAdminJson();
            if ($method !== 'POST') {
                $respond(['success' => false, 'error' => METHOD_NOT_ALLOWED], 405);
            }
            $year = $getInt($input, 'year', (int)date('Y'));
            $cedula  = $getString($input, 'cedula');
            $nombre  = $getString($input, 'nombre');
            $materia = $getString($input, 'materia');
            if ($cedula === '' || $nombre === '' || $materia === '') {
                $respond(['success' => false, 'error' => 'Required fields: cedula, nombre, materia'], 400);
            }
            $em = EntityManagerProvider::get();
            $record = new AcademicRecord();
            $record->fill([
                'cedula'       => $cedula,
                'nombre'       => $nombre,
                'materia'      => $materia,
                'email'        => $getString($input, 'email'),
                'nota'         => $input['nota']  ?? null,
                'total'        => $input['total'] ?? null,
                'periodo'      => $getString($input, 'periodo'),
                'anio'         => $year,
                'origen_tabla' => (string)$year,
            ]);
            $em->persist($record);
            $em->flush();
            CsvService::logHistory('Alta Manual', "Registro creado para cédula $cedula (año $year).");
            $respond(['success' => true, 'id' => $record->getId()]);

        case 'update_record':
            $requireAdminJson();
            if ($method !== 'PUT' && $method !== 'POST') {
                $respond(['success' => false, 'error' => METHOD_NOT_ALLOWED], 405);
            }
            $id = $getInt($input, 'id', 0);
            if ($id <= 0) {
                $respond(['success' => false, 'error' => YEAR_ID_REQUIRED], 400);
            }
            $em = EntityManagerProvider::get();
            $record = $em->getRepository(AcademicRecord::class)->find($id);
            if ($record === null) {
                $respond(['success' => false, 'error' => 'Record not found'], 404);
            }
            $record->fill([
                'nombre'  => $getString($input, 'nombre'),
                'email'   => $getString($input, 'email'),
                'materia' => $getString($input, 'materia'),
                'nota'    => isset($input['nota']) && $input['nota'] !== '' ? $input['nota'] : null,
                'total'   => isset($input['total']) && $input['total'] !== '' ? $input['total'] : null,
                'periodo' => $getString($input, 'periodo'),
            ]);
            $em->flush();
            $respond(['success' => true]);

        case 'delete_record':
            $requireAdminJson();
            if ($method !== 'DELETE' && $method !== 'POST') {
                $respond(['success' => false, 'error' => METHOD_NOT_ALLOWED], 405);
            }
            $id = $getInt($_GET, 'id', $getInt($input, 'id', 0));
            if ($id <= 0) {
                $respond(['success' => false, 'error' => YEAR_ID_REQUIRED], 400);
            }
            $em = EntityManagerProvider::get();
            $record = $em->getRepository(AcademicRecord::class)->find($id);
            if ($record === null) {
                $respond(['success' => false, 'error' => 'Record not found'], 404);
            }
            $em->remove($record);
            $em->flush();
            CsvService::logHistory('Eliminación', "Registro #$id eliminado.");
            $respond(['success' => true]);

        case 'get_years':
            $respond(['success' => true, 'years' => ErrorFinder::getAvailableYears()]);

        case 'check_errors':
            SecurityContext::requireRole($_ENV['KEYCLOAK_ROLE_ADMIN'] ?? 'ROLE_ADMIN');
            $year = $getInt($_GET, 'year', $getInt($input, 'year', (int)date('Y')));
            $type = $getString($_GET, 'type', $getString($input, 'type'));
            if ($type !== '') {
                $finderMethod = 'find' . ucfirst(str_replace('_', '', $type)) . 's';
                if (!method_exists(ErrorFinder::class, $finderMethod)) {
                    $respond(['success' => false, 'error' => 'Invalid error type'], 400);
                }
                $errors = ErrorFinder::$finderMethod($year);
            } else {
                $all = ErrorFinder::runAllChecks($year);
                $errors = array_merge(...array_values($all));
            }
            $respond(['success' => true, 'year' => $year, 'count' => count($errors), 'errors' => $errors]);

        case 'error_summary':
            SecurityContext::requireRole($_ENV['KEYCLOAK_ROLE_ADMIN'] ?? 'ROLE_ADMIN');
            $yearParam = $_GET['year'] ?? $input['year'] ?? null;
            $summary = $yearParam !== null
                ? ErrorFinder::getErrorSummary((int)$yearParam)
                : ErrorFinder::getErrorSummaryAllYears();
            $respond(['success' => true, 'summary' => $summary]);

        case 'import_csv':
            $requireAdminJson();
            if ($method !== 'POST') {
                $respond(['success' => false, 'error' => METHOD_NOT_ALLOWED], 405);
            }
            $year = $getInt($_POST, 'year', $getInt($input, 'year', (int)date('Y')));
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                $respond(['success' => false, 'error' => 'CSV file required'], 400);
            }
            $respond(CsvService::importCSV($_FILES['csv_file']['tmp_name'], $year));

        case 'validate_csv':
            $requireAdminJson();
            if ($method !== 'POST') {
                $respond(['success' => false, 'error' => METHOD_NOT_ALLOWED], 405);
            }
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                $respond(['success' => false, 'error' => 'CSV file required'], 400);
            }
            $respond(CsvService::validateCSV($_FILES['csv_file']['tmp_name']));

        case 'export_csv':
            SecurityContext::requireRole($_ENV['KEYCLOAK_ROLE_ADMIN'] ?? 'ROLE_ADMIN');
            $year   = $getInt($_GET, 'year', $getInt($input, 'year', (int)date('Y')));
            $cedula = $getString($_GET, 'cedula', $getString($input, 'cedula'));
            $outputPath = sys_get_temp_dir() . "/export_{$year}_" . date('YmdHis') . '.csv';
            $result = CsvService::exportCSV($year, $outputPath, $cedula);
            if ($result['success'] && file_exists($outputPath)) {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . basename($outputPath) . '"');
                readfile($outputPath);
                unlink($outputPath);
                exit;
            }
            $errorMsg = implode('; ', $result['errors'] ?? ['Unknown error']);
            $respond(['success' => false, 'error' => 'Export failed: ' . $errorMsg], 500);

        case 'get_config':
            $config = ConfigService::get();
            unset($config['admin_code']);
            $respond(['success' => true, 'config' => $config]);

        case 'get_history':
            SecurityContext::requireRole($_ENV['KEYCLOAK_ROLE_ADMIN'] ?? 'ROLE_ADMIN');
            $limit = max(1, min(500, $getInt($_GET, 'limit', 50)));
            $respond(['success' => true, 'history' => CsvService::getHistory($limit)]);

        case 'generate_pdf':
            $cedula = $getString($_GET, 'cedula', $getString($input, 'cedula'));
            if ($cedula === '') {
                $respond(['success' => false, 'error' => 'Cedula parameter required'], 400);
            }
            $em = EntityManagerProvider::get();
            $count = (int)$em->createQueryBuilder()
                ->select('COUNT(r.id)')->from(AcademicRecord::class, 'r')
                ->where('r.cedula = :cedula')->setParameter('cedula', $cedula)
                ->getQuery()->getSingleScalarResult();
            if ($count === 0) {
                $respond(['success' => false, 'error' => 'No records found for this cedula'], 404);
            }
            $respond([
                'success'      => true,
                'cedula'       => $cedula,
                'record_count' => $count,
                'pdf_url'      => 'PdfGenerator.php?cedula_query=' . urlencode($cedula),
            ]);

        default:
            $respond([
                'name'    => 'Academic Record System API',
                'version' => '2.1',
                'endpoints' => [
                    'GET /api.php?action=me'                                           => 'Datos del usuario autenticado',
                    'GET /api.php?action=search&cedula=12345678'                       => 'Buscar registros por cédula',
                    'GET /api.php?action=get_record&id=1'                              => 'Obtener un registro por ID',
                    'GET /api.php?action=list_records&year=2024&limit=50&offset=0'     => 'Listar registros por año (paginado)',
                    'POST /api.php?action=insert_record'                               => 'Crear registro (admin)',
                    'PUT /api.php?action=update_record'                                => 'Actualizar registro (admin)',
                    'DELETE /api.php?action=delete_record&id=1'                        => 'Eliminar registro (admin)',
                    'GET /api.php?action=get_years'                                    => 'Años disponibles',
                    'GET /api.php?action=check_errors&year=2024&type=comma_emails'     => 'Chequeo de errores (admin)',
                    'GET /api.php?action=error_summary&year=2024'                      => 'Resumen de errores (admin)',
                    'POST /api.php?action=import_csv'                                  => 'Importar CSV (admin)',
                    'POST /api.php?action=validate_csv'                                => 'Validar CSV (admin)',
                    'GET /api.php?action=export_csv&year=2024'                         => 'Exportar CSV (admin)',
                    'GET /api.php?action=get_config'                                   => 'Configuración pública del sistema',
                    'GET /api.php?action=get_history&limit=50'                         => 'Bitácora (admin)',
                    'GET /api.php?action=generate_pdf&cedula=12345678'                 => 'Info previa a generar PDF',
                ],
            ]);
    }
} catch (Throwable $e) {
    $respond(['success' => false, 'error' => $e->getMessage()], 400);
}
