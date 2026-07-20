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

    public static function setColumnSchema(array $schema): bool
    {
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
}