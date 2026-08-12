<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
use App\Core\EntityManagerProvider;
use App\Core\ErrorHandler;
use App\Entity\AcademicRecord;
use App\Security\SecurityContext;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Manejo global de errores -- ver src/Core/ErrorHandler.php
ErrorHandler::register();

SecurityContext::ensureSession();

if (($_ENV['WORKSPACE_ACCESS_MODE'] ?? 'protected') === 'protected') {
    SecurityContext::requireRole($_ENV['KEYCLOAK_ROLE_USER'] ?? 'SECRE_ACADEMICO');
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
            ->andWhere("r.estado != 'X'")
            ->orderBy('r.anio', 'DESC')
            ->addOrderBy('r.id', 'DESC');
    $allRecords = array_map(
            static fn(AcademicRecord $r) => $r->toArray(),
            $qb->getQuery()->getResult()
    );
    $records = array_filter($allRecords, static function ($r) use ($startYear, $endYear) {
        $rYear = (int)($r['anio'] ?? 0);
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
        #toast { position: fixed; top: 24px; left: 50%; transform: translateX(-50%); padding: 14px 28px; border-radius: 8px; font-size: 14px; font-weight: 600; color: #fff; z-index: 9999; opacity: 0; transition: opacity 0.3s; pointer-events: none; max-width: 90vw; text-align: center; }
        #toast.show { opacity: 1; }
        #toast.success { background: #28a745; }
        #toast.error   { background: #dc3545; }
        .container { max-width: 1400px; margin: 0 auto; }
        .workspace-layout { display: grid; grid-template-columns: 420px 1fr; gap: 20px; margin-top: 20px; }
        @media(max-width: 1024px) {
            .workspace-layout { grid-template-columns: 1fr; }
        }
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
        .empty-view { display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; color: #94a3b8; text-align: center; padding: 40px; }
        .empty-view span { font-size: 50px; margin-bottom: 10px; }
        .btn-danger { background: #dc3545; color: white; }

        /* Visor propio con PDF.js sobre <canvas>: sin barra de herramientas del navegador */
        .pdf-canvas-container {
            width: 100%; flex: 1; min-height: 650px; overflow: auto;
            background: #edf2f7; padding: 16px;
            display: flex; flex-direction: column; align-items: center; gap: 16px;
            -webkit-user-select: none; user-select: none;
        }
        .pdf-canvas-container canvas {
            max-width: 100%; height: auto;
            box-shadow: 0 1px 6px rgba(0,0,0,.15); background: #fff;
        }
        .pdf-loading { color: #64748b; font-size: 14px; padding: 20px; }
    </style>
    <!-- PDF.js alojado localmente (build legacy, sin CDN) -->
    <script src="assets/pdfjs/pdf.min.js"></script>
    <script>
        if (window.pdfjsLib) {
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/pdfjs/pdf.worker.min.js';
        }
    </script>
    <script>
        // ---------- Visor PDF sobre <canvas> (PDF.js) ----------
        // Sin iframe → sin barra nativa del navegador → sin botones Guardar/Imprimir.
        let _pdfToken = 0;

        async function renderPdf(source) {
            const container = document.getElementById('pdfViewer');
            const empty     = document.getElementById('emptyState');
            if (!container) return false;

            const token = ++_pdfToken;
            container.innerHTML = '<div class="pdf-loading">Cargando expediente…</div>';
            container.style.display = 'flex';
            if (empty) empty.style.display = 'none';

            try {
                if (!window.pdfjsLib) throw new Error('PDF.js no cargó.');
                const params = typeof source === 'string'
                    ? { url: source, withCredentials: true }
                    : source;
                const pdf = await pdfjsLib.getDocument(params).promise;
                if (token !== _pdfToken) return false;

                container.innerHTML = '';
                const scale = 1.5, dpr = window.devicePixelRatio || 1;

                for (let n = 1; n <= pdf.numPages; n++) {
                    const page     = await pdf.getPage(n);
                    if (token !== _pdfToken) return false;
                    const viewport = page.getViewport({ scale });
                    const canvas   = document.createElement('canvas');
                    const ctx      = canvas.getContext('2d');
                    canvas.width   = Math.floor(viewport.width  * dpr);
                    canvas.height  = Math.floor(viewport.height * dpr);
                    canvas.style.width  = viewport.width  + 'px';
                    canvas.style.height = viewport.height + 'px';
                    container.appendChild(canvas);
                    await page.render({
                        canvasContext: ctx, viewport,
                        transform: dpr !== 1 ? [dpr, 0, 0, dpr, 0, 0] : null
                    }).promise;
                }
                return true;
            } catch (err) {
                if (token !== _pdfToken) return false;
                container.innerHTML = '<div class="pdf-loading">No se pudo mostrar el expediente.</div>';
                showToast('No se pudo mostrar el PDF.', 'error');
                return false;
            }
        }

        // Descarga el PDF (botón ⚡) y lo muestra en el visor con UNA sola
        // petición al servidor — mismos bytes, dos usos.
        async function generarYDescargar(url, nombreArchivo) {
            const resp = await fetch(url, { credentials: 'same-origin' });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const tipo = resp.headers.get('Content-Type') || '';
            if (!tipo.includes('application/pdf'))
                throw new Error('La respuesta no es un PDF (' + tipo + ')');

            const blob  = await resp.blob();

            // 1) Descarga
            const dlUrl = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = dlUrl; a.download = nombreArchivo;
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(() => URL.revokeObjectURL(dlUrl), 1500);

            // 2) Visor (bytes propios; PDF.js puede transferir el buffer)
            const data = new Uint8Array(await blob.arrayBuffer());
            return await renderPdf({ data });
        }

        function showToast(msg, type = 'success') {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.className = 'show ' + type;
            clearTimeout(t._timer);
            t._timer = setTimeout(() => { t.className = ''; }, 4000);
        }

        async function requestPdfReload() {
            const boton = document.getElementById('btnGenerar');
            boton.disabled = true;
            boton.textContent = 'Generando y archivando…';
            try {
                const response = await fetch('api.php?action=archive_pdf', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        cedula: document.getElementById('override_cedula').value,
                        start_year: document.getElementById('start_year').value,
                        end_year: document.getElementById('end_year').value,
                        name: document.getElementById('override_nombre').value,
                        email: document.getElementById('override_email').value,
                        periodo: document.getElementById('override_periodo').value,
                        extra1: document.getElementById('override_extra1').value,
                        extra2: document.getElementById('override_extra2').value,
                    }),
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.error || 'No se pudo archivar el PDF.');
                }
                const cedula  = encodeURIComponent(document.getElementById('override_cedula').value);
                const nombre  = encodeURIComponent(document.getElementById('override_nombre').value);
                const email   = encodeURIComponent(document.getElementById('override_email').value);
                const periodo = encodeURIComponent(document.getElementById('override_periodo').value);
                const extra1  = encodeURIComponent(document.getElementById('override_extra1').value);
                const extra2  = encodeURIComponent(document.getElementById('override_extra2').value);
                const start   = encodeURIComponent(document.getElementById('start_year').value);
                const end     = encodeURIComponent(document.getElementById('end_year').value);
                const pdfBase = 'PdfGenerator.php?cedula_query=' + cedula
                    + '&start=' + start + '&end=' + end
                    + '&name=' + nombre + '&email=' + email
                    + '&periodo=' + periodo
                    + '&extra1=' + extra1 + '&extra2=' + extra2
                    + '&_=' + Date.now();

                // Construye el nombre de descarga a partir de la cédula y la fecha
                const cedulaRaw = document.getElementById('override_cedula').value.replace(/[^0-9A-Za-z_-]/g, '');
                const fecha     = new Date().toISOString().slice(0, 10).replace(/-/g, '');
                const nombreArchivo = 'expediente_' + (cedulaRaw || 'academico') + '_' + fecha + '.pdf';

                // Genera, descarga y muestra en el visor con UNA sola petición
                const ok = await generarYDescargar(pdfBase, nombreArchivo);
                if (ok) {
                    showToast('PDF generado, archivado y descargado.', 'success');
                }
            } catch (err) {
                showToast('No se pudo generar el PDF. Intente nuevamente.', 'error');
            } finally {
                boton.disabled = false;
                boton.textContent = '⚡ Generar PDF';
            }
        }
    </script>
</head>
<body>
<div id="toast"></div>
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
                $first = reset($records);
                $nombreBase = trim((string)($first['nombre'] ?? ''));
                $apellido   = trim((string)($first['apellido'] ?? ''));
                if ($apellido !== '' && stripos($nombreBase, $apellido) === false) {
                    $nombreBase = trim($nombreBase . ' ' . $apellido);
                }
                if ($nombreBase === '') {
                    $nombreBase = 'Estudiante';
                }
                ?>
                <div class="card">
                    <h3>Verificación y Edición de Cabecera</h3>
                    <div class="form-group">
                        <label for="override_nombre">Nombre Completo</label>
                        <input type="text" id="override_nombre" value="<?= e($nombreBase) ?>">
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
                    <button type="button" id="btnGenerar" class="btn btn-success" onclick="requestPdfReload()">⚡ Generar PDF</button>
                </div>
            <?php endif; ?>
        </div>
        <div class="results-viewport">
            <div class="empty-view" id="emptyState" <?= (!empty($searchCedula) && !empty($records)) ? 'style="display:none;"' : '' ?>>
                <span>📄</span>
                <h3>Visor de Expedientes Académicos</h3>
                <p>Ingrese una cédula válida y presione generar PDF.</p>
            </div>
            <div class="pdf-canvas-container" id="pdfViewer"
                 style="<?= (!empty($searchCedula) && !empty($records)) ? '' : 'display:none;' ?>"></div>
        </div>
    </div>
</div>

<script>
    <?php if (!empty($searchCedula) && !empty($records)): ?>
    window.addEventListener('DOMContentLoaded', function () {
        renderPdf('PdfGenerator.php?cedula_query=<?= urlencode($searchCedula) ?>&start=<?= (int)$startYear ?>&end=<?= (int)$endYear ?>');
    });
    <?php endif; ?>

    // Disuasivos cosméticos sobre el visor (no son protección real)
    (function () {
        const viewer = document.getElementById('pdfViewer');
        if (viewer) {
            viewer.addEventListener('contextmenu', e => e.preventDefault());
            viewer.addEventListener('dragstart',   e => e.preventDefault());
        }
        document.addEventListener('keydown', function (e) {
            const k = (e.key || '').toLowerCase();
            if ((e.ctrlKey || e.metaKey) && (k === 's' || k === 'p')) {
                e.preventDefault();
                showToast('Acción deshabilitada.', 'error');
            }
        });
    })();
</script>

</body>
</html>