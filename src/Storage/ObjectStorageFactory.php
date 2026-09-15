<?php
declare(strict_types=1);

namespace App\Storage;

final class ObjectStorageFactory
{
    private static ?ObjectStorageInterface $instance = null;

    private function __construct()
    {
    }

    public static function create(): ObjectStorageInterface
    {
        if (self::$instance === null) {
            self::$instance = StorageConfig::driver() === 's3'
                ? S3ObjectStorage::fromEnvironment()
                : new LocalObjectStorage(StorageConfig::localRoot());
        }

        return self::$instance;
    }

    /** Permite aislar pruebas sin acceder a red o disco compartido. */
    public static function replace(?ObjectStorageInterface $storage): void
    {
        self::$instance = $storage;
    }
}
