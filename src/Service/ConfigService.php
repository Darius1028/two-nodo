<?php
declare(strict_types=1);

namespace App\Service;

class ConfigService
{
    private static ?array $config = null;
    private const PATH = __DIR__ . '/../../config/config.json';

    public static function get(): array
    {
        if (self::$config === null) {
            if (!file_exists(self::PATH)) {
                self::$config = [];
                return self::$config;
            }
            $decoded = json_decode((string)file_get_contents(self::PATH), true);
            self::$config = is_array($decoded) ? $decoded : [];
        }
        return self::$config;
    }

    public static function set(array $config): bool
    {
        self::$config = $config;
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return $json !== false && file_put_contents(self::PATH, $json) !== false;
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
        $config = self::get();
        $config['column_schema'] = $schema;
        return self::set($config);
    }

    public static function toggleQr(): bool
    {
        $config = self::get();
        $config['qr_enabled'] = !($config['qr_enabled'] ?? false);
        return self::set($config);
    }

    public static function getHistorialPath(): string
    {
        return __DIR__ . '/../../var/log/historial.json';
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