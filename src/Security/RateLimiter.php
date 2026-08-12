<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Rate limiting simple basado en archivo (con file locking), pensado para
 * endpoints públicos sin sesión -- hoy, api.php?action=verify_certificate,
 * que consume el validador de QR de WordPress y por diseño no requiere
 * login. No necesita Redis/Memcached; alcanza sobradamente para el
 * volumen esperado de un validador de certificados.
 */
final class RateLimiter
{
    private const PATH = __DIR__ . '/../../var/cache/rate_limit.json';

    /**
     * @return bool true si la request entra dentro del límite, false si hay
     *              que rechazarla (demasiadas requests para esta $key en
     *              la ventana de tiempo).
     */
    public static function allow(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $dir = dirname(self::PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $fp = fopen(self::PATH, 'c+');
        if ($fp === false) {
            // Si no se puede abrir el archivo (permisos, disco lleno, etc.)
            // no bloqueamos el servicio real por un problema del limiter.
            return true;
        }

        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        $now = time();
        // Limpieza de entradas vencidas para que el archivo no crezca sin
        // límite con IPs viejas.
        foreach ($data as $k => $entry) {
            if (($entry['reset_at'] ?? 0) < $now) {
                unset($data[$k]);
            }
        }

        $entry = $data[$key] ?? null;
        if ($entry === null || ($entry['reset_at'] ?? 0) < $now) {
            $entry = ['count' => 0, 'reset_at' => $now + $windowSeconds];
        }

        $entry['count']++;
        $data[$key] = $entry;
        $allowed = $entry['count'] <= $maxRequests;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string)json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return $allowed;
    }
}