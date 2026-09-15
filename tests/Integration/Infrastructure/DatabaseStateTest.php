<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure;

use App\Core\EntityManagerProvider;
use App\Infrastructure\DatabaseSessionHandler;
use App\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

final class DatabaseStateTest extends TestCase
{
    private string $key;
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        if (getenv('RUN_DATABASE_INTEGRATION') !== '1') {
            self::markTestSkipped('Defina RUN_DATABASE_INTEGRATION=1 para probar SQL Server real.');
        }

        foreach (['RATE_LIMIT_STORE', 'RATE_LIMIT_FAIL_OPEN'] as $name) {
            $this->previousEnvironment[$name] = $_ENV[$name] ?? null;
        }
        $_ENV['RATE_LIMIT_STORE'] = 'database';
        $_ENV['RATE_LIMIT_FAIL_OPEN'] = 'false';
        $this->key = 'database-rate-limit-' . bin2hex(random_bytes(12));
    }

    protected function tearDown(): void
    {
        EntityManagerProvider::get()->getConnection()->delete('Academico.RateLimit', [
            'clave' => hash('sha256', $this->key),
        ]);
        foreach ($this->previousEnvironment as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
    }

    public function testRateLimitCounterIsGlobalInDatabase(): void
    {
        self::assertTrue(RateLimiter::allow($this->key, 1, 60));
        self::assertFalse(RateLimiter::allow($this->key, 1, 60));
    }

    public function testSessionDataCanBeReadFromAnotherHandler(): void
    {
        $id = 'test-session-' . bin2hex(random_bytes(16));
        $first = new DatabaseSessionHandler();
        self::assertTrue($first->open('', 'ACADEMICSESSID'));
        self::assertSame('', $first->read($id));
        self::assertTrue($first->write($id, 'keycloak_user|a:0:{}'));
        self::assertTrue($first->close());

        $second = new DatabaseSessionHandler();
        self::assertTrue($second->open('', 'ACADEMICSESSID'));
        self::assertSame('keycloak_user|a:0:{}', $second->read($id));
        self::assertTrue($second->destroy($id));
        self::assertTrue($second->close());
    }
}
