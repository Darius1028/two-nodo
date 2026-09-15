<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\EntityManagerProvider;
use App\Exception\StorageException;
use App\Service\CsvService;
use App\Service\ImportFileStorage;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$appTimezone = trim((string)($_ENV['APP_TIMEZONE'] ?? 'America/Guayaquil'));
date_default_timezone_set($appTimezone);

const POLL_INTERVAL_SECONDS = 3;
const PROGRESS_UPDATE_EVERY = 2000;
const MAX_RECONNECT_ATTEMPTS = 5;
const RECONNECT_DELAY_SECONDS = 5;
const STUCK_JOB_TIMEOUT_HOURS = 2;
const MAX_STORAGE_RETRIES = 5;
const IMPORT_LOCK_RESOURCE = 'record-academico:csv-import';

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
             workerId = NULL,
             proximoIntento = NULL,
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
    $workerId = substr((gethostname() ?: 'worker') . ':' . getmypid(), 0, 100);

    $job = $conn->fetchAssociative(
        ";WITH siguiente AS (
            SELECT TOP (1) *
            FROM Academico.ImportJob WITH (UPDLOCK, READPAST, ROWLOCK)
            WHERE estado = 'PENDIENTE'
              AND (proximoIntento IS NULL OR proximoIntento <= GETDATE())
            ORDER BY id ASC
         )
         UPDATE siguiente
            SET estado = 'PROCESANDO', fechaInicio = GETDATE(), workerId = ?
         OUTPUT INSERTED.id, INSERTED.rutaArchivo, INSERTED.storageBucket,
                INSERTED.objectKey, INSERTED.objectVersionId, INSERTED.objectETag,
                INSERTED.sha256, INSERTED.tamanoBytes, INSERTED.intentos,
                INSERTED.anio, INSERTED.idPersonaCrea, INSERTED.ipCrea,
                INSERTED.equipoCrea;",
        [$workerId]
    );

    if ($job === false) {
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
    $lockConnection = $conn;
    $jobId = (int)$job['id'];
    $temporaryPath = null;
    $lockAcquired = false;
    $fileStorage = new ImportFileStorage();

    fwrite(STDOUT, sprintf(
        "[import-worker] trabajo #%d: procesando %s (año %d)\n",
        $jobId,
        $job['objectKey'] ?: $job['rutaArchivo'],
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
        $lockAcquired = adquirirBloqueoGlobal($lockConnection);
        if (!$lockAcquired) {
            devolverPendientePorBloqueo($conn, $jobId);
            return;
        }

        $objectKey = trim((string) ($job['objectKey'] ?? ''));
        if ($objectKey !== '') {
            $temporaryPath = $fileStorage->downloadTemporary(
                (string) ($job['storageBucket'] ?? ''),
                $objectKey,
                isset($job['objectVersionId']) ? (string) $job['objectVersionId'] : null,
                (int) ($job['tamanoBytes'] ?? 0),
                (string) ($job['sha256'] ?? '')
            );
            $rutaArchivo = $temporaryPath;
        } else {
            // Compatibilidad temporal para jobs creados antes de la migración.
            $rutaArchivo = (string) ($job['rutaArchivo'] ?? '');
        }

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
            if (marcarError($conn, $jobId, $mensaje)) {
                limpiarFuenteImportacion($fileStorage, $job);
            }
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
            limpiarFuenteImportacion($fileStorage, $job);
        }
    } catch (StorageException $e) {
        $attempts = (int) ($job['intentos'] ?? 0);
        if ($e->isRetryable() && $attempts < MAX_STORAGE_RETRIES) {
            reprogramarPorAlmacenamiento($conn, $jobId, $attempts, $e->getMessage());
        } else {
            if (marcarError($conn, $jobId, $e->getMessage())) {
                limpiarFuenteImportacion($fileStorage, $job);
            }
        }
    } catch (\Throwable $e) {
        if (marcarError($conn, $jobId, $e->getMessage())) {
            limpiarFuenteImportacion($fileStorage, $job);
        }
    } finally {
        if ($temporaryPath !== null && is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
        if ($lockAcquired) {
            liberarBloqueoGlobal($lockConnection);
        }
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
                     fechaFin = GETDATE(),
                     workerId = NULL,
                     proximoIntento = NULL
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
function marcarError(\Doctrine\DBAL\Connection $conn, int $jobId, string $mensaje): bool
{
    fwrite(STDERR, sprintf("[import-worker] trabajo #%d falló: %s\n", $jobId, $mensaje));

    try {
        $conn->executeStatement(
            "UPDATE Academico.ImportJob
             SET estado = 'ERROR',
                 mensajeError = ?,
                 fechaFin = GETDATE(),
                 workerId = NULL,
                 proximoIntento = NULL
             WHERE id = ?",
            [$mensaje, $jobId]
        );
        return true;
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf(
            "[import-worker] ADEMÁS no se pudo marcar el trabajo #%d como ERROR (%s).\n",
            $jobId,
            $e->getMessage()
        ));
        return false;
    }
}

function limpiarFuenteImportacion(ImportFileStorage $storage, array $job): void
{
    $objectKey = trim((string) ($job['objectKey'] ?? ''));
    if ($objectKey === '') {
        limpiarArchivoCsv((string) ($job['rutaArchivo'] ?? ''));
        return;
    }

    try {
        $storage->delete(
            (string) ($job['storageBucket'] ?? ''),
            $objectKey,
            isset($job['objectVersionId']) ? (string) $job['objectVersionId'] : null
        );
        fwrite(STDOUT, "[import-worker] Objeto CSV eliminado: {$objectKey}\n");
    } catch (\Throwable $e) {
        // El lifecycle del bucket funciona como red de seguridad.
        fwrite(STDERR, "[import-worker] No se pudo eliminar el objeto {$objectKey}: {$e->getMessage()}\n");
    }
}

function reprogramarPorAlmacenamiento(
    \Doctrine\DBAL\Connection $conn,
    int $jobId,
    int $attempts,
    string $message
): void {
    $delay = min(900, 30 * (2 ** $attempts));
    $conn->executeStatement(
        "UPDATE Academico.ImportJob
         SET estado = 'PENDIENTE',
             intentos = intentos + 1,
             proximoIntento = DATEADD(second, ?, GETDATE()),
             fechaInicio = NULL,
             workerId = NULL,
             mensajeError = ?
         WHERE id = ?",
        [$delay, 'Error temporal de almacenamiento: ' . $message, $jobId]
    );
    fwrite(STDERR, "[import-worker] trabajo #{$jobId} reprogramado en {$delay}s por almacenamiento.\n");
}

function devolverPendientePorBloqueo(\Doctrine\DBAL\Connection $conn, int $jobId): void
{
    $conn->executeStatement(
        "UPDATE Academico.ImportJob
         SET estado = 'PENDIENTE', fechaInicio = NULL, workerId = NULL,
             proximoIntento = DATEADD(second, 10, GETDATE())
         WHERE id = ?",
        [$jobId]
    );
}

function adquirirBloqueoGlobal(\Doctrine\DBAL\Connection $conn): bool
{
    $result = $conn->fetchOne(
        "DECLARE @resultado int;
         EXEC @resultado = sys.sp_getapplock
              @Resource = '" . IMPORT_LOCK_RESOURCE . "',
              @LockMode = 'Exclusive',
              @LockOwner = 'Session',
              @LockTimeout = 0;
         SELECT @resultado;"
    );
    return $result !== false && (int) $result >= 0;
}

function liberarBloqueoGlobal(\Doctrine\DBAL\Connection $conn): void
{
    try {
        $conn->executeStatement(
            "EXEC sys.sp_releaseapplock
             @Resource = '" . IMPORT_LOCK_RESOURCE . "',
             @LockOwner = 'Session';"
        );
    } catch (\Throwable $e) {
        fwrite(STDERR, '[import-worker] No se pudo liberar el bloqueo global: ' . $e->getMessage() . "\n");
    }
}
