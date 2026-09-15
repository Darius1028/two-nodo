<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\StorageException;
use App\Exception\ValidationException;
use App\Storage\ObjectStorageFactory;
use App\Storage\ObjectStorageInterface;
use App\Storage\StorageConfig;
use App\Storage\StoredObject;

final class ImportFileStorage
{
    private readonly ObjectStorageInterface $storage;

    public function __construct(?ObjectStorageInterface $storage = null)
    {
        $this->storage = $storage ?? ObjectStorageFactory::create();
    }

    public function store(string $uploadedPath, string $originalName): StoredObject
    {
        if (!is_file($uploadedPath) || !is_readable($uploadedPath)) {
            throw new ValidationException('El archivo temporal de importación no es legible.');
        }

        $size = filesize($uploadedPath);
        if ($size === false || $size <= 0) {
            throw new ValidationException('El archivo de importación está vacío.');
        }
        if ($size > CsvService::MAX_FILE_SIZE) {
            throw new ValidationException('El archivo excede el tamaño máximo de 128MB.');
        }

        $key = sprintf(
            'imports/%s/%s.csv',
            date('Y/m/d'),
            bin2hex(random_bytes(16))
        );

        return $this->storage->putFile(
            StorageConfig::importBucket(),
            $key,
            $uploadedPath,
            'text/csv',
            [
                // No incluir el nombre original como header: puede contener
                // caracteres no ASCII. La BD conserva el nombre visible.
                'original-name-sha256' => hash('sha256', basename($originalName)),
            ]
        );
    }

    public function downloadTemporary(
        string $bucket,
        string $key,
        ?string $versionId,
        int $expectedSize,
        string $expectedSha256
    ): string {
        $workDir = trim(StorageConfig::env('IMPORT_WORK_DIR', sys_get_temp_dir()));
        if (!is_dir($workDir) && !mkdir($workDir, 0770, true) && !is_dir($workDir)) {
            throw new StorageException('No se pudo crear el directorio temporal de importación.', true);
        }

        $temporary = tempnam($workDir, 'academic-import-');
        if ($temporary === false) {
            throw new StorageException('No se pudo reservar un archivo temporal para la importación.', true);
        }

        try {
            $downloaded = $this->storage->downloadTo($bucket, $key, $temporary, $versionId);
            if ($downloaded->size > CsvService::MAX_FILE_SIZE) {
                throw new StorageException('El objeto de importación excede el máximo de 128MB.');
            }
            if ($expectedSize > 0 && $downloaded->size !== $expectedSize) {
                throw new StorageException('El tamaño del objeto de importación no coincide con el registrado.');
            }
            if ($expectedSha256 !== '' && !hash_equals(strtolower($expectedSha256), strtolower($downloaded->sha256))) {
                throw new StorageException('El hash SHA-256 del objeto de importación no coincide con el registrado.');
            }

            return $temporary;
        } catch (\Throwable $e) {
            @unlink($temporary);
            throw $e;
        }
    }

    public function delete(string $bucket, string $key, ?string $versionId = null): void
    {
        $this->storage->delete($bucket, $key, $versionId);
    }

    public static function uri(StoredObject $object): string
    {
        return 's3://' . $object->bucket . '/' . $object->key;
    }
}
