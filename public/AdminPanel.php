<?php
declare(strict_types=1);
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
/* SecurityContext::requireRole($_ENV['KEYCLOAK_ROLE_ADMIN'] ?? 'ROLE_ADMIN'); */
$currentUser = SecurityContext::getCurrentUser();

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function verifyCsrf(?string $token): bool {
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$message = '';
$messageType = '';
$config = ConfigService::get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $message = 'Token CSRF inválido.';
        $messageType = 'error';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        switch ($action) {
            case 'delete_year_db':
                $year = (int)($_POST['delete_year'] ?? 0);
                if ($year <= 0) { $message = 'Año inválido.'; $messageType = 'error'; break; }
                $em = EntityManagerProvider::get();
                $conn = $em->getConnection();
                try {
                    $conn->beginTransaction();
                    $records = $em->getRepository(AcademicRecord::class)->findBy(['origen_tabla' => (string)$year]);
                    foreach ($records as $r) { $em->remove($r); }
                    $em->flush();
                    $conn->commit();
                    $count = count($records);
                    $message = "Se eliminaron $count registros del año $year.";
                    $messageType = 'success';
                    CsvService::logHistory('Eliminación Masiva', "Se eliminaron $count registros del año $year.");
                } catch (\Throwable $e) {
                    if ($conn->isTransactionActive()) $conn->rollBack();
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                break;

            case 'import_csv':
                if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                    $message = 'Error en subida de archivo.';
                    $messageType = 'error';
                    break;
                }
                $year = (int)($_POST['import_year'] ?? date('Y'));
                $result = CsvService::importCSV($_FILES['csv_file']['tmp_name'], $year);
                $message = $result['success']
                        ? 'Importación exitosa: ' . $result['imported'] . ' registros.'
                        : 'Fallo: ' . implode('; ', $result['errors']);
                $messageType = $result['success'] ? 'success' : 'error';
                break;

            case 'update_record':
                $id = (int)($_POST['edit_id'] ?? 0);
                if ($id <= 0) { $message = 'ID inválido.'; $messageType = 'error'; break; }
                $em = EntityManagerProvider::get();
                $record = $em->getRepository(AcademicRecord::class)->find($id);
                if (!$record) { $message = 'Registro no encontrado.'; $messageType = 'error'; break; }
                $record->fill([
                        'nombre'         => trim((string)($_POST['edit_nombre']  ?? '')),
                        'email'          => trim((string)($_POST['edit_email']   ?? '')),
                        'materia'        => trim((string)($_POST['edit_materia'] ?? '')),
                        'nota'           => $_POST['edit_nota']  ?? null,
                        'total'          => $_POST['edit_total'] ?? null,
                        'periodo'        => trim((string)($_POST['edit_periodo'] ?? '')),
                        'proceso'        => trim((string)($_POST['edit_proceso'] ?? '')),
                        'grupo_objetivo' => trim((string)($_POST['edit_grupo']   ?? '')),
                        'modalidad'      => trim((string)($_POST['edit_modalidad'] ?? '')),
                        'fecha_inicio'   => trim((string)($_POST['edit_inicio'] ?? '')),
                        'fecha_fin'      => trim((string)($_POST['edit_fin']    ?? '')),
                        'aprueba'        => trim((string)($_POST['edit_aprueba'] ?? '')),
                ]);
                $em->flush();
                $message = 'Registro actualizado.';
                $messageType = 'success';
                break;

            // NUEVO: alta manual de un registro individual (no existía en la
            // primera versión migrada; en el original se hacía vía
            // api.php?action=insert_record).
            case 'insert_record':
                $cedula  = trim((string)($_POST['new_cedula']  ?? ''));
                $nombre  = trim((string)($_POST['new_nombre']  ?? ''));
                $materia = trim((string)($_POST['new_materia'] ?? ''));
                $year    = (int)($_POST['new_anio'] ?? date('Y'));
                if ($cedula === '' || $nombre === '' || $materia === '') {
                    $message = 'Cédula, nombre y materia son obligatorios.';
                    $messageType = 'error';
                    break;
                }
                $em = EntityManagerProvider::get();
                $record = new AcademicRecord();
                $record->fill([
                        'cedula'       => $cedula,
                        'nombre'       => $nombre,
                        'materia'      => $materia,
                        'email'        => trim((string)($_POST['new_email']   ?? '')),
                        'nota'         => $_POST['new_nota']  ?? null,
                        'total'        => $_POST['new_total'] ?? null,
                        'periodo'      => trim((string)($_POST['new_periodo'] ?? '')),
                        'anio'         => $year,
                        'origen_tabla' => (string)$year,
                ]);
                $em->persist($record);
                $em->flush();
                $message = "Registro creado (ID {$record->getId()}).";
                $messageType = 'success';
                CsvService::logHistory('Alta Manual', "Registro creado para cédula $cedula (año $year).");
                break;

            // NUEVO: borrado de un registro individual (antes solo existía
            // borrado masivo por año).
            case 'delete_record':
                $id = (int)($_POST['delete_id'] ?? 0);
                if ($id <= 0) { $message = 'ID inválido.'; $messageType = 'error'; break; }
                $em = EntityManagerProvider::get();
                $record = $em->getRepository(AcademicRecord::class)->find($id);
                if (!$record) { $message = 'Registro no encontrado.'; $messageType = 'error'; break; }
                $cedulaBorrada = $record->getCedula();
                $em->remove($record);
                $em->flush();
                $message = "Registro de cédula $cedulaBorrada eliminado.";
                $messageType = 'success';
                CsvService::logHistory('Eliminación', "Registro #$id (cédula $cedulaBorrada) eliminado.");
                break;

            case 'toggle_qr':
                ConfigService::toggleQr();
                $message = 'QR actualizado';
                $messageType = 'success';
                break;

            case 'upload_asset':
                $assetType = is_string($_POST['asset_type'] ?? null) ? $_POST['asset_type'] : '';
                if (!in_array($assetType, ['letterhead', 'signature'], true)) {
                    $message = 'Tipo no permitido.'; $messageType = 'error'; break;
                }
                if (!isset($_FILES['asset_file']) || $_FILES['asset_file']['error'] !== UPLOAD_ERR_OK) {
                    $message = 'Error en subida.'; $messageType = 'error'; break;
                }
                $targetDir = __DIR__ . '/assets/';
                if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
                $targetFile = $targetDir . ($assetType === 'letterhead' ? 'letterhead.png' : 'signature.png');
                if (move_uploaded_file($_FILES['asset_file']['tmp_name'], $targetFile)) {
                    $cfg = ConfigService::get();
                    $cfg[$assetType . '_image'] = 'assets/' . basename($targetFile);
                    ConfigService::set($cfg);
                    $message = 'Imagen cargada.';
                    $messageType = 'success';
                } else {
                    $message = 'No se pudo mover el archivo.';
                    $messageType = 'error';
                }
                break;

            case 'save_schema':
                $schema = json_decode(is_string($_POST['schema_json'] ?? null) ? $_POST['schema_json'] : '[]', true);
                if (is_array($schema) && ConfigService::setColumnSchema($schema)) {
                    $message = 'Esquema guardado.';
                    $messageType = 'success';
                } else {
                    $message = 'Esquema inválido.';
                    $messageType = 'error';
                }
                break;

            default:
                $message = 'Acción no reconocida.';
                $messageType = 'error';
        }
    }
}

// Data para la vista
$em = EntityManagerProvider::get();
$years = ErrorFinder::getAvailableYears();
$schema = ConfigService::getColumnSchema();

$searchColumn = is_string($_GET['columna'] ?? null) ? $_GET['columna'] : 'cedula';
$searchTerm   = is_string($_GET['termino'] ?? null) ? $_GET['termino'] : '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$allowed = ['cedula','nombre','email','materia','proceso','origen_tabla','grupo_objetivo','modalidad'];
$records = [];
$totalRecords = 0;

if ($searchTerm !== '' && in_array($searchColumn, $allowed, true)) {
    $qb = $em->createQueryBuilder()
            ->select('r')->from(AcademicRecord::class, 'r')
            ->where("r.$searchColumn LIKE :termino")
            ->setParameter('termino', '%' . $searchTerm . '%')
            ->orderBy('r.origen_tabla', 'DESC')
            ->addOrderBy('r.id', 'DESC');
    $records = array_map(static fn(AcademicRecord $r) => $r->toArray(), $qb->getQuery()->getResult());
    $totalRecords = count($records);
} else {
    $qb = $em->createQueryBuilder()
            ->select('r')->from(AcademicRecord::class, 'r')
            ->orderBy('r.origen_tabla', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($perPage)
            ->setFirstResult($offset);
    $records = array_map(static fn(AcademicRecord $r) => $r->toArray(), $qb->getQuery()->getResult());
    $countQb = $em->createQueryBuilder()->select('COUNT(r.id)')->from(AcademicRecord::class, 'r');
    $totalRecords = (int)$countQb->getQuery()->getSingleScalarResult();
}
$totalPages = $totalRecords > 0 ? (int)ceil($totalRecords / $perPage) : 1;
$history = CsvService::getHistory(50);
$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; color: #333; line-height: 1.6; }
        .container { max-width: 98%; margin: 0 auto; padding: 20px; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-weight: 500; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .nav-tabs { display: flex; gap: 5px; margin-bottom: 20px; flex-wrap: wrap; }
        .nav-tab { padding: 12px 24px; background: white; border: 1px solid #ddd; border-radius: 6px 6px 0 0; cursor: pointer; font-weight: 500; }
        .nav-tab.active { background: #003366; color: white; border-color: #003366; }
        .tab-content { display: none; background: white; border-radius: 0 6px 6px 6px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .tab-content.active { display: block; }
        .form-group { margin-bottom: 12px; }
        label { display: block; margin-bottom: 4px; font-weight: 500; font-size: 13px; }
        input[type="text"], input[type="password"], input[type="number"], select { width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
        .btn { padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: 500; font-size: 14px; text-decoration: none; display: inline-block; }
        .btn-primary { background: #003366; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .table-container { overflow-x: auto; margin-top: 15px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; }
        .badge-year { background: #e7f1ff; color: #003366; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .card { background: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 20px; border: 1px solid #e1e5eb; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; padding: 25px; border-radius: 8px; max-width: 800px; width: 90%; }
        .pagination { display: flex; gap: 5px; justify-content: center; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 14px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; }
        .pagination .active { background: #003366; color: white; border-color: #003366; }
    </style>
    <script>
        function searchRecords() {
            const col = document.getElementById('searchColumn').value;
            const term = document.getElementById('searchTerm').value;
            window.location.href = '?tab=records&columna=' + encodeURIComponent(col) + '&termino=' + encodeURIComponent(term);
        }
        function showTab(tab) {
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('.nav-tab').forEach(b => b.classList.remove('active'));
            const tabContent = document.getElementById('tab-' + tab);
            const tabBtn = document.querySelector('[data-tab="' + tab + '"]');
            if (tabContent && tabBtn) {
                tabContent.classList.add('active');
                tabBtn.classList.add('active');
            }
        }
        function openEditModal(record) {
            document.getElementById('edit_id').value = record.id;
            document.getElementById('edit_cedula').value = record.cedula;
            document.getElementById('edit_nombre').value = record.nombre;
            document.getElementById('edit_email').value = record.email || '';
            document.getElementById('edit_materia').value = record.materia;
            document.getElementById('edit_nota').value = record.nota ?? '';
            document.getElementById('edit_total').value = record.total ?? '';
            document.getElementById('edit_periodo').value = record.periodo || '';
            document.getElementById('edit_proceso').value = record.proceso || '';
            document.getElementById('edit_grupo').value = record.grupo_objetivo || '';
            document.getElementById('edit_modalidad').value = record.modalidad || '';
            document.getElementById('edit_inicio').value = record.fecha_inicio || '';
            document.getElementById('edit_fin').value = record.fecha_fin || '';
            document.getElementById('edit_aprueba').value = record.aprueba || '';
            document.getElementById('editModal').classList.add('active');
        }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }
        document.addEventListener('DOMContentLoaded', () => {
            const tab = new URLSearchParams(window.location.search).get('tab') || 'records';
            showTab(tab);
            document.querySelectorAll('.nav-tab').forEach(btn => btn.addEventListener('click', () => showTab(btn.dataset.tab)));
        });
    </script>
</head>
<body>
<div class="container">
    <?php require_once __DIR__ . '/../templates/includes/header.php'; ?>
    <?php if ($message): ?>
        <div class="alert <?= e($messageType) ?>"><?= e($message) ?></div>
    <?php endif; ?>

    <div class="nav-tabs">
        <button class="nav-tab active" data-tab="records">Registros</button>
        <button class="nav-tab" data-tab="import">Importar CSV</button>
        <button class="nav-tab" data-tab="config">Gráficos</button>
        <button class="nav-tab" data-tab="history">Bitácora</button>
        <button class="nav-tab" data-tab="schema">Editor Esquema</button>
    </div>

    <div class="tab-content active" id="tab-records">
        <div style="display:flex;gap:15px;margin-bottom:20px;align-items:flex-end;flex-wrap:wrap;background:#fff;padding:15px;border:1px solid #ddd;border-radius:8px;">
            <div class="form-group" style="width:250px;margin:0;">
                <label for="searchColumn">Buscar por</label>
                <select id="searchColumn">
                    <option value="cedula">Cédula</option>
                    <option value="nombre">Nombre</option>
                    <option value="email">Email</option>
                    <option value="materia">Materia</option>
                </select>
            </div>
            <div class="form-group" style="flex:1;margin:0;">
                <label for="searchTerm">Término</label>
                <input type="text" id="searchTerm" value="<?= e($searchTerm) ?>" onkeypress="if(event.key==='Enter')searchRecords();">
            </div>
            <button class="btn btn-primary" onclick="searchRecords()">Buscar</button>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('newRecordModal').classList.add('active')">+ Nuevo Registro</button>
        </div>
        <div class="table-container">
            <table>
                <thead>
                <tr><th>Año</th><th>Cédula</th><th>Nombre</th><th>Materia</th><th>Nota</th><th>Total</th><th>Acción</th></tr>
                </thead>
                <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="7" style="text-align:center;">No se encontraron registros.</td></tr>
                <?php else: foreach ($records as $r): ?>
                    <tr>
                        <td><span class="badge-year"><?= e($r['origen_tabla'] ?? '') ?></span></td>
                        <td><?= e($r['cedula'] ?? '') ?></td>
                        <td><strong><?= e($r['nombre'] ?? '') ?></strong></td>
                        <td><?= e($r['materia'] ?? '') ?></td>
                        <td><?= e($r['nota'] ?? '') ?></td>
                        <td><?= e($r['total'] ?? '') ?></td>
                        <td style="white-space:nowrap;">
                            <button class="btn btn-primary btn-sm" onclick='openEditModal(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Editar</button>
                            <form action="?tab=records" method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este registro (cédula <?= e($r['cedula'] ?? '') ?>)?');">
                                <input type="hidden" name="action" value="delete_record">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="delete_id" value="<?= (int)($r['id'] ?? 0) ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="?tab=records&page=<?= $p ?>" class="<?= $p == $page ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="tab-content" id="tab-import">
        <div class="grid-2">
            <div class="card">
                <h3>Cargar CSV</h3>
                <form action="?tab=import" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_csv">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="import_year" value="<?= (int)date('Y') ?>">
                    <div class="form-group">
                        <label for="csv_file">Archivo CSV</label>
                        <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Importar</button>
                </form>
            </div>
            <div class="card" style="border:1px solid #f5c6cb;background:#fff5f5;">
                <h3 style="color:#dc3545;">Eliminar Año</h3>
                <form action="?tab=import" method="POST" onsubmit="return confirm('¿Eliminar todos los registros del año?');">
                    <input type="hidden" name="action" value="delete_year_db">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <div class="form-group">
                        <label for="delete_year">Año</label>
                        <select id="delete_year" name="delete_year" required>
                            <option value="">--</option>
                            <?php foreach ($years as $y): ?>
                                <option value="<?= e($y) ?>"><?= e($y) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-danger">Eliminar</button>
                </form>
            </div>
        </div>
    </div>

    <div class="tab-content" id="tab-config">
        <div class="grid-2">
            <div class="card">
                <h3>QR Dinámico</h3>
                <form action="?tab=config" method="POST">
                    <input type="hidden" name="action" value="toggle_qr">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <button type="submit" class="btn <?= ($config['qr_enabled'] ?? false) ? 'btn-primary' : 'btn-secondary' ?>">
                        <?= ($config['qr_enabled'] ?? false) ? '🟢 ACTIVADO' : '🔴 DESACTIVADO' ?>
                    </button>
                </form>
            </div>
            <div class="card">
                <h3>Imágenes</h3>
                <form action="?tab=config" method="POST" enctype="multipart/form-data" style="margin-bottom:20px;">
                    <input type="hidden" name="action" value="upload_asset">
                    <input type="hidden" name="asset_type" value="letterhead">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <div class="form-group">
                        <label>Membrete</label>
                        <input type="file" name="asset_file" accept=".png" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Actualizar</button>
                </form>
                <form action="?tab=config" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_asset">
                    <input type="hidden" name="asset_type" value="signature">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <div class="form-group">
                        <label>Firma</label>
                        <input type="file" name="asset_file" accept=".png" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Actualizar</button>
                </form>
            </div>
        </div>
    </div>

    <div class="tab-content" id="tab-history">
        <div class="card">
            <h3>Bitácora</h3>
            <div style="background:#272822;color:#f8f8f2;padding:15px;border-radius:6px;font-family:monospace;font-size:12px;max-height:400px;overflow-y:auto;">
                <?php if (empty($history)): ?>
                    <span style="color:#75715e;">[Sin operaciones]</span>
                <?php else: foreach ($history as $log): ?>
                    <div style="border-bottom:1px solid #3e3d32;padding:4px 0;">
                        <span style="color:#75715e;"><?= e($log['timestamp'] ?? '') ?></span> |
                        <strong style="color:#a6e22e;"><?= e($log['action'] ?? '') ?></strong> |
                        <span style="color:#e6db74;"><?= e($log['details'] ?? '') ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <div class="tab-content" id="tab-schema">
        <div class="card">
            <h3>Editor de Esquema PDF</h3>
            <p>Configuración actual:</p>
            <pre><?= e(json_encode($schema, JSON_PRETTY_PRINT)) ?></pre>
        </div>
    </div>
</div>

<div class="modal" id="editModal">
    <div class="modal-content">
        <h3 style="color:#003366;margin-bottom:15px;">Editar Registro</h3>
        <form action="?tab=records" method="POST">
            <input type="hidden" name="action" value="update_record">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="edit_id" id="edit_id">
            <div class="form-group">
                <label>Cédula</label>
                <input type="text" id="edit_cedula" readonly>
            </div>
            <div class="form-group">
                <label>Nombre</label>
                <input type="text" name="edit_nombre" id="edit_nombre" required>
            </div>
            <div class="form-group">
                <label>Materia</label>
                <input type="text" name="edit_materia" id="edit_materia" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="text" name="edit_email" id="edit_email">
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:15px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="newRecordModal">
    <div class="modal-content">
        <h3 style="color:#003366;margin-bottom:15px;">Nuevo Registro</h3>
        <form action="?tab=records" method="POST">
            <input type="hidden" name="action" value="insert_record">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="form-group">
                <label for="new_cedula">Cédula</label>
                <input type="text" name="new_cedula" id="new_cedula" required>
            </div>
            <div class="form-group">
                <label for="new_nombre">Nombre</label>
                <input type="text" name="new_nombre" id="new_nombre" required>
            </div>
            <div class="form-group">
                <label for="new_materia">Materia</label>
                <input type="text" name="new_materia" id="new_materia" required>
            </div>
            <div class="form-group">
                <label for="new_email">Email</label>
                <input type="text" name="new_email" id="new_email">
            </div>
            <div class="form-group">
                <label for="new_nota">Nota</label>
                <input type="text" name="new_nota" id="new_nota">
            </div>
            <div class="form-group">
                <label for="new_total">Total</label>
                <input type="text" name="new_total" id="new_total">
            </div>
            <div class="form-group">
                <label for="new_periodo">Periodo</label>
                <input type="text" name="new_periodo" id="new_periodo">
            </div>
            <div class="form-group">
                <label for="new_anio">Año</label>
                <input type="number" name="new_anio" id="new_anio" value="<?= (int)date('Y') ?>" required>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:15px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('newRecordModal')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Crear</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>