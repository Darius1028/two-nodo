<?php
declare(strict_types=1);

namespace App\Storage;

use App\Exception\InvalidConfigurationException;

final class StorageConfig
{
    private function __construct()
    {
    }

    public static function driver(): string
    {
        $driver = strtolower(trim(self::env('STORAGE_DRIVER', 'local')));
        if (!in_array($driver, ['local', 's3'], true)) {
            throw new InvalidConfigurationException('STORAGE_DRIVER debe ser "local" o "s3".');
        }

        return $driver;
    }

    public static function endpoint(): string
    {
        $endpoint = rtrim(self::required('MINIO_ENDPOINT'), '/');
        $parts = parse_url($endpoint);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidConfigurationException(
                'MINIO_ENDPOINT debe ser una URL HTTP(S) sin credenciales, query ni fragmento.'
            );
        }

        return $endpoint;
    }

    public static function region(): string
    {
        return trim(self::env('MINIO_REGION', 'us-east-1')) ?: 'us-east-1';
    }

    public static function accessKey(): string
    {
        return self::secret('MINIO_ACCESS_KEY');
    }

    public static function secretKey(): string
    {
        return self::secret('MINIO_SECRET_KEY');
    }

    public static function importBucket(): string
    {
        return self::bucket('MINIO_IMPORT_BUCKET', 'record-academico-imports');
    }

    public static function assetBucket(): string
    {
        return self::bucket('MINIO_ASSET_BUCKET', 'record-academico-assets');
    }

    public static function pathStyle(): bool
    {
        return self::bool('MINIO_PATH_STYLE', true);
    }

    /** @return bool|string */
    public static function tlsVerify(): bool|string
    {
        $caBundle = trim(self::env('MINIO_CA_BUNDLE', ''));
        if ($caBundle !== '' && !is_readable($caBundle)) {
            throw new InvalidConfigurationException('MINIO_CA_BUNDLE no es legible.');
        }
        return $caBundle !== '' ? $caBundle : self::bool('MINIO_TLS_VERIFY', true);
    }

    public static function connectTimeout(): float
    {
        return max(0.5, (float) self::env('MINIO_CONNECT_TIMEOUT', '5'));
    }

    public static function requestTimeout(): float
    {
        return max(5.0, (float) self::env('MINIO_REQUEST_TIMEOUT', '300'));
    }

    public static function localRoot(): string
    {
        $configured = trim(self::env('LOCAL_OBJECT_STORAGE_PATH', ''));
        return $configured !== ''
            ? rtrim($configured, DIRECTORY_SEPARATOR)
            : dirname(__DIR__, 2) . '/var/object-storage';
    }

    public static function env(string $name, string $default = ''): string
    {
        $value = $_ENV[$name] ?? getenv($name);
        return is_string($value) ? $value : $default;
    }

    private static function required(string $name): string
    {
        $value = trim(self::env($name));
        if ($value === '') {
            throw new InvalidConfigurationException("{$name} no está configurado.");
        }

        return $value;
    }

    private static function secret(string $name): string
    {
        $file = trim(self::env($name . '_FILE'));
        if ($file !== '') {
            if (!is_readable($file)) {
                throw new InvalidConfigurationException("No se puede leer {$name}_FILE.");
            }
            $value = trim((string) file_get_contents($file));
        } else {
            $value = trim(self::env($name));
        }

        if ($value === '') {
            throw new InvalidConfigurationException("{$name} no está configurado.");
        }

        return $value;
    }

    private static function bucket(string $name, string $default): string
    {
        $bucket = strtolower(trim(self::env($name, $default)));
        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket) !== 1) {
            throw new InvalidConfigurationException("{$name} no contiene un nombre de bucket válido.");
        }

        return $bucket;
    }

    private static function bool(string $name, bool $default): bool
    {
        $value = self::env($name, $default ? 'true' : 'false');
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed ?? $default;
    }
}
