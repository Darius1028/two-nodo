<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\EntityManagerProvider;
use App\Exception\ValidationException;

final class ImportJobService
{
    /**
     * @return array{id:int, rows:int, bucket:string, key:string, sha256:string}
     */
    public function enqueue(
        string $uploadedPath,
        string $originalName,
        int $year,
        int $userId,
        string $ip,
        string $host,
        ?int $validatedRows = null
    ): array {
        if ($validatedRows === null) {
            $validation = CsvService::validateCSV($uploadedPath);
            if (!$validation['success']) {
                throw new ValidationException('Archivo inválido: ' . implode('; ', $validation['errors']));
            }
            $validatedRows = (int) $validation['rows'];
        }

        $fileStorage = new ImportFileStorage();
        $storedObject = null;
        $safeOriginalName = self::safeOriginalName($originalName);
        try {
            $storedObject = $fileStorage->store($uploadedPath, $originalName);
            $jobId = (int) EntityManagerProvider::get()->getConnection()->fetchOne(
                "INSERT INTO Academico.ImportJob
                    (nombreArchivo, rutaArchivo, storageBucket, objectKey, objectVersionId,
                     objectETag, sha256, tamanoBytes, anio, filasTotal,
                     idPersonaCrea, ipCrea, equipoCrea)
                 OUTPUT INSERTED.id
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $safeOriginalName,
                    ImportFileStorage::uri($storedObject),
                    $storedObject->bucket,
                    $storedObject->key,
                    $storedObject->versionId,
                    $storedObject->etag,
                    $storedObject->sha256,
                    $storedObject->size,
                    $year,
                    $validatedRows,
                    $userId,
                    $ip,
                    $host,
                ]
            );

            return [
                'id' => $jobId,
                'rows' => $validatedRows,
                'bucket' => $storedObject->bucket,
                'key' => $storedObject->key,
                'sha256' => $storedObject->sha256,
            ];
        } catch (\Throwable $e) {
            if ($storedObject !== null) {
                try {
                    $fileStorage->delete($storedObject->bucket, $storedObject->key, $storedObject->versionId);
                } catch (\Throwable $cleanupError) {
                    error_log('[ImportJobService] No se pudo retirar un CSV huérfano: ' . $cleanupError->getMessage());
                }
            }
            throw $e;
        }
    }

    private static function safeOriginalName(string $originalName): string
    {
        $normalized = str_replace('\\', '/', $originalName);
        $name = basename($normalized);
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');
        if ($name === '') {
            $name = 'import.csv';
        }

        return mb_substr($name, 0, 255);
    }
}
