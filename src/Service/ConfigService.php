<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\EntityManagerProvider;
use App\Exception\InvalidConfigurationException;
use App\Exception\SystemException;

class ConfigService
{
    private static ?array $config = null;
    private const PATH = __DIR__ . '/../../config/config.json';
    private const DATABASE_TABLE = 'Academico.ConfiguracionAplicacion';
    private const ALLOWED_CONFIG_KEYS = [
        'qr_enabled', 'qr_base_url', 'column_schema',
        'letterhead_image', 'signature_image',
        'letterhead_asset', 'signature_asset',
    ];

    public static function get(): array
    {
        if (self::$config === null) {
            $defaults = self::getFileConfig();
            if (!self::usesDatabase()) {
                self::$config = $defaults;
                return self::$config;
            }

            try {
                $rows = EntityManagerProvider::get()->getConnection()->fetchAllAssociative(
                    'SELECT clave, valorJson FROM ' . self::DATABASE_TABLE
                );
                foreach ($rows as $row) {
                    $key = (string) ($row['clave'] ?? '');
                    if (!in_array($key, self::ALLOWED_CONFIG_KEYS, true)) {
                        continue;
                    }
                    $defaults[$key] = json_decode((string) ($row['valorJson'] ?? 'null'), true, 512, JSON_THROW_ON_ERROR);
                }
                self::$config = $defaults;
            } catch (\Throwable $e) {
                throw new SystemException(
                    'No se pudo leer la configuración compartida. Verifique la migración de ConfiguracionAplicacion.',
                    0,
                    $e
                );
            }
        }
        return self::$config;
    }

    public static function set(array $config): bool
    {
        $filtered = array_intersect_key($config, array_flip(self::ALLOWED_CONFIG_KEYS));
        if (!self::usesDatabase()) {
            self::$config = $filtered;
            $json = json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return $json !== false && file_put_contents(self::PATH, $json, LOCK_EX) !== false;
        }

        $connection = EntityManagerProvider::get()->getConnection();
        $connection->beginTransaction();
        try {
            foreach ($filtered as $key => $value) {
                self::persistDatabaseValue($connection, (string) $key, $value);
            }
            $connection->commit();
            self::$config = null;
            return true;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            error_log('[ConfigService] No se pudo guardar la configuración compartida: ' . $e->getMessage());
            return false;
        }
    }

    public static function setValue(string $key, mixed $value): bool
    {
        if (!in_array($key, self::ALLOWED_CONFIG_KEYS, true)) {
            return false;
        }
        if (!self::usesDatabase()) {
            $config = self::get();
            $config[$key] = $value;
            return self::set($config);
        }

        $connection = EntityManagerProvider::get()->getConnection();
        $connection->beginTransaction();
        try {
            self::persistDatabaseValue($connection, $key, $value);
            $connection->commit();
            self::$config = null;
            return true;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            error_log("[ConfigService] No se pudo guardar {$key}: " . $e->getMessage());
            return false;
        }
    }

    public static function isQrEnabled(): bool
    {
        return (bool)(self::get()['qr_enabled'] ?? true);
    }

    public static function getQrBaseUrl(): string
    {
        // Prioridad: .env (por entorno: dev/ngrok/producción, sin tocar
        // config.json ni redeploy) -> config.json (editable en runtime si
        // en algún momento se repone un campo en el panel) -> default fijo.
        $fromEnv = trim((string)($_ENV['QR_BASE_URL'] ?? ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }
        return (string)(self::get()['qr_base_url'] ?? 'https://escuela.funcionjudicial.gob.ec/verificar-record/');
    }

    public static function getColumnSchema(): array
    {
        return self::get()['column_schema'] ?? [];
    }

    private const ALLOWED_COLUMN_KEYS = [
        'cedula', 'nombre', 'apellido', 'email', 'curso', 'materia',
        'proceso', 'anio', 'grupo_objetivo', 'modalidad', 'nro_horas',
        'genero', 'tipo', 'cargo', 'provincia', 'total',
        'fecha_inicio', 'fecha_fin', 'aprueba', 'periodo',
    ];

    public static function setColumnSchema(array $schema): bool
    {
        foreach ($schema as $col) {
            $key = $col['key'] ?? $col['field'] ?? '';
            if ($key === '' || !in_array($key, self::ALLOWED_COLUMN_KEYS, true)) {
                return false;
            }
        }
        return self::setValue('column_schema', $schema);
    }

    public static function toggleQr(): bool
    {
        if (!self::usesDatabase()) {
            $config = self::get();
            return self::setValue('qr_enabled', !($config['qr_enabled'] ?? false));
        }

        $connection = EntityManagerProvider::get()->getConnection();
        $connection->beginTransaction();
        try {
            $stored = $connection->fetchOne(
                'SELECT valorJson FROM ' . self::DATABASE_TABLE . ' WITH (UPDLOCK, HOLDLOCK) WHERE clave = ?',
                ['qr_enabled']
            );
            $current = $stored === false
                ? (bool) (self::getFileConfig()['qr_enabled'] ?? true)
                : (bool) json_decode((string) $stored, true, 512, JSON_THROW_ON_ERROR);
            self::persistDatabaseValue($connection, 'qr_enabled', !$current);
            $connection->commit();
            self::$config = null;
            return true;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            error_log('[ConfigService] No se pudo alternar qr_enabled: ' . $e->getMessage());
            return false;
        }
    }

    public static function resetCache(): void
    {
        self::$config = null;
    }

    private static function usesDatabase(): bool
    {
        $value = $_ENV['CONFIG_STORAGE'] ?? (getenv('CONFIG_STORAGE') ?: 'file');
        $mode = strtolower(trim((string) $value));
        if (!in_array($mode, ['file', 'database'], true)) {
            throw new InvalidConfigurationException('CONFIG_STORAGE debe ser "file" o "database".');
        }
        return $mode === 'database';
    }

    private static function getFileConfig(): array
    {
        if (!is_file(self::PATH) || !is_readable(self::PATH)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents(self::PATH), true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function persistDatabaseValue(\Doctrine\DBAL\Connection $connection, string $key, mixed $value): void
    {
        if (!in_array($key, self::ALLOWED_CONFIG_KEYS, true)) {
            throw new \InvalidArgumentException('Clave de configuración no permitida.');
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $exists = $connection->fetchOne(
            'SELECT clave FROM ' . self::DATABASE_TABLE . ' WITH (UPDLOCK, HOLDLOCK) WHERE clave = ?',
            [$key]
        );
        if ($exists === false) {
            $connection->insert(self::DATABASE_TABLE, [
                'clave' => $key,
                'valorJson' => $json,
                'fechaModifica' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            ]);
            return;
        }

        $connection->update(
            self::DATABASE_TABLE,
            ['valorJson' => $json, 'fechaModifica' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u')],
            ['clave' => $key]
        );
    }

    public static function getHistorialPath(): string
    {
        return __DIR__ . '/../../var/log/historial.json';
    }

    public static function getSecurityAlertPath(): string
    {
        return __DIR__ . '/../../var/log/security_alerts.json';
    }

    // --- PAdES / Firma digital PDF  ---

    public static function isPadesSignEnabled(): bool
    {
        $raw = trim((string)($_ENV['PADES_SIGN_ENABLED'] ?? ''));
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public static function getPadesCertPath(): string
    {
        return trim((string)($_ENV['PADES_CERT_PATH'] ?? ''));
    }

    public static function getPadesCertPassword(): string
    {
        return (string)($_ENV['PADES_CERT_PASSWORD'] ?? '');
    }

    public static function getPadesSignerName(): string
    {
        $v = trim((string)($_ENV['PADES_SIGNER_NAME'] ?? ''));
        return $v !== '' ? $v : 'Institución';
    }

    public static function getPadesSignerReason(): string
    {
        $v = trim((string)($_ENV['PADES_SIGNER_REASON'] ?? ''));
        return $v !== '' ? $v : 'Certificación de documento oficial';
    }

    public static function getPadesSignerLocation(): string
    {
        $v = trim((string)($_ENV['PADES_SIGNER_LOCATION'] ?? ''));
        return $v !== '' ? $v : 'Ecuador';
    }
}
