<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Storage\S3ClientFactory;
use App\Storage\StorageConfig;
use Aws\Exception\AwsException;

try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();

    if (StorageConfig::driver() !== 's3') {
        throw new \RuntimeException('STORAGE_DRIVER debe ser s3.');
    }

    $client = S3ClientFactory::create();
    $importsBucket = StorageConfig::importBucket();
    $assetsBucket = StorageConfig::assetBucket();

    foreach ([$importsBucket, $assetsBucket] as $bucket) {
        try {
            $client->headBucket(['Bucket' => $bucket]);
            fwrite(STDOUT, "[storage-init] bucket existente: {$bucket}\n");
        } catch (AwsException $e) {
            if ((int) ($e->getStatusCode() ?? 0) !== 404) {
                throw $e;
            }
            $client->createBucket(['Bucket' => $bucket]);
            $client->waitUntil('BucketExists', ['Bucket' => $bucket]);
            fwrite(STDOUT, "[storage-init] bucket creado: {$bucket}\n");
        }
    }

    $client->putBucketVersioning([
        'Bucket' => $assetsBucket,
        'VersioningConfiguration' => ['Status' => 'Enabled'],
    ]);

    $expirationDays = max(1, (int) StorageConfig::env('MINIO_IMPORT_EXPIRATION_DAYS', '7'));
    $client->putBucketLifecycleConfiguration([
        'Bucket' => $importsBucket,
        'LifecycleConfiguration' => [
            'Rules' => [[
                'ID' => 'expire-import-files',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => 'imports/'],
                'Expiration' => ['Days' => $expirationDays],
                'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 1],
            ]],
        ],
    ]);

    fwrite(STDOUT, "[storage-init] assets versionados; imports expiran en {$expirationDays} día(s).\n");
} catch (\Throwable $e) {
    $message = strtok($e->getMessage(), "\r\n") ?: 'error desconocido';
    $status = $e instanceof AwsException && $e->getStatusCode() !== null
        ? ' (HTTP ' . $e->getStatusCode() . ')'
        : '';
    fwrite(STDERR, '[storage-init] error' . $status . ': ' . $message . "\n");
    exit(1);
}
