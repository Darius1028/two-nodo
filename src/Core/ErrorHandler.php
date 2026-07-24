<?php

declare(strict_types=1);

namespace App\Core;

use App\Exception\SystemException;
use ErrorException;
use Throwable;

/**
 * Equivalente a un GlobalExceptionHandler: registra un manejador único para
 * cualquier excepción no controlada en cualquier punto del sistema. Sin
 * esto, un error sin capturar (Keycloak caído, la BD sin responder, un bug
 * cualquiera) termina en el error crudo de PHP -- con rutas de servidor y
 * stack trace -- servido directo al navegador del usuario final.
 *
 * Uso: llamar a ErrorHandler::register() lo antes posible en cada script de
 * entrada público (después de cargar el autoload/.env, antes de cualquier
 * otra cosa). No lo uses en diagnostico.php -- ese script maneja sus
 * propios errores a propósito, para mostrarlos.
 */
final class ErrorHandler
{
    public static function register(): void
    {
        // No confiar en el display_errors del php.ini del entorno donde
        // termine corriendo esto -- se fuerza acá.
        ini_set('display_errors', '0');
        error_reporting(E_ALL);

        // Convierte warnings/notices no capturados en excepciones, para que
        // pasen por el mismo manejador de abajo en vez de imprimirse solos.
        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $severity)) {
                return false; // silenciado con @ o por error_reporting()
            }
            throw new SystemException($message, 0, $severity, $file, $line);
        });

        set_exception_handler([self::class, 'handle']);
    }

    public static function handle(Throwable $e): void
    {
        error_log(sprintf(
            'Uncaught %s: %s in %s:%d' . PHP_EOL . 'Trace: %s',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));

        if (!headers_sent()) {
            http_response_code(500);
        }

        $appDebug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);

        if (self::wantsJson()) {
            self::renderJson($e, $appDebug);
            return;
        }

        self::renderHtml($appDebug ? $e : null);
    }

    private static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        return str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json')
            || str_contains($scriptName, 'api.php');
    }

    private static function renderJson(Throwable $e, bool $appDebug): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'error' => $appDebug
                ? $e->getMessage()
                : 'Ocurrió un error inesperado. Si el problema continúa, contactá al área de soporte técnico.',
        ], JSON_UNESCAPED_UNICODE);
    }

    private static function renderHtml(?Throwable $debugException): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>Ocurrió un error</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; color: #333; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
                .box { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); padding: 40px; max-width: 520px; text-align: center; }
                .box span { font-size: 48px; }
                h1 { color: #003366; font-size: 1.3rem; margin: 15px 0 10px; }
                p { color: #666; font-size: 14px; line-height: 1.5; margin-bottom: 25px; }
                a { display: inline-block; background: #003366; color: white; text-decoration: none; padding: 10px 24px; border-radius: 6px; font-size: 14px; font-weight: 600; }
                pre { text-align: left; background: #f5f7fa; padding: 12px; border-radius: 6px; font-size: 12px; overflow: auto; max-height: 300px; margin-bottom: 20px; }
            </style>
        </head>
        <body>
        <div class="box">
            <span>⚠️</span>
            <h1>Ocurrió un error inesperado</h1>
            <p>El sistema no pudo completar la operación. Si el problema continúa, contactá al área de soporte técnico.</p>
            <?php if ($debugException !== null): ?>
                <pre><?= htmlspecialchars(
                        $debugException->getMessage() . "\n\n" . $debugException->getTraceAsString(),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?></pre>
            <?php endif; ?>
            <a href="workspace.php">Volver al inicio</a>
        </div>
        </body>
        </html>
        <?php
    }
}
