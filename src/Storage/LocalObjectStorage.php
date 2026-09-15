<?php
declare(strict_types=1);

namespace App\Storage;

use App\Exception\StorageException;

/**
 * Implementación para desarrollo y pruebas. No es almacenamiento compartido
 * entre servidores y por eso nunca debe seleccionarse en producción multinodo.
 */
final class LocalObjectStorage implements ObjectStorageInterface
{
    public function __construct(private readonly string $root)
    {
    }

    public function checkBucket(string $bucket): void
    {
        $path = rtrim($this->root, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . StoragePath::assertBucket($bucket);
        $this->ensureDirectory($path);
        if (!is_writable($path)) {
            throw new StorageException('El bucket local no es escribible.', true);
        }
    }

    public function putFile(
        string $bucket,
        string $key,
        string $localPath,
        string $contentType = 'application/octet-stream',
        array $metadata = []
    ): StoredObject {
        if (!is_file($localPath) || !is_readable($localPath)) {
            throw new StorageException('El archivo que se desea almacenar no existe o no es legible.');
        }

        $target = $this->path($bucket, $key);
        $this->ensureDirectory(dirname($target));
        $temporary = tempnam(dirname($target), '.upload-');
        if ($temporary === false) {
            throw new StorageException('No se pudo crear el archivo temporal de almacenamiento.');
        }

        try {
            if (!copy($localPath, $temporary)) {
                throw new StorageException('No se pudo copiar el objeto al almacenamiento local.');
            }
            if (!rename($temporary, $target)) {
                throw new StorageException('No se pudo publicar el objeto en el almacenamiento local.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $this->metadata($bucket, $key, $target, $contentType);
    }

    public function downloadTo(
        string $bucket,
        string $key,
        string $localPath,
        ?string $versionId = null
    ): StoredObject {
        $source = $this->path($bucket, $key);
        if (!is_file($source) || !is_readable($source)) {
            throw new StorageException('El objeto solicitado no existe.', false);
        }

        $this->ensureDirectory(dirname($localPath));
        if (!copy($source, $localPath)) {
            throw new StorageException('No se pudo descargar el objeto al archivo temporal.');
        }

        return $this->metadata($bucket, $key, $localPath, $this->detectContentType($source));
    }

    public function stat(string $bucket, string $key, ?string $versionId = null): StoredObject
    {
        $path = $this->path($bucket, $key);
        if (!is_file($path) || !is_readable($path)) {
            throw new StorageException('El objeto solicitado no existe.', false);
        }

        return $this->metadata($bucket, $key, $path, $this->detectContentType($path));
    }

    public function delete(string $bucket, string $key, ?string $versionId = null): void
    {
        $path = $this->path($bucket, $key);
        if (is_file($path) && !unlink($path)) {
            throw new StorageException('No se pudo eliminar el objeto del almacenamiento local.', true);
        }
    }

    private function path(string $bucket, string $key): string
    {
        $bucket = StoragePath::assertBucket($bucket);
        $key = StoragePath::assertKey($key);
        return rtrim($this->root, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . $bucket
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new StorageException('No se pudo crear un directorio del almacenamiento local.');
        }
    }

    private function metadata(string $bucket, string $key, string $path, string $contentType): StoredObject
    {
        $size = filesize($path);
        $sha256 = hash_file('sha256', $path);
        $etag = hash_file('md5', $path);
        if ($size === false || $sha256 === false || $etag === false) {
            throw new StorageException('No se pudo calcular la integridad del objeto almacenado.');
        }

        return new StoredObject($bucket, $key, $size, $sha256, $etag, null, $contentType);
    }

    private function detectContentType(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return $finfo->file($path) ?: 'application/octet-stream';
    }
}
