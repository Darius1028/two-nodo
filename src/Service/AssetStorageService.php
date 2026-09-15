<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\StorageException;
use App\Exception\ValidationException;
use App\Storage\ObjectStorageFactory;
use App\Storage\ObjectStorageInterface;
use App\Storage\StorageConfig;
use App\Storage\StoredObject;

final class AssetStorageService
{
    private const TYPES = ['letterhead', 'signature'];
    private const MAX_SIZE = 5 * 1024 * 1024;
    private const MAX_PIXELS = 40_000_000;

    private readonly ObjectStorageInterface $storage;

    public function __construct(?ObjectStorageInterface $storage = null)
    {
        $this->storage = $storage ?? ObjectStorageFactory::create();
    }

    public function upload(string $type, string $uploadedPath): StoredObject
    {
        $this->assertType($type);
        if (!is_file($uploadedPath) || !is_readable($uploadedPath)) {
            throw new ValidationException('La imagen subida no es legible.');
        }
        $size = filesize($uploadedPath);
        if ($size === false || $size <= 0 || $size > self::MAX_SIZE) {
            throw new ValidationException('La imagen debe tener un tamaño entre 1 byte y 5 MB.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($uploadedPath);
        $dimensions = @getimagesize($uploadedPath);
        if (!in_array($mime, ['image/png', 'image/jpeg'], true) || $dimensions === false) {
            throw new ValidationException('Solo se permiten imágenes PNG o JPEG válidas.');
        }
        $width = (int) ($dimensions[0] ?? 0);
        $height = (int) ($dimensions[1] ?? 0);
        if ($width <= 0 || $height <= 0 || $width > intdiv(self::MAX_PIXELS, $height)) {
            throw new ValidationException('La imagen excede el máximo de 40 millones de píxeles.');
        }

        $normalized = $this->normalizeToPng($uploadedPath, (string) $mime);
        try {
            $sha256 = hash_file('sha256', $normalized);
            if ($sha256 === false) {
                throw new StorageException('No se pudo calcular el hash del asset.');
            }
            $key = sprintf('assets/%s/%s.png', $type, $sha256);
            $stored = $this->storage->putFile(
                StorageConfig::assetBucket(),
                $key,
                $normalized,
                'image/png',
                ['asset-type' => $type]
            );

            $activated = ConfigService::setValue($type . '_asset', [
                'bucket' => $stored->bucket,
                'key' => $stored->key,
                'versionId' => $stored->versionId,
                'etag' => $stored->etag,
                'sha256' => $stored->sha256,
                'size' => $stored->size,
                'contentType' => $stored->contentType,
            ]);
            if (!$activated) {
                try {
                    $this->storage->delete($stored->bucket, $stored->key, $stored->versionId);
                } catch (\Throwable $cleanupError) {
                    error_log('[AssetStorageService] No se pudo retirar el asset huérfano: ' . $cleanupError->getMessage());
                }
                throw new StorageException('El asset se subió, pero no se pudo activar en la configuración compartida.');
            }

            return $stored;
        } finally {
            @unlink($normalized);
        }
    }

    /**
     * Resuelve el asset activo a un archivo local inmutable que FPDF pueda
     * consumir. Si aún no existe configuración compartida usa el asset que
     * viene empacado con la aplicación como bootstrap.
     */
    public function resolveLocalPath(string $type): ?string
    {
        $this->assertType($type);
        $descriptor = ConfigService::get()[$type . '_asset'] ?? null;
        if (!is_array($descriptor) || empty($descriptor['key'])) {
            $packaged = dirname(__DIR__, 2) . '/public/assets/' . $type . '.png';
            return is_file($packaged) && is_readable($packaged) ? $packaged : null;
        }

        $bucket = (string) ($descriptor['bucket'] ?? StorageConfig::assetBucket());
        $key = (string) $descriptor['key'];
        $versionId = isset($descriptor['versionId']) ? (string) $descriptor['versionId'] : null;
        $sha256 = strtolower((string) ($descriptor['sha256'] ?? ''));
        if ($sha256 === '' || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new StorageException("El descriptor del asset {$type} no contiene un SHA-256 válido.");
        }

        $cacheDir = dirname(__DIR__, 2) . '/var/cache/assets';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0770, true) && !is_dir($cacheDir)) {
            throw new StorageException('No se pudo crear la caché local de assets.', true);
        }
        $target = $cacheDir . '/' . $type . '_' . $sha256 . '.png';
        if ($this->isExpectedPng($target, $sha256)) {
            return $target;
        }

        $lock = fopen($target . '.lock', 'c');
        if ($lock === false) {
            throw new StorageException('No se pudo bloquear la caché local de assets.', true);
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new StorageException('No se pudo adquirir el bloqueo de assets.', true);
            }
            if ($this->isExpectedPng($target, $sha256)) {
                return $target;
            }

            $temporary = tempnam($cacheDir, '.asset-');
            if ($temporary === false) {
                throw new StorageException('No se pudo crear un temporal para el asset.', true);
            }
            try {
                $downloaded = $this->storage->downloadTo($bucket, $key, $temporary, $versionId);
                if (!hash_equals($sha256, strtolower($downloaded->sha256)) || !$this->isPng($temporary)) {
                    throw new StorageException("El asset {$type} descargado no supera la verificación de integridad.");
                }
                if (!rename($temporary, $target)) {
                    throw new StorageException('No se pudo publicar el asset en la caché local.', true);
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }

            return $target;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function normalizeToPng(string $path, string $mime): string
    {
        $image = $mime === 'image/png' ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if ($image === false) {
            throw new ValidationException('No se pudo decodificar la imagen subida.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'academic-asset-');
        if ($temporary === false) {
            imagedestroy($image);
            throw new StorageException('No se pudo crear el temporal del asset.', true);
        }

        try {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            if (!imagepng($image, $temporary, 6)) {
                throw new StorageException('No se pudo normalizar la imagen a PNG.');
            }
        } catch (\Throwable $e) {
            @unlink($temporary);
            throw $e;
        } finally {
            imagedestroy($image);
        }

        return $temporary;
    }

    private function isExpectedPng(string $path, string $sha256): bool
    {
        if (!is_file($path) || !is_readable($path) || !$this->isPng($path)) {
            return false;
        }
        $actual = hash_file('sha256', $path);
        return is_string($actual) && hash_equals($sha256, strtolower($actual));
    }

    private function isPng(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = fread($handle, 8);
        fclose($handle);
        return $header === "\x89PNG\r\n\x1a\n";
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new ValidationException('Tipo de asset no permitido.');
        }
    }
}
