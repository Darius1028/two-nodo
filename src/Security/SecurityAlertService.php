<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\EntityManagerProvider;
use App\Exception\InvalidConfigurationException;
use App\Exception\SystemException;
use App\Service\ConfigService;
use Throwable;

/**
 * Registro de alertas de seguridad con severidad (escala 1-10).
 *
 * En un despliegue multinodo cada alerta se persiste en SQL Server. El JSON
 * gitignored se conserva para desarrollo de un solo nodo. En ambos modos se
 * emite por error_log para que el SIEM pueda capturarla.
 */
final class SecurityAlertService
{
    /** Nivel alto de severidad (intentos de evasión / contenidos peligrosos). */
    public const LEVEL_HIGH = 9;

    /** Nivel medio de severidad (anomalías, correlaciones). */
    public const LEVEL_MEDIUM = 7;

    private const MAX_STORED_ALERTS = 1000;

    /**
     * @param array<string, mixed> $data
     */
    public static function log(int $level, string $type, array $data = []): void
    {
        $entry = array_merge([
            'timestamp' => date('Y-m-d H:i:s'),
            'nivel'     => $level,
            'tipo'      => $type,
        ], $data);

        error_log('[SEGURIDAD][NIVEL ' . $level . '] ' . json_encode($entry, JSON_UNESCAPED_UNICODE));

        self::persist($entry);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function persist(array $entry): void
    {
        try {
            $storage = $_ENV['SECURITY_ALERT_STORAGE'] ?? (getenv('SECURITY_ALERT_STORAGE') ?: 'file');
            $mode = strtolower(trim((string) $storage));
            if (!in_array($mode, ['file', 'database'], true)) {
                throw new InvalidConfigurationException(
                    'SECURITY_ALERT_STORAGE debe ser "file" o "database".'
                );
            }
            if ($mode === 'database') {
                EntityManagerProvider::get()->getConnection()->insert('Academico.AlertaSeguridad', [
                    'fecha' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                    'nivel' => (int) ($entry['nivel'] ?? 0),
                    'tipo' => mb_substr((string) ($entry['tipo'] ?? 'unknown'), 0, 100),
                    'datosJson' => json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'nodo' => mb_substr(gethostname() ?: 'unknown', 0, 100),
                ]);
                return;
            }

            $path = ConfigService::getSecurityAlertPath();
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new SystemException("No se pudo crear el directorio de alertas: {$dir}");
            }

            $alerts = file_exists($path)
                ? (json_decode((string)file_get_contents($path), true) ?? [])
                : [];
            if (!is_array($alerts)) {
                $alerts = [];
            }
            array_unshift($alerts, $entry);
            $alerts = array_slice($alerts, 0, self::MAX_STORED_ALERTS);

            $json = json_encode($alerts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($path, $json, LOCK_EX) === false) {
                throw new SystemException("No se pudo escribir el registro de alertas: {$path}");
            }
        } catch (Throwable $e) {
            error_log('[SEGURIDAD] No se pudo persistir la alerta: ' . $e->getMessage());
        }
    }
}
