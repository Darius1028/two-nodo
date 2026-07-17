<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use App\Core\EntityManagerProvider;
use App\Entity\AcademicRecord;
use App\Security\SecurityContext;

SecurityContext::ensureSession();

if (($_ENV['WORKSPACE_ACCESS_MODE'] ?? 'protected') === 'protected') {
    SecurityContext::requireAuthentication();
}
$currentUser = SecurityContext::getCurrentUser();

$searchCedula = isset($_GET['cedula']) && is_string($_GET['cedula']) ? trim($_GET['cedula']) : '';
$startYear    = isset($_GET['start_year']) && is_numeric($_GET['start_year']) ? (int)$_GET['start_year'] : 2010;
$endYear      = isset($_GET['end_year'])   && is_numeric($_GET['end_year'])   ? (int)$_GET['end_year']   : (int)date('Y');

$records = [];
if ($searchCedula !== '') {
    $em = EntityManagerProvider::get();
    $qb = $em->createQueryBuilder()
            ->select('r')->from(AcademicRecord::class, 'r')
            ->where('r.cedula = :cedula')->setParameter('cedula', $searchCedula)
            ->orderBy('r.origen_tabla', 'DESC')
            ->addOrderBy('r.id', 'DESC');
    $allRecords = array_map(
            static fn(AcademicRecord $r) => $r->toArray(),
            $qb->getQuery()->getResult()
    );
    $records = array_filter($allRecords, static function ($r) use ($startYear, $endYear) {
        $rYear = (int)($r['origen_tabla'] ?? 0);
        return $rYear >= $startYear && $rYear <= $endYear;
    });
}

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Registro Académico</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; color: #333; line-height: 1.6; min-height: 100vh; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .workspace-layout { display: grid; grid-template-columns: 420px 1fr; gap: 20px; margin-top: 20px; }
        @media(max-width: 1024px) { .workspace-layout { grid-template-columns: 1fr; } }
        .panel-sidebar { display: flex; flex-direction: column; gap: 20px; }
        .card { background: white; border-radius: 10px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); }
        .card h3 { color: #003366; font-size: 1.1rem; margin-bottom: 15px; border-bottom: 2px solid #f0f2f5; padding-bottom: 8px; }
        .form-row { display: flex; gap: 10px; margin-bottom: 12px; }
        .form-group { margin-bottom: 14px; flex: 1; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: #666; margin-bottom: 4px; text-transform: uppercase; }
        input[type="text"], select { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; text-align: center; display: block; text-decoration: none; }
        .btn-primary { background: #003366; color: white; }
        .btn-success { background: #28a745; color: white; margin-top: 10px; }
        .results-viewport { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); display: flex; flex-direction: column; min-height: 600px; overflow: hidden; }
        .iframe-container { width: 100%; flex: 1; min-height: 650px; border: none; background: #edf2f7; }
        .empty-view { display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; color: #94a3b8; text-align: center; padding: 40px; }
        .empty-view span { font-size: 50px; margin-bottom: 10px; }
    </style>
    <script>
        function requestPdfReload() {
            const cedula  = encodeURIComponent(document.getElementById('override_cedula').value);
            const nombre  = encodeURIComponent(document.getElementById('override_nombre').value);
            const email   = encodeURIComponent(document.getElementById('override_email').value);
            const periodo = encodeURIComponent(document.getElementById('override_periodo').value);
            const extra1  = encodeURIComponent(document.getElementById('override_extra1').value);
            const extra2  = encodeURIComponent(document.getElementById('override_extra2').value);
            const start   = encodeURIComponent(document.getElementById('start_year').value);
            const end     = encodeURIComponent(document.getElementById('end_year').value);
            const iframe = document.getElementById('pdfIframe');
            if (iframe) {
                iframe.src = 'PdfGenerator.php?cedula_query=' + cedula
                    + '&start=' + start + '&end=' + end
                    + '&name=' + nombre + '&email=' + email
                    + '&periodo=' + periodo + '&extra1=' + extra1 + '&extra2=' + extra2
                    + '#toolbar=1';
                iframe.style.display = 'block';
                document.getElementById('emptyState').style.display = 'none';
            }
        }
    </script>
</head>
<body>
<div class="container">
    <?php require_once __DIR__ . '/../templates/includes/header.php'; ?>

    <div class="workspace-layout">
        <div class="panel-sidebar">
            <div class="card">
                <h3>Filtros de Búsqueda</h3>
                <form method="GET" action="">
                    <div class="form-group">
                        <label for="cedulaInput">Cédula del Alumno</label>
                        <input type="text" id="cedulaInput" name="cedula" placeholder="Ingrese cédula" value="<?= e($searchCedula) ?>" required autocomplete="off">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="start_year">Año Desde</label>
                            <select id="start_year" name="start_year">
                                <?php for ($i = (int)date('Y'); $i >= 2000; $i--): ?>
                                    <option value="<?= $i ?>" <?= $i === $startYear ? 'selected' : '' ?>><?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="end_year">Año Hasta</label>
                            <select id="end_year" name="end_year">
                                <?php for ($i = (int)date('Y'); $i >= 2000; $i--): ?>
                                    <option value="<?= $i ?>" <?= $i === $endYear ? 'selected' : '' ?>><?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Buscar Historial</button>
                </form>
            </div>
            <?php if (!empty($searchCedula) && !empty($records)):
                $first = reset($records); ?>
                <div class="card">
                    <h3>Verificación y Edición de Cabecera</h3>
                    <div class="form-group">
                        <label for="override_nombre">Nombre Completo</label>
                        <input type="text" id="override_nombre" value="<?= e($first['nombre'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="override_cedula">Cédula</label>
                        <input type="text" id="override_cedula" value="<?= e($first['cedula'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="override_email">Correo</label>
                        <input type="text" id="override_email" value="<?= e($first['email'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="override_periodo">Periodo</label>
                        <input type="text" id="override_periodo" value="<?= e($startYear . ' - ' . $endYear) ?>">
                    </div>
                    <div class="form-group">
                        <label for="override_extra1">Línea Personalizada 1</label>
                        <input type="text" id="override_extra1" placeholder="Ej: Carrera: Ciencias Jurídicas">
                    </div>
                    <div class="form-group">
                        <label for="override_extra2">Línea Personalizada 2</label>
                        <input type="text" id="override_extra2" placeholder="Ej: Modalidad Regular">
                    </div>
                    <button type="button" class="btn btn-success" onclick="requestPdfReload()">⚡ Generar PDF</button>
                </div>
            <?php endif; ?>
        </div>
        <div class="results-viewport">
            <div class="empty-view" id="emptyState" <?= (!empty($searchCedula) && !empty($records)) ? 'style="display:none;"' : '' ?>>
                <span>📄</span>
                <h3>Visor de Expedientes Académicos</h3>
                <p>Ingrese una cédula válida y presione generar PDF.</p>
            </div>
            <?php if (!empty($searchCedula) && !empty($records)): ?>
                <iframe class="iframe-container" id="pdfIframe"
                        src="PdfGenerator.php?cedula_query=<?= urlencode($searchCedula) ?>&start=<?= e($startYear) ?>&end=<?= e($endYear) ?>#toolbar=1"
                        title="Visor PDF"></iframe>
            <?php else: ?>
                <iframe class="iframe-container" id="pdfIframe" style="display:none;" title="Visor PDF"></iframe>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>