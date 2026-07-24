<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Manejo global de errores -- ver src/Core/ErrorHandler.php
\App\Core\ErrorHandler::register();

use App\Core\EntityManagerProvider;
use App\Core\RequestContext;
use App\Entity\AcademicRecord;
use App\Security\SecurityContext;
use App\Service\ConfigService;
use App\Service\CsvService;
use App\Service\ErrorFinder;

SecurityContext::ensureSession();
SecurityContext::requireRole($_ENV['KEYCLOAK_ROLE_ADMIN'] ?? 'ADMIN_ACADEMICO');
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
                $conn = EntityManagerProvider::get()->getConnection();
                $idPersona = SecurityContext::getCurrentUserId() ?? 0;
                $ip       = mb_substr(trim(RequestContext::getClientIp()), 0, 45);
                $equipo   = mb_substr(trim(RequestContext::getClientHostname()), 0, 50);
                $fecha    = (new \DateTime())->format('Y-m-d H:i:s');
                try {
                    $count = $conn->executeStatement(
                        "UPDATE [Academico].[RecordAcademico]
                            SET estado            = 'X',
                                idPersonaModifica = :idPersona,
                                fechaModifica     = :fecha,
                                ipModifica        = :ip,
                                equipoModifica    = :equipo,
                                motivoModifica    = 'Eliminado'
                          WHERE origen_tabla = :year
                            AND estado      != 'X'",
                        [
                            'idPersona' => $idPersona,
                            'fecha'     => $fecha,
                            'ip'        => $ip,
                            'equipo'    => $equipo,
                            'year'      => (string)$year,
                        ]
                    );
                    $message = "Se eliminaron $count registros del año $year.";
                    $messageType = 'success';
                    CsvService::logHistory('Eliminación Masiva', "Se eliminaron $count registros del año $year (estado X).");
                } catch (\Throwable $e) {
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

                // ✅ CORRECCIÓN: Pasar explícitamente el usuario, IP y hostname
                $result = CsvService::importCSV(
                        $_FILES['csv_file']['tmp_name'],
                        $year,
                        SecurityContext::getCurrentUserId() ?? 0,
                        RequestContext::getClientIp(),
                        RequestContext::getClientHostname()
                );

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
                $record->setAuditoriaModificacion(
                        SecurityContext::getCurrentUserId() ?? 0,
                        RequestContext::getClientIp(),
                        RequestContext::getClientHostname(),
                        trim((string)($_POST['edit_motivo'] ?? ''))
                );
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

                $record->setAuditoriaCreacion(
                        SecurityContext::getCurrentUserId() ?? 0,
                        RequestContext::getClientIp(),
                        RequestContext::getClientHostname()
                );
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
                $record->markAsDeleted(
                    SecurityContext::getCurrentUserId() ?? 0,
                    RequestContext::getClientIp(),
                    RequestContext::getClientHostname()
                );
                $em->flush();
                $message = "Registro de cédula $cedulaBorrada eliminado.";
                $messageType = 'success';
                CsvService::logHistory('Eliminación', "Registro #$id (cédula $cedulaBorrada) eliminado (estado X).");
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
                if (!is_dir($targetDir)) { mkdir($targetDir, 0755, true); }
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
$config = ConfigService::get();
$em = EntityManagerProvider::get();
$years = ErrorFinder::getAvailableYears();
$schema = ConfigService::getColumnSchema();

$searchColumn = is_string($_GET['columna'] ?? null) ? $_GET['columna'] : 'cedula';
$searchTerm   = is_string($_GET['termino'] ?? null) ? $_GET['termino'] : '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$allowed = ['cedula','nombre','email','materia','proceso','origen_tabla','grupo_objetivo','modalidad','nota','total','fecha_inicio','fecha_fin','aprueba'];
$records = [];
$totalRecords = 0;

if ($searchTerm !== '' && in_array($searchColumn, $allowed, true)) {
    $qb = $em->createQueryBuilder()
            ->select('r')->from(AcademicRecord::class, 'r')
            ->where("r.$searchColumn LIKE :termino")
            ->setParameter('termino', '%' . $searchTerm . '%')
            ->andWhere("r.estado != 'X'")
            ->orderBy('r.origen_tabla', 'DESC')
            ->addOrderBy('r.id', 'DESC');
    $records = array_map(static fn(AcademicRecord $r) => $r->toArray(), $qb->getQuery()->getResult());
    $totalRecords = count($records);
} else {
    $qb = $em->createQueryBuilder()
            ->select('r')->from(AcademicRecord::class, 'r')
            ->where("r.estado != 'X'")
            ->orderBy('r.origen_tabla', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($perPage)
            ->setFirstResult($offset);
    $records = array_map(static fn(AcademicRecord $r) => $r->toArray(), $qb->getQuery()->getResult());
    $countQb = $em->createQueryBuilder()->select('COUNT(r.id)')->from(AcademicRecord::class, 'r')
            ->where("r.estado != 'X'");
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
        .btn-primary:hover { background: #002244; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .table-container { overflow-x: auto; margin-top: 15px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; white-space: nowrap; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; }
        .badge-year { background: #e7f1ff; color: #003366; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        .card { background: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 20px; border: 1px solid #e1e5eb; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; padding: 25px; border-radius: 8px; max-width: 800px; width: 90%; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
        .modal-sm { max-width: 400px; text-align: center; }
        .builder-toolbar { background: #eef2f7; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 15px; align-items: center; }
        .sortable-item { display: flex; align-items: center; gap: 10px; padding: 12px; background: white; border: 1px solid #ddd; border-radius: 6px; margin-bottom: 8px; cursor: grab; }
        .sortable-item .label-input { flex: 1; border: 1px solid #ccc; padding: 6px 10px; border-radius: 4px; }
        .sortable-item .width-input { width: 80px; text-align: center; }
        .del-btn { background: none; border: none; color: #dc3545; font-size: 16px; cursor: pointer; }
        .schema-message { padding: 6px 12px; border-radius: 4px; background: #fff5f5; border: 1px solid #f5c6cb; font-size: 13px; transition: opacity 0.3s; }
        .schema-message.show { display: block !important; opacity: 1; }
        .visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); border: 0; }
        .pagination { display: flex; gap: 5px; justify-content: center; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 14px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; }
        .pagination .active { background: #003366; color: white; border-color: #003366; }
        .pagination span { background: transparent; border: none; color: #666; }
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
                const url = new URL(window.location);
                url.searchParams.set('tab', tab);
                window.history.pushState({}, '', url);
            }
        }
        function confirmDeleteYear(event) {
            event.preventDefault();
            document.getElementById('deleteConfirmModal').classList.add('active');
            return false;
        }
        function executeDeleteYear() {
            document.getElementById('deleteYearForm').submit();
        }
        let pendingDeleteRecordFormId = null;
        function confirmDeleteRecord(formId, cedula) {
            pendingDeleteRecordFormId = formId;
            document.getElementById('deleteRecordMessage').textContent =
                '¿Eliminar este registro (cédula ' + cedula + ')?';
            document.getElementById('deleteRecordModal').classList.add('active');
        }
        function executeDeleteRecord() {
            if (pendingDeleteRecordFormId) {
                document.getElementById(pendingDeleteRecordFormId).submit();
            }
        }
        let dragSrcEl = null;
        function handleDragStart(e) { dragSrcEl = this; e.dataTransfer.effectAllowed = 'move'; }
        function handleDrop(e) {
            if (dragSrcEl !== this) {
                let items = [...document.querySelectorAll('.sortable-item')];
                let srcIdx = items.indexOf(dragSrcEl), targetIdx = items.indexOf(this);
                if (srcIdx < targetIdx) this.after(dragSrcEl); else this.before(dragSrcEl);
            }
            updateSchemaJson();
            return false;
        }
        function attachDragEvents(item) {
            item.addEventListener('dragstart', handleDragStart);
            item.addEventListener('dragover', e => e.preventDefault());
            item.addEventListener('drop', handleDrop);
            item.querySelector('.label-input').addEventListener('input', updateSchemaJson);
            item.querySelector('.width-input').addEventListener('input', updateSchemaJson);
            item.querySelector('.visible-checkbox').addEventListener('change', updateSchemaJson);
        }
        function updateSchemaJson() {
            let schema = [];
            document.querySelectorAll('.sortable-item').forEach(item => {
                schema.push({
                    key: item.dataset.key,
                    label: item.querySelector('.label-input').value,
                    width: parseFloat(item.querySelector('.width-input').value) || 20,
                    visible: item.querySelector('.visible-checkbox').checked
                });
            });
            document.getElementById('schema_json').value = JSON.stringify(schema);
        }
        function addNewColumn() {
            const select = document.getElementById('newColSelect');
            const key = select.value;
            const label = select.options[select.selectedIndex].text;
            const msgContainer = document.getElementById('schemaMessage');
            msgContainer.textContent = '';
            msgContainer.classList.remove('show');
            msgContainer.style.display = 'none';
            msgContainer.style.color = '#dc3545';
            msgContainer.style.background = '#fff5f5';
            msgContainer.style.borderColor = '#f5c6cb';
            if (document.querySelector('li[data-key="' + CSS.escape(key) + '"]')) {
                msgContainer.textContent = '⚠️ Esta columna ya está agregada.';
                msgContainer.style.display = 'block';
                msgContainer.classList.add('show');
                setTimeout(() => {
                    msgContainer.classList.remove('show');
                    setTimeout(() => { msgContainer.style.display = 'none'; }, 300);
                }, 4000);
                return;
            }
            const li = document.createElement('li');
            li.className = 'sortable-item';
            li.draggable = true;
            li.dataset.key = key;
            const uniqueId = 'vis_' + Date.now() + Math.random().toString(36).substring(2, 5);
            li.innerHTML = '<div class="drag-handle">☰</div>'
                + '<input type="checkbox" class="visible-checkbox" id="' + uniqueId + '" checked aria-label="Visible">'
                + '<span style="min-width:100px;font-family:monospace;">[' + key + ']</span>'
                + '<input type="text" class="label-input" value="' + label + '" aria-label="Etiqueta de columna">'
                + '<input type="number" class="width-input" value="25" aria-label="Ancho en mm"><span>mm</span>'
                + '<button type="button" class="del-btn" onclick="this.parentElement.remove(); updateSchemaJson();">❌</button>';
            document.getElementById('sortableSchema').appendChild(li);
            attachDragEvents(li);
            updateSchemaJson();
            msgContainer.textContent = '✅ Columna añadida correctamente.';
            msgContainer.style.color = '#155724';
            msgContainer.style.background = '#d4edda';
            msgContainer.style.borderColor = '#c3e6cb';
            msgContainer.style.display = 'block';
            msgContainer.classList.add('show');
            setTimeout(() => {
                msgContainer.classList.remove('show');
                setTimeout(() => { msgContainer.style.display = 'none'; }, 300);
            }, 3000);
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
            document.querySelectorAll('.sortable-item').forEach(attachDragEvents);
            updateSchemaJson();
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
            <div class="form-group" style="width:250px; margin:0;">
                <label for="searchColumn">Buscar por (Filtro de Columna)</label>
                <select id="searchColumn">
                    <option value="cedula" <?= $searchColumn === 'cedula' ? 'selected' : '' ?>>Cédula</option>
                    <option value="nombre" <?= $searchColumn === 'nombre' ? 'selected' : '' ?>>Nombre Completo</option>
                    <option value="origen_tabla" <?= $searchColumn === 'origen_tabla' ? 'selected' : '' ?>>Año de Expediente</option>
                    <option value="email" <?= $searchColumn === 'email' ? 'selected' : '' ?>>Email</option>
                    <option value="materia" <?= $searchColumn === 'materia' ? 'selected' : '' ?>>Materia / Curso</option>
                    <option value="proceso" <?= $searchColumn === 'proceso' ? 'selected' : '' ?>>Proceso</option>
                    <option value="grupo_objetivo" <?= $searchColumn === 'grupo_objetivo' ? 'selected' : '' ?>>Grupo Objetivo</option>
                    <option value="modalidad" <?= $searchColumn === 'modalidad' ? 'selected' : '' ?>>Modalidad</option>
                    <option value="nota" <?= $searchColumn === 'nota' ? 'selected' : '' ?>>Nota / Horas</option>
                    <option value="total" <?= $searchColumn === 'total' ? 'selected' : '' ?>>Total</option>
                    <option value="fecha_inicio" <?= $searchColumn === 'fecha_inicio' ? 'selected' : '' ?>>Fecha de Inicio</option>
                    <option value="fecha_fin" <?= $searchColumn === 'fecha_fin' ? 'selected' : '' ?>>Fecha de Fin</option>
                    <option value="aprueba" <?= $searchColumn === 'aprueba' ? 'selected' : '' ?>>Aprueba (SI/NO)</option>
                </select>
            </div>
            <div class="form-group" style="flex:1; margin:0;">
                <label for="searchTerm">Término de búsqueda</label>
                <input type="text" id="searchTerm" value="<?= e($searchTerm) ?>" placeholder="Escriba el valor a buscar..." onkeypress="if(event.key === 'Enter') searchRecords();">
            </div>
            <button class="btn btn-primary" onclick="searchRecords()">Buscar</button>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('newRecordModal').classList.add('active')">+ Nuevo Registro</button>
            <?php if ($searchTerm !== ''): ?>
                <a href="?tab=records" class="btn btn-secondary">Mostrar Todos</a>
            <?php endif; ?>
        </div>
        <?php if ($searchTerm !== ''): ?>
            <div class="alert alert-success" style="padding:8px 12px; font-size:13px;">
                Se encontraron <?= count($records) ?> coincidencias para "<strong><?= e($searchTerm) ?></strong>".
            </div>
        <?php endif; ?>
        <div class="table-container">
            <table>
                <thead>
                <tr>
                    <th>Año</th>
                    <th>Cédula</th><th>Nombre</th><th>Proceso</th><th>Materia</th><th>Grupo Obj.</th>
                    <th>Modalidad</th><th>Inicio</th><th>Fin</th><th>Nota</th><th>Total</th><th>Aprueba</th><th>Acción</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($records)): ?>
                    <tr><td colspan="13" style="text-align:center;">No se encontraron registros.</td></tr>
                <?php else: foreach ($records as $r): ?>
                    <tr>
                        <td><span class="badge-year"><?= e($r['origen_tabla'] ?? '') ?></span></td>
                        <td><?= e($r['cedula'] ?? '') ?></td>
                        <td><strong><?= e($r['nombre'] ?? '') ?></strong></td>
                        <td><?= e($r['proceso'] ?? '') ?></td>
                        <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;"><?= e($r['materia'] ?? '') ?></td>
                        <td><?= e($r['grupo_objetivo'] ?? '') ?></td>
                        <td><?= e($r['modalidad'] ?? '') ?></td>
                        <td><?= e($r['fecha_inicio'] ?? '') ?></td>
                        <td><?= e($r['fecha_fin'] ?? '') ?></td>
                        <td><?= e($r['nota'] ?? '') ?></td>
                        <td><?= e($r['total'] ?? '') ?></td>
                        <td><?= e($r['aprueba'] ?? '') ?></td>
                        <td>
                            <button class="btn btn-primary btn-sm" onclick='openEditModal(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'>Editar</button>
                            <form id="deleteRecordForm-<?= (int)($r['id'] ?? 0) ?>" action="?tab=records" method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="delete_record">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="delete_id" value="<?= (int)($r['id'] ?? 0) ?>">
                                <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteRecord('deleteRecordForm-<?= (int)($r['id'] ?? 0) ?>', '<?= e($r['cedula'] ?? '') ?>')">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1 && $searchTerm === ''): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?tab=records&page=1">&laquo; Primera</a>
                    <a href="?tab=records&page=<?= $page - 1 ?>">&lsaquo; Anterior</a>
                <?php endif; ?>

                <?php
                $rango = 2;
                $inicio = max(1, $page - $rango);
                $fin = min($totalPages, $page + $rango);
                if ($fin - $inicio < 4) {
                    if ($inicio == 1) {
                        $fin = min($totalPages, $inicio + 4);
                    } elseif ($fin == $totalPages) {
                        $inicio = max(1, $fin - 4);
                    }
                }
                if ($inicio > 1) {
                    echo '<span>…</span>';
                }
                for ($p = $inicio; $p <= $fin; $p++):
                    ?>
                    <a href="?tab=records&page=<?= $p ?>" class="<?= $p == $page ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor;
                if ($fin < $totalPages) {
                    echo '<span>…</span>';
                }
                ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?tab=records&page=<?= $page + 1 ?>">Siguiente &rsaquo;</a>
                    <a href="?tab=records&page=<?= $totalPages ?>">Última &raquo;</a>
                <?php endif; ?>
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
                <h3 style="color:#dc3545;">Limpiar Año (Borrado Masivo)</h3>
                <form id="deleteYearForm" action="?tab=import" method="POST" onsubmit="return confirmDeleteYear(event);">
                    <input type="hidden" name="action" value="delete_year_db">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <div class="form-group">
                        <label for="delete_year">Seleccione el año a vaciar</label>
                        <select id="delete_year" name="delete_year" required>
                            <option value="">--</option>
                            <?php foreach ($years as $y): ?>
                                <option value="<?= e($y) ?>"><?= e($y) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-danger">Eliminar Registros</button>
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
            <h3>Constructor Visual de Columnas PDF</h3>
            <div class="builder-toolbar">
                <label for="newColSelect" class="visually-hidden">Seleccionar columna</label>
                <select id="newColSelect" style="max-width: 250px;">
                    <option value="cedula">Cédula</option>
                    <option value="proceso">Proceso</option>
                    <option value="materia">Curso (Materia)</option>
                    <option value="grupo_objetivo">Grupo Objetivo</option>
                    <option value="modalidad">Modalidad</option>
                    <option value="nota">Nro. de Horas (Nota)</option>
                    <option value="fecha_inicio">Fecha Inicio</option>
                    <option value="fecha_fin">Fecha Fin</option>
                    <option value="total">Total</option>
                    <option value="aprueba">Aprueba</option>
                    <option value="periodo">Año (Periodo/Rango)</option>
                </select>
                <button type="button" class="btn btn-secondary btn-sm" onclick="addNewColumn()">Añadir a la Tabla</button>
                <div id="schemaMessage" class="schema-message" role="alert" style="display:none; color:#dc3545; font-weight:500; margin-left:15px;"></div>
            </div>

            <ul class="sortable-list" id="sortableSchema" style="margin-top:20px;">
                <?php foreach ($schema as $col): ?>
                    <?php
                    $key = $col['key'] ?? $col['field'] ?? '';
                    $uniqueId = 'vis_' . $key . '_' . uniqid();
                    ?>
                    <li class="sortable-item" draggable="true" data-key="<?= e($key) ?>">
                        <div class="drag-handle">☰</div>
                        <input type="checkbox" class="visible-checkbox" id="<?= e($uniqueId) ?>" <?= ($col['visible'] ?? true) ? 'checked' : '' ?> aria-label="Visible">
                        <span style="min-width:100px;font-family:monospace;">[<?= e($key) ?>]</span>
                        <input type="text" class="label-input" value="<?= e($col['label'] ?? '') ?>" aria-label="Etiqueta de columna">
                        <input type="number" class="width-input" value="<?= e($col['width'] ?? 20) ?>" aria-label="Ancho en mm"><span>mm</span>
                        <button type="button" class="del-btn" onclick="this.parentElement.remove(); updateSchemaJson();">❌</button>
                    </li>
                <?php endforeach; ?>
            </ul>

            <form action="?tab=schema" method="POST" style="margin-top:20px;">
                <input type="hidden" name="action" value="save_schema">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="schema_json" id="schema_json">
                <button type="submit" class="btn btn-primary" onclick="updateSchemaJson()">Guardar Estructura</button>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="editModal">
    <div class="modal-content">
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h3 style="margin:0; color:#003366;">Modificar Registro Extendido</h3>
            <button class="close-modal" onclick="closeModal('editModal')" style="border:none; background:none; font-size:24px; cursor:pointer;">&times;</button>
        </div>
        <form action="?tab=records" method="POST">
            <input type="hidden" name="action" value="update_record">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="edit_id" id="edit_id">

            <div class="grid-2">
                <div class="form-group">
                    <label for="edit_cedula">Cédula</label>
                    <input type="text" id="edit_cedula" readonly style="background:#eee;">
                </div>
                <div class="form-group">
                    <label for="edit_nombre">Nombre Completo</label>
                    <input type="text" name="edit_nombre" id="edit_nombre" required>
                </div>
            </div>

            <div class="form-group">
                <label for="edit_materia">Materia / Curso</label>
                <input type="text" name="edit_materia" id="edit_materia" required>
            </div>

            <div class="grid-3">
                <div class="form-group">
                    <label for="edit_proceso">Proceso</label>
                    <input type="text" name="edit_proceso" id="edit_proceso">
                </div>
                <div class="form-group">
                    <label for="edit_grupo">Grupo Objetivo</label>
                    <input type="text" name="edit_grupo" id="edit_grupo">
                </div>
                <div class="form-group">
                    <label for="edit_modalidad">Modalidad</label>
                    <input type="text" name="edit_modalidad" id="edit_modalidad">
                </div>
            </div>

            <div class="grid-3">
                <div class="form-group">
                    <label for="edit_inicio">Fecha Inicio</label>
                    <input type="text" name="edit_inicio" id="edit_inicio" placeholder="Ej: 01/01/2014">
                </div>
                <div class="form-group">
                    <label for="edit_fin">Fecha Fin</label>
                    <input type="text" name="edit_fin" id="edit_fin">
                </div>
                <div class="form-group">
                    <label for="edit_aprueba">Aprueba</label>
                    <input type="text" name="edit_aprueba" id="edit_aprueba">
                </div>
            </div>

            <div class="grid-3">
                <div class="form-group">
                    <label for="edit_nota">Nro. Horas (Nota)</label>
                    <input type="number" name="edit_nota" id="edit_nota" step="0.01">
                </div>
                <div class="form-group">
                    <label for="edit_total">Total Calificación</label>
                    <input type="number" name="edit_total" id="edit_total" step="0.01">
                </div>
                <div class="form-group">
                    <label for="edit_periodo">Periodo (Año)</label>
                    <input type="text" name="edit_periodo" id="edit_periodo">
                </div>
            </div>

            <div class="form-group" style="margin-top:10px;">
                <label for="edit_email">Email</label>
                <input type="text" name="edit_email" id="edit_email">
            </div>

            <div class="form-group" style="margin-top:10px;">
                <label for="edit_motivo">Motivo de la modificación</label>
                <input type="text" name="edit_motivo" id="edit_motivo" maxlength="250" required>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:15px;border-top:1px solid #eee;padding-top:15px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="deleteConfirmModal">
    <div class="modal-content modal-sm">
        <h3 style="color:#dc3545; margin-bottom: 15px;">⚠️ Confirmar Borrado Masivo</h3>
        <p style="margin-bottom: 20px; color:#555;">
            ¿Estás completamente seguro que deseas <strong>ELIMINAR TODOS</strong> los registros del año seleccionado? <br><br>
            <span style="color:#dc3545; font-weight:bold;">Esta acción no se puede deshacer.</span>
        </p>
        <div style="display:flex; gap:10px; justify-content:center;">
            <button type="button" class="btn btn-secondary" onclick="closeModal('deleteConfirmModal')">Cancelar</button>
            <button type="button" class="btn btn-danger" onclick="executeDeleteYear()">Sí, Eliminar Registros</button>
        </div>
    </div>
</div>

<div class="modal" id="deleteRecordModal">
    <div class="modal-content modal-sm">
        <h3 style="color:#dc3545; margin-bottom: 15px;">⚠️ Confirmar Eliminación</h3>
        <p id="deleteRecordMessage" style="margin-bottom: 20px; color:#555;"></p>
        <div style="display:flex; gap:10px; justify-content:center;">
            <button type="button" class="btn btn-secondary" onclick="closeModal('deleteRecordModal')">Cancelar</button>
            <button type="button" class="btn btn-danger" onclick="executeDeleteRecord()">Sí, Eliminar</button>
        </div>
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