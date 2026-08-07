<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\EntityManagerProvider;
use App\Service\CsvService;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$appTimezone = trim((string)($_ENV['APP_TIMEZONE'] ?? 'America/Guayaquil'));
date_default_timezone_set($appTimezone);

const POLL_INTERVAL_SECONDS = 3;
const PROGRESS_UPDATE_EVERY = 2000;
const MAX_RECONNECT_ATTEMPTS = 5;
const RECONNECT_DELAY_SECONDS = 5;
const STUCK_JOB_TIMEOUT_HOURS = 2;

fwrite(STDOUT, "[import-worker] iniciado, revisando Academico.ImportJob cada " . POLL_INTERVAL_SECONDS . "s\n");

// ============================================================
// RECOVERY: Jobs stuck en PROCESANDO por más de X horas
// ============================================================
try {
    $conn = EntityManagerProvider::get()->getConnection();

    // Recuperar jobs stuck
    $recuperados = $conn->executeStatement(
        "UPDATE Academico.ImportJob
         SET estado = 'PENDIENTE',
             fechaInicio = NULL,
             mensajeError = 'Recovery: job stuck detectado automáticamente'
         WHERE estado = 'PROCESANDO'
         AND fechaInicio < DATEADD(hour, -" . STUCK_JOB_TIMEOUT_HOURS . ", GETDATE())"
    );

    if ($recuperados > 0) {
        fwrite(STDOUT, "[import-worker] $recuperados trabajo(s) PROCESANDO recuperado(s) tras reinicio.\n");
    }

    // Log de jobs actualmente en PROCESANDO (para debugging)
    $jobsProcesando = $conn->fetchAllAssociative(
        "SELECT id, fechaInicio, DATEDIFF(minute, fechaInicio, GETDATE()) as minutos_activos
         FROM Academico.ImportJob
         WHERE estado = 'PROCESANDO'
         ORDER BY id"
    );

    if (!empty($jobsProcesando)) {
        fwrite(STDOUT, "[import-worker] Jobs actualmente en PROCESANDO:\n");
        foreach ($jobsProcesando as $job) {
            fwrite(STDOUT, sprintf(
                "  - Job #%d: iniciado hace %d minutos (%s)\n",
                $job['id'],
                $job['minutos_activos'],
                $job['fechaInicio']
            ));
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '[import-worker] no se pudo ejecutar la recuperación de arranque: ' . $e->getMessage() . "\n");
}

// ============================================================
// LIMPIEZA DE JOBS ANTIGUOS
// ============================================================
try {
    $diasRetencion = (int)($_ENV['IMPORT_JOB_RETENTION_DAYS'] ?? 30);
    $eliminados = $conn->executeStatement(
        "DELETE FROM Academico.ImportJob
         WHERE estado IN ('COMPLETADO', 'ERROR')
         AND fechaFin < DATEADD(day, -{$diasRetencion}, GETDATE())"
    );
    if ($eliminados > 0) {
        fwrite(STDOUT, "[import-worker] $eliminados job(s) antiguo(s) eliminado(s).\n");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '[import-worker] Error en limpieza de jobs antiguos: ' . $e->getMessage() . "\n");
}

// ============================================================
// MANEJO DE SEÑALES (SIGTERM, SIGINT)
// ============================================================
$debeDetenerse = false;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);

    pcntl_signal(SIGTERM, function () use (&$debeDetenerse) {
        $debeDetenerse = true;
        fwrite(STDOUT, "[import-worker] SIGTERM recibido, se detendrá tras el trabajo actual.\n");
    });

    pcntl_signal(SIGINT, function () use (&$debeDetenerse) {
        $debeDetenerse = true;
        fwrite(STDOUT, "[import-worker] SIGINT recibido (Ctrl+C), se detendrá tras el trabajo actual.\n");
    });
}

// ============================================================
// FUNCIÓN DE RECONEXIÓN
// ============================================================
function reconectarBaseDeDatos(int $intentos = MAX_RECONNECT_ATTEMPTS): bool
{
    for ($i = 1; $i <= $intentos; $i++) {
        fwrite(STDOUT, "[import-worker] Intento de reconexión $i/$intentos...\n");

        try {
            EntityManagerProvider::reset();
            $conn = EntityManagerProvider::get()->getConnection();
            $conn->executeStatement("SELECT 1");

            fwrite(STDOUT, "[import-worker] Reconexión exitosa.\n");
            return true;
        } catch (\Throwable $e) {
            fwrite(STDERR, "[import-worker] Reconexión falló: " . $e->getMessage() . "\n");

            if ($i < $intentos) {
                sleep(RECONNECT_DELAY_SECONDS);
            }
        }
    }

    return false;
}

// ============================================================
// BUCLE PRINCIPAL
// ============================================================
while (!$debeDetenerse) {
    try {
        $job = tomarSiguienteJob();

        if ($job === null) {
            sleep(POLL_INTERVAL_SECONDS);
            continue;
        }

        procesarJob($job);

    } catch (\Throwable $e) {
        fwrite(STDERR, '[import-worker] error inesperado: ' . $e->getMessage() . "\n");

        // Intentar reconectar
        if (!reconectarBaseDeDatos()) {
            // SOLUCIÓN: Concatenar la constante en lugar de usar $
            fwrite(STDERR, "[import-worker] No se pudo reconectar después de " . MAX_RECONNECT_ATTEMPTS . " intentos. Esperando " . (POLL_INTERVAL_SECONDS * 10) . "s...\n");
            sleep(POLL_INTERVAL_SECONDS * 10);
        }
    }
}

fwrite(STDOUT, "[import-worker] detenido.\n");
exit(0);

// ============================================================
// FUNCIONES AUXILIARES
// ============================================================

/**
 * Busca el trabajo PENDIENTE más antiguo y lo marca PROCESANDO
 */
function tomarSiguienteJob(): ?array
{
    $conn = EntityManagerProvider::get()->getConnection();

    $job = $conn->fetchAssociative(
        "SELECT TOP 1 id, rutaArchivo, anio, idPersonaCrea, ipCrea, equipoCrea
         FROM Academico.ImportJob
         WHERE estado = 'PENDIENTE'
         ORDER BY id ASC"
    );

    if ($job === false) {
        return null;
    }

    $filas = $conn->executeStatement(
        "UPDATE Academico.ImportJob
         SET estado = 'PROCESANDO', fechaInicio = GETDATE()
         WHERE id = ? AND estado = 'PENDIENTE'",
        [$job['id']]
    );

    if ($filas === 0) {
        return null;
    }

    return $job;
}

/**
 * Procesa un job de importación
 */
function procesarJob(array $job): void
{
    $conn = EntityManagerProvider::get()->getConnection();
    $jobId = (int)$job['id'];

    fwrite(STDOUT, sprintf(
        "[import-worker] trabajo #%d: procesando %s (año %d)\n",
        $jobId,
        $job['rutaArchivo'],
        $job['anio']
    ));

    $onProgress = static function (int $count) use ($conn, $jobId): void {
        try {
            $conn->executeStatement(
                'UPDATE Academico.ImportJob SET filasProcesadas = ? WHERE id = ?',
                [$count, $jobId]
            );
        } catch (\Throwable $e) {
            fwrite(STDERR, "[import-worker] no se pudo actualizar el avance: " . $e->getMessage() . "\n");
        }
    };

    try {
        $rutaArchivo = (string)($job['rutaArchivo'] ?? '');

        if (!is_file($rutaArchivo) || !is_readable($rutaArchivo)) {
            $detalle = !is_file($rutaArchivo)
                ? 'el archivo no existe en la ruta guardada'
                : 'el archivo existe pero no es legible';
            $mensaje = sprintf(
                'No se pudo abrir el archivo CSV: %s. Ruta: %s',
                $detalle,
                $rutaArchivo
            );
            fwrite(STDERR, sprintf(
                "[import-worker] trabajo #%d falló: %s\n",
                $jobId,
                $mensaje
            ));
            marcarError($conn, $jobId, $mensaje);
            return;
        }

        $resultado = CsvService::importCSV(
            $rutaArchivo,
            (int)$job['anio'],
            (int)$job['idPersonaCrea'],
            (string)$job['ipCrea'],
            (string)$job['equipoCrea'],
            $onProgress
        );

        // Early Return si falla la importación
        if (!$resultado['success']) {
            $mensaje = implode('; ', $resultado['errors']);
            marcarError($conn, $jobId, $mensaje);
            return;
        }

        // Manejo del éxito delegado a otras funciones
        $actualizado = marcarTrabajoCompletado($conn, $jobId, $resultado['imported']);

        if (!$actualizado) {
            fwrite(STDERR, "[import-worker] CRÍTICO: No se pudo marcar el job #$jobId como COMPLETADO.\n");
        } else {
            fwrite(STDOUT, sprintf(
                "[import-worker] trabajo #%d completado: %d filas.\n",
                $jobId,
                $resultado['imported']
            ));
        }

        limpiarArchivoCsv($job['rutaArchivo']);

    } catch (\Throwable $e) {
        marcarError($conn, $jobId, $e->getMessage());
    }
}

/**
 * Intenta actualizar el estado del trabajo a COMPLETADO con reintentos
 */
function marcarTrabajoCompletado(\Doctrine\DBAL\Connection &$conn, int $jobId, int $filasImportadas): bool
{
    $intentos = 0;
    $maxIntentos = 3;

    while ($intentos < $maxIntentos) {
        try {
            $conn->executeStatement(
                "UPDATE Academico.ImportJob
                 SET estado = 'COMPLETADO',
                     filasImportadas = ?,
                     filasProcesadas = ?,
                     fechaFin = GETDATE()
                 WHERE id = ?",
                [$filasImportadas, $filasImportadas, $jobId]
            );
            return true;

        } catch (\Throwable $updateError) {
            $intentos++;
            fwrite(STDERR, "[import-worker] Intento $intentos/$maxIntentos de actualizar estado final falló: " . $updateError->getMessage() . "\n");

            if ($intentos < $maxIntentos) {
                sleep(3);
                reconectarBaseDeDatos(1);
                // Refrescar la conexión después del reconect
                $conn = EntityManagerProvider::get()->getConnection();
            }
        }
    }

    return false;
}

/**
 * Limpia el archivo físico una vez importado
 */
function limpiarArchivoCsv(string $rutaArchivo): void
{
    if (is_file($rutaArchivo)) {
        @unlink($rutaArchivo);
        fwrite(STDOUT, "[import-worker] Archivo CSV eliminado: {$rutaArchivo}\n");
    }
}

/**
 * Marca un job como ERROR
 */
function marcarError(\Doctrine\DBAL\Connection $conn, int $jobId, string $mensaje): void
{
    fwrite(STDERR, sprintf("[import-worker] trabajo #%d falló: %s\n", $jobId, $mensaje));

    try {
        $conn->executeStatement(
            "UPDATE Academico.ImportJob
             SET estado = 'ERROR',
                 mensajeError = ?,
                 fechaFin = GETDATE()
             WHERE id = ?",
            [$mensaje, $jobId]
        );
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf(
            "[import-worker] ADEMÁS no se pudo marcar el trabajo #%d como ERROR (%s).\n",
            $jobId,
            $e->getMessage()
        ));
    }
}
