<?php
declare(strict_types=1);

namespace App\Storage;

use App\Exception\StorageException;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;

final class S3ObjectStorage implements ObjectStorageInterface
{
    public function __construct(private readonly S3Client $client)
    {
    }

    public function checkBucket(string $bucket): void
    {
        $bucket = StoragePath::assertBucket($bucket);
        try {
            $this->client->headBucket(['Bucket' => $bucket]);
        } catch (\Throwable $e) {
            throw $this->translate($e, 'No se pudo acceder al bucket de MinIO.');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(S3ClientFactory::create());
    }

    public function putFile(
        string $bucket,
        string $key,
        string $localPath,
        string $contentType = 'application/octet-stream',
        array $metadata = []
    ): StoredObject {
        $bucket = StoragePath::assertBucket($bucket);
        $key = StoragePath::assertKey($key);
        if (!is_file($localPath) || !is_readable($localPath)) {
            throw new StorageException('El archivo que se desea almacenar no existe o no es legible.');
        }

        $size = filesize($localPath);
        $sha256 = hash_file('sha256', $localPath);
        if ($size === false || $sha256 === false) {
            throw new StorageException('No se pudo calcular la integridad del archivo a almacenar.');
        }

        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new StorageException('No se pudo abrir el archivo para subirlo.');
        }

        try {
            $result = $this->client->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => $stream,
                'ContentLength' => $size,
                'ContentType' => $contentType,
                'Metadata' => $this->sanitizeMetadata(array_merge($metadata, [
                    'sha256' => $sha256,
                    'size' => (string) $size,
                ])),
            ]);
        } catch (\Throwable $e) {
            throw $this->translate($e, 'No se pudo subir el objeto a MinIO.');
        } finally {
            fclose($stream);
        }

        return new StoredObject(
            $bucket,
            $key,
            $size,
            $sha256,
            trim((string) ($result['ETag'] ?? ''), '"'),
            isset($result['VersionId']) ? (string) $result['VersionId'] : null,
            $contentType
        );
    }

    public function downloadTo(
        string $bucket,
        string $key,
        string $localPath,
        ?string $versionId = null
    ): StoredObject {
        $bucket = StoragePath::assertBucket($bucket);
        $key = StoragePath::assertKey($key);
        $params = ['Bucket' => $bucket, 'Key' => $key, 'SaveAs' => $localPath];
        if ($versionId !== null && $versionId !== '') {
            $params['VersionId'] = $versionId;
        }

        try {
            $result = $this->client->getObject($params);
        } catch (\Throwable $e) {
            @unlink($localPath);
            throw $this->translate($e, 'No se pudo descargar el objeto desde MinIO.');
        }

        if (!is_file($localPath)) {
            throw new StorageException('MinIO no produjo el archivo descargado.', true);
        }
        $size = filesize($localPath);
        $sha256 = hash_file('sha256', $localPath);
        if ($size === false || $sha256 === false) {
            @unlink($localPath);
            throw new StorageException('No se pudo verificar el objeto descargado.', true);
        }

        return new StoredObject(
            $bucket,
            $key,
            $size,
            $sha256,
            trim((string) ($result['ETag'] ?? ''), '"'),
            isset($result['VersionId']) ? (string) $result['VersionId'] : $versionId,
            (string) ($result['ContentType'] ?? 'application/octet-stream')
        );
    }

    public function stat(string $bucket, string $key, ?string $versionId = null): StoredObject
    {
        $bucket = StoragePath::assertBucket($bucket);
        $key = StoragePath::assertKey($key);
        $params = ['Bucket' => $bucket, 'Key' => $key];
        if ($versionId !== null && $versionId !== '') {
            $params['VersionId'] = $versionId;
        }

        try {
            $result = $this->client->headObject($params);
        } catch (\Throwable $e) {
            throw $this->translate($e, 'No se pudo consultar el objeto en MinIO.');
        }

        $metadata = is_array($result['Metadata'] ?? null) ? $result['Metadata'] : [];
        return new StoredObject(
            $bucket,
            $key,
            (int) ($result['ContentLength'] ?? 0),
            (string) ($metadata['sha256'] ?? ''),
            trim((string) ($result['ETag'] ?? ''), '"'),
            isset($result['VersionId']) ? (string) $result['VersionId'] : $versionId,
            (string) ($result['ContentType'] ?? 'application/octet-stream')
        );
    }

    public function delete(string $bucket, string $key, ?string $versionId = null): void
    {
        $bucket = StoragePath::assertBucket($bucket);
        $key = StoragePath::assertKey($key);
        $params = ['Bucket' => $bucket, 'Key' => $key];
        if ($versionId !== null && $versionId !== '') {
            $params['VersionId'] = $versionId;
        }

        try {
            $this->client->deleteObject($params);
        } catch (\Throwable $e) {
            throw $this->translate($e, 'No se pudo eliminar el objeto de MinIO.');
        }
    }

    /** @param array<string, string> $metadata */
    private function sanitizeMetadata(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            $name = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '-', (string) $key) ?? '');
            if ($name === '') {
                continue;
            }
            $clean[$name] = mb_substr(str_replace(["\r", "\n"], '', (string) $value), 0, 512);
        }
        return $clean;
    }

    private function translate(\Throwable $exception, string $message): StorageException
    {
        $retryable = true;
        $status = 0;
        if ($exception instanceof AwsException) {
            $status = (int) ($exception->getStatusCode() ?? 0);
            $retryable = $status === 0 || $status === 408 || $status === 429 || $status >= 500;
        }

        return new StorageException($message, $retryable, $status, $exception);
    }
}
