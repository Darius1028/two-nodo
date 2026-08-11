<?php
declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ConfigService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests para ConfigService::setColumnSchema — el único método con
 * validación de entrada real (whitelist ALLOWED_COLUMN_KEYS).
 *
 * La caché estática de ConfigService::$config se resetea vía reflection
 * antes de cada test, ya que get() la memoiza para toda la vida del proceso.
 */
final class ConfigServiceTest extends TestCase
{
    private string $backupConfigContent = '';
    private string $configPath;

    protected function setUp(): void
    {
        // Reset de la caché estática entre tests.
        $ref = new ReflectionClass(ConfigService::class);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->configPath = dirname(__DIR__, 3) . '/config/config.json';
        if (file_exists($this->configPath)) {
            $this->backupConfigContent = (string)file_get_contents($this->configPath);
        }
    }

    protected function tearDown(): void
    {
        // Restaurar contenido original para no ensuciar el entorno.
        if ($this->backupConfigContent !== '') {
            file_put_contents($this->configPath, $this->backupConfigContent);
        }
        $ref = new ReflectionClass(ConfigService::class);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testSetColumnSchemaAcceptsWhitelistedKeys(): void
    {
        $schema = [
            ['key' => 'cedula', 'label' => 'Cédula', 'width' => 30, 'visible' => true],
            ['key' => 'nombre', 'label' => 'Nombre', 'width' => 60, 'visible' => true],
        ];
        self::assertTrue(ConfigService::setColumnSchema($schema));
    }

    public function testSetColumnSchemaRejectsUnknownKey(): void
    {
        $schema = [
            ['key' => 'campo_inexistente', 'label' => 'X', 'width' => 10, 'visible' => true],
        ];
        self::assertFalse(
            ConfigService::setColumnSchema($schema),
            'Claves fuera de ALLOWED_COLUMN_KEYS deben ser rechazadas.'
        );
    }

    public function testSetColumnSchemaRejectsEmptyKey(): void
    {
        $schema = [
            ['label' => 'Sin key', 'width' => 10, 'visible' => true],
        ];
        self::assertFalse(
            ConfigService::setColumnSchema($schema),
            'Filas sin key ni field deben ser rechazadas.'
        );
    }

    public function testSetColumnSchemaAcceptsFieldAsAliasOfKey(): void
    {
        $schema = [
            ['field' => 'cedula', 'label' => 'Cédula', 'width' => 30, 'visible' => true],
        ];
        self::assertTrue(
            ConfigService::setColumnSchema($schema),
            "El alias 'field' debe funcionar igual que 'key'."
        );
    }
}