<?php
declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Tests para RateLimiter. Cubre el contrato:
 *  - Permite hasta $maxRequests dentro de la ventana.
 *  - Bloquea la request #($maxRequests + 1) dentro de la misma ventana.
 *  - Aísla contadores por clave.
 *
 * NOTA: RateLimiter usa un archivo compartido en var/cache/rate_limit.json.
 * Cada test usa claves con prefijo único para no colisionar con otros tests
 * ni con datos del entorno de desarrollo.
 */
final class RateLimiterTest extends TestCase
{
    private static function uniqueKey(string $suffix): string
    {
        return 'test:' . bin2hex(random_bytes(6)) . ':' . $suffix;
    }

    public function testAllowsRequestsUpToLimit(): void
    {
        $key = self::uniqueKey('allow_up_to_limit');

        for ($i = 1; $i <= 3; $i++) {
            self::assertTrue(
                RateLimiter::allow($key, 3, 60),
                "La request #$i debería estar permitida (dentro del límite de 3)."
            );
        }
    }

    public function testBlocksRequestBeyondLimit(): void
    {
        $key = self::uniqueKey('blocks_beyond');

        RateLimiter::allow($key, 2, 60);
        RateLimiter::allow($key, 2, 60);

        self::assertFalse(
            RateLimiter::allow($key, 2, 60),
            'La 3ra request debería ser rechazada con maxRequests=2.'
        );
    }

    public function testIsolatesCountersBetweenKeys(): void
    {
        $keyA = self::uniqueKey('isolation_a');
        $keyB = self::uniqueKey('isolation_b');

        RateLimiter::allow($keyA, 1, 60);
        self::assertFalse(RateLimiter::allow($keyA, 1, 60), 'keyA agotó su cupo.');
        self::assertTrue(
            RateLimiter::allow($keyB, 1, 60),
            'keyB debe conservar su cupo independiente de keyA.'
        );
    }

    public function testWindowExpirationResetsCounter(): void
    {
        $key = self::uniqueKey('window_expiration');

        // Ventana de 1 segundo, límite de 1 request.
        self::assertTrue(RateLimiter::allow($key, 1, 1));
        self::assertFalse(RateLimiter::allow($key, 1, 1));

        // Esperar a que la ventana se cierre.
        sleep(2);

        self::assertTrue(
            RateLimiter::allow($key, 1, 1),
            'Tras expirar la ventana, el contador debe reiniciarse.'
        );
    }
}