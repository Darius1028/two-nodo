<?php
declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Core\RequestContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests de RequestContext::getClientIp() — resolución segura de IP con
 * proxies confiables (TRUSTED_PROXIES) para evitar el bypass de rate limit
 * mediante X-Forwarded-For.
 *
 * No requieren base de datos: solo manipulan $_SERVER / $_ENV.
 */
final class RequestContextTest extends TestCase
{
    private array $backupServer = [];
    private array $backupEnv = [];

    protected function setUp(): void
    {
        $this->backupServer = $_SERVER;
        $this->backupEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->backupServer;
        $_ENV = $this->backupEnv;
    }

    private function setServer(array $values): void
    {
        $_SERVER = $values + $_SERVER;
    }

    private function setTrustedProxies(string $value): void
    {
        $_ENV['TRUSTED_PROXIES'] = $value;
    }

    public function testUsesRemoteAddrWhenNoForwardedHeaders(): void
    {
        $this->setServer(['REMOTE_ADDR' => '203.0.113.10']);
        self::assertSame('203.0.113.10', RequestContext::getClientIp());
    }

    public function testIgnoresXForwardedForFromUntrustedClient(): void
    {
        // El atacante conectado directo (REMOTE_ADDR no es proxy confiable)
        // envía X-Forwarded-For para resetear el rate limit: se descarta.
        $this->setServer([
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.1, 10.0.0.1',
            'HTTP_X_REAL_IP' => '203.0.113.1',
        ]);
        self::assertSame('198.51.100.7', RequestContext::getClientIp());
    }

    public function testTrustsForwardedForFromTrustedProxy(): void
    {
        $this->setTrustedProxies('10.0.0.1');
        $this->setServer([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5',
        ]);
        self::assertSame('203.0.113.5', RequestContext::getClientIp());
    }

    public function testTakesClientIpFromRightmostNonTrustedEntry(): void
    {
        $this->setTrustedProxies('10.0.0.1');
        $this->setServer([
            'REMOTE_ADDR' => '10.0.0.1',
            // El cliente no puede falsificar la parte derecha (agregada por
            // el proxy confiable): se toma 203.0.113.9, no 203.0.113.66.
            'HTTP_X_FORWARDED_FOR' => '203.0.113.66, 203.0.113.9',
        ]);
        self::assertSame('203.0.113.9', RequestContext::getClientIp());
    }

    public function testFallsBackToXRealIpWhenChainOnlyHasTrustedProxies(): void
    {
        $this->setTrustedProxies('10.0.0.1');
        $this->setServer([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1',
            'HTTP_X_REAL_IP' => '203.0.113.42',
        ]);
        self::assertSame('203.0.113.42', RequestContext::getClientIp());
    }

    public function testCidrTrustedProxyMatches(): void
    {
        $this->setTrustedProxies('10.10.0.0/16');
        $this->setServer([
            'REMOTE_ADDR' => '10.10.55.2',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.88',
        ]);
        self::assertSame('203.0.113.88', RequestContext::getClientIp());
    }

    public function testCidrTrustedProxyDoesNotMatchOutside(): void
    {
        $this->setTrustedProxies('10.10.0.0/16');
        $this->setServer([
            'REMOTE_ADDR' => '192.168.1.5',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.88',
        ]);
        self::assertSame('192.168.1.5', RequestContext::getClientIp());
    }

    public function testMultipleTrustedProxiesSeparatedByComma(): void
    {
        $this->setTrustedProxies('172.16.0.1, 10.0.0.1');
        $this->setServer([
            'REMOTE_ADDR' => '172.16.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.20',
        ]);
        self::assertSame('203.0.113.20', RequestContext::getClientIp());
    }

    public function testIgnoresInvalidForwardedIp(): void
    {
        $this->setTrustedProxies('10.0.0.1');
        $this->setServer([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => 'not-an-ip, 203.0.113.77',
            'HTTP_X_REAL_IP' => '',
        ]);
        self::assertSame('203.0.113.77', RequestContext::getClientIp());
    }

    public function testStripsPortFromForwardedFor(): void
    {
        $this->setTrustedProxies('10.0.0.1');
        $this->setServer([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.99:61234',
        ]);
        self::assertSame('203.0.113.99', RequestContext::getClientIp());
    }

    public function testEmptyRemoteAddrReturnsEmpty(): void
    {
        $this->setServer(['REMOTE_ADDR' => '']);
        self::assertSame('', RequestContext::getClientIp());
    }

    public function testAlertForwardedHeaderSpoofSkipsTrustedProxy(): void
    {
        $this->setTrustedProxies('10.0.0.1');
        $this->setServer([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
        ]);
        // No debe lanzar ni persistir nada; se verifica que devuelve sin errores.
        RequestContext::alertForwardedHeaderSpoof('verify_certificate');
        self::assertTrue(true);
    }
}
