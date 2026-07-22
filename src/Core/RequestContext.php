<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Resuelve IP y hostname del cliente para los campos de auditoría
 * institucional (ipCrea/equipoCrea, ipModifica/equipoModifica).
 *
 * equipoCrea/equipoModifica se resuelven por DNS inverso de la IP del
 * cliente (convención confirmada contra otros sistemas institucionales).
 */
final class RequestContext
{
    public static function getClientIp(): string
    {
        // 1. X-Forwarded-For: si hay otro proxy/balanceador delante de Nginx.
        //    Se toma la primera IP de la lista (la más cercana al cliente).
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($forwarded) && trim($forwarded) !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if ($first !== '') {
                error_log('[RequestContext] IP via X-Forwarded-For: ' . $first);
                return $first;
            }
        }

        // 2. X-Real-IP: seteado explícitamente por nginx.conf en el bloque
        //    fastcgi (fastcgi_param HTTP_X_REAL_IP $remote_addr).
        $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        if (is_string($realIp) && trim($realIp) !== '') {
            error_log('[RequestContext] IP via HTTP_X_REAL_IP: ' . trim($realIp));
            return trim($realIp);
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        error_log('[RequestContext] IP via REMOTE_ADDR: "' . $remoteAddr . '"'
            . ' | X-Forwarded-For: "' . ($forwarded) . '"'
            . ' | X-Real-IP: "' . ($realIp) . '"');
        return $remoteAddr;
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
}
