<?php
declare(strict_types=1);

namespace App\Storage;

use App\Exception\StorageException;

final class StoragePath
{
    private function __construct()
    {
    }

    public static function assertBucket(string $bucket): string
    {
        $bucket = strtolower(trim($bucket));
        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket) !== 1) {
            throw new StorageException('Nombre de bucket inválido.');
        }

        return $bucket;
    }

    public static function assertKey(string $key): string
    {
        $key = trim($key);
        if ($key === ''
            || strlen($key) > 1024
            || preg_match('/[\x00-\x1F\x7F]/', $key) === 1
            || str_contains($key, '\\')
        ) {
            throw new StorageException('Clave de objeto inválida.');
        }

        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new StorageException('La clave de objeto contiene un segmento inválido.');
            }
        }

        return ltrim($key, '/');
    }
}
