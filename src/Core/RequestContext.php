<?php
declare(strict_types=1);

namespace App\Core;

use App\Security\SecurityAlertService;

/**
 * Resuelve IP y hostname del cliente para los campos de auditoría
 * institucional (ipCrea/equipoCrea, ipModifica/equipoModifica) y para el
 * rate limiting.
 *
 * SEGURIDAD (bypass de rate limit vía X-Forwarded-For):
 *  - Nunca se confía en X-Forwarded-For / X-Real-IP cuando el par inmediato
 *    (REMOTE_ADDR) no es un proxy declarado en TRUSTED_PROXIES. Un cliente
 *    conectado directo NO puede resetear el rate limit inventando cabeceras.
 *  - Cuando REMOTE_ADDR sí es un proxy confiable, la IP del cliente se extrae
 *    de la cadena X-Forwarded-For recorrida de derecha a izquierda, saltando
 *    los proxies confiables; si no queda ninguna, se usa X-Real-IP.
 *  - REMOTE_ADDR es la única cabecera que el cliente no puede falsificar
 *    (la setea el servidor/proxy).
 */
final class RequestContext
{
    /** Env con la lista de proxies confiables (IPs o CIDR separados por coma). */
    private const ENV_TRUSTED_PROXIES = 'TRUSTED_PROXIES';

    public static function getClientIp(): string
    {
        $remote = self::getRemoteAddr();
        $clientIp = $remote;

        // El par inmediato no es un proxy confiable: se usa REMOTE_ADDR tal
        // cual. Cualquier X-Forwarded-For / X-Real-IP presente es inventado
        // por el cliente y se descarta.
        if ($remote !== '' && self::isTrustedProxy($remote)) {
            // Proxy confiable: recorrer la cadena XFF de derecha a izquierda,
            // saltando proxies confiables, hasta encontrar la IP del cliente.
            $chain = self::getForwardedForChain();
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                if (!self::isTrustedProxy($chain[$i])) {
                    $clientIp = $chain[$i];
                    break;
                }
            }

            // Si la cadena no aportó una IP de cliente (todas eran proxies
            // confiables o vacía), se usa X-Real-IP si está disponible.
            if ($clientIp === $remote) {
                $realIp = self::getXRealIp();
                if ($realIp !== '') {
                    $clientIp = $realIp;
                }
            }
        }

        return $clientIp;
    }

    /** REMOTE_ADDR normalizado y validado (la fuente que el cliente no falsifica). */
    public static function getRemoteAddr(): string
    {
        return self::normalizeIp((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    /** Cadena X-Forwarded-For cruda (como la envió el proxy/cliente). */
    public static function getRawForwardedFor(): string
    {
        $value = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /** Entradas de X-Forwarded-For normalizadas (orden recibido, izquierda→derecha). */
    public static function getForwardedForChain(): array
    {
        $raw = self::getRawForwardedFor();
        if ($raw === '') {
            return [];
        }
        $chain = [];
        foreach (explode(',', $raw) as $candidate) {
            $ip = self::normalizeIp($candidate);
            if ($ip !== '') {
                $chain[] = $ip;
            }
        }
        return $chain;
    }

    /** X-Real-IP normalizado (lo setea nginx). */
    public static function getXRealIp(): string
    {
        $value = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        return self::normalizeIp(is_string($value) ? $value : '');
    }

    /**
     * true si el par inmediato (REMOTE_ADDR) está declarado en TRUSTED_PROXIES.
     */
    public static function isTrustedProxy(string $ip): bool
    {
        $ip = self::normalizeIp($ip);
        if ($ip === '') {
            return false;
        }
        foreach (self::trustedProxyEntries() as $entry) {
            if (self::ipMatchesEntry($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Alertas de correlación de IP para el rate limit. Se llama cuando un
     * endpoint devuelve HTTP 429. Si el par inmediato NO es un proxy
     * confiable y aun así se recibieron X-Forwarded-For / X-Real-IP, es un
     * intento de spoofing (cambiar la "IP lógica" para resetear el límite)
     * y se registra una alerta de seguridad de nivel alto.
     */
    public static function alertForwardedHeaderSpoof(string $action): void
    {
        $remote = self::getRemoteAddr();
        $xff    = self::getRawForwardedFor();
        $xri    = self::getXRealIp();

        if ($remote === '') {
            return;
        }

        // Si el par es un proxy confiable, las cabeceras son esperadas.
        if (self::isTrustedProxy($remote)) {
            return;
        }

        // Sin cabeceras de reenvío: nada anómalo que reportar.
        if ($xff === '' && $xri === '') {
            return;
        }

        SecurityAlertService::log(SecurityAlertService::LEVEL_HIGH, 'rate_limit_xff_spoof', [
            'accion'    => $action,
            'remoto'    => $remote,
            'xff'       => mb_substr($xff, 0, 255),
            'x_real_ip' => $xri,
            'ip_logica' => self::getClientIp(),
        ]);
    }

    public static function getClientHostname(): string
    {
        $ip = self::getClientIp();
        if ($ip === '') {
            return '';
        }

        // gethostbyaddr() hace una consulta DNS bloqueante -- si el DNS
        // interno no tiene registro PTR para la IP, puede demorar hasta
        // el timeout de resolución en vez de fallar rápido. Si en algún
        // punto esto vuelve lentas las escrituras, conviene cachear por IP
        // en sesión (dentro de una misma sesión de trabajo la IP del
        // cliente no cambia) en vez de resolver en cada guardado.
        $host = @gethostbyaddr($ip);

        // gethostbyaddr() devuelve la MISMA ip como string si no pudo
        // resolver (no devuelve false en ese caso) -- se detecta así.
        if ($host === false || $host === $ip) {
            return $ip;
        }

        return $host;
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers privados
    // ──────────────────────────────────────────────────────────────

    /**
     * Normaliza y valida una IP (IPv4/IPv6). Devuelve '' si no es una IP
     * válida, quitando espacios y, en XFF, el puerto "ip:puerto".
     */
    private static function normalizeIp(string $value): string
    {
        $value = trim($value);
        $ip = '';

        if ($value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $value;
        }

        // X-Forwarded-For a veces viene como "ip:puerto" (p. ej. IPv4).
        $withoutPort = $value !== '' ? strrchr($value, ':') : false;
        if ($withoutPort !== false) {
            $candidate = substr($value, 0, strlen($value) - strlen($withoutPort));
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return $ip;
    }

    /**
     * Lista de proxies confiables desde TRUSTED_PROXIES.
     *
     * @return string[]
     */
    private static function trustedProxyEntries(): array
    {
        $raw = trim((string)($_ENV[self::ENV_TRUSTED_PROXIES] ?? ''));
        if ($raw === '') {
            return [];
        }
        $entries = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry !== '') {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /**
     * true si $ip coincide con una entrada (IP exacta o bloque CIDR).
     */
    private static function ipMatchesEntry(string $ip, string $entry): bool
    {
        if (str_contains($entry, '/')) {
            [$network, $prefix] = explode('/', $entry, 2);
            $network = self::normalizeIp($network);
            if ($network === '' || !ctype_digit($prefix)) {
                return false;
            }
            return self::ipInCidr($ip, $network, (int) $prefix);
        }

        return self::normalizeIp($entry) === $ip;
    }

    private static function ipInCidr(string $ip, string $network, int $prefix): bool
    {
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($network);
        $bits = is_string($ipBin) ? strlen($ipBin) * 8 : 0;

        $valid = $ipBin !== false
            && $netBin !== false
            && strlen($netBin) === strlen($ipBin)
            && $prefix >= 0
            && $prefix <= $bits;

        if (!$valid) {
            return false;
        }

        if ($prefix === 0) {
            return true;
        }

        $matches = true;
        $fullBytes = intdiv($prefix, 8);
        for ($i = 0; $i < $fullBytes; $i++) {
            if ($ipBin[$i] !== $netBin[$i]) {
                $matches = false;
                break;
            }
        }

        if ($matches) {
            $remBits = $prefix % 8;
            if ($remBits > 0) {
                $shift = 8 - $remBits;
                $mask = (0xFF << $shift) & 0xFF;
                if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($netBin[$fullBytes]) & $mask)) {
                    $matches = false;
                }
            }
        }

        return $matches;
    }
}
