<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Se encarga exclusivamente de renderizar las vistas (HTML) relacionadas
 * con errores de seguridad, autenticación y autorización.
 */
class AuthResponder
{
    private const HEADER_HTML_UTF8 = 'Content-Type: text/html; charset=utf-8';

    public static function renderAccessDeniedPage(): void
    {
        http_response_code(403);
        header(self::HEADER_HTML_UTF8);
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>Acceso Denegado</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: #f5f7fa; color: #333; display: flex;
                    align-items: center; justify-content: center; min-height: 100vh; margin: 0;
                }
                .box {
                    background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);
                    padding: 40px; max-width: 420px; text-align: center;
                }
                .box span { font-size: 48px; }
                h1 { color: #003366; font-size: 1.3rem; margin: 15px 0 10px; }
                p { color: #666; font-size: 14px; line-height: 1.5; margin-bottom: 25px; }
                .buttons { display: flex; gap: 10px; justify-content: center; }
                a {
                    display: inline-block; background: #003366; color: white;
                    text-decoration: none; padding: 10px 24px; border-radius: 6px;
                    font-size: 14px; font-weight: 600; transition: background 0.2s;
                }
                a:hover { background: #004080; }
                a.secondary { background: #f1f5f9; color: #475569; }
                a.secondary:hover { background: #e2e8f0; }
            </style>
        </head>
        <body>
        <div class="box">
            <span>🔒</span>
            <h1>Acceso Denegado</h1>
            <p>No tenés permiso para acceder a esta sección.<br>Tu usuario no tiene el rol requerido.</p>
            <div class="buttons">
                <a href="javascript:history.back()" class="secondary">← Volver atrás</a>
                <a href="logout.php">Cerrar sesión</a>
            </div>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    public static function renderNoRolesPage(string $username, string $adminRole, string $userRole): void
    {
        http_response_code(403);
        header(self::HEADER_HTML_UTF8);
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Acceso Denegado</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;
                }
                .container {
                    background: white; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                    padding: 40px; max-width: 520px; width: 100%; text-align: center;
                }
                .icon {
                    width: 64px; height: 64px; margin: 0 auto 20px; background: #fee2e2;
                    border-radius: 50%; display: flex; align-items: center; justify-content: center;
                }
                .icon svg { width: 32px; height: 32px; color: #dc2626; }
                h1 { font-size: 24px; color: #1e293b; margin-bottom: 16px; font-weight: 700; }
                .message { color: #64748b; font-size: 15px; line-height: 1.6; margin-bottom: 24px; }
                .hint {
                    background: #eff6ff; border-left: 4px solid #3b82f6; padding: 14px;
                    border-radius: 4px; text-align: left; margin-bottom: 24px; color: #1e40af; font-size: 14px;
                }
                .btn {
                    display: inline-flex; align-items: center; justify-content: center;
                    gap: 8px; padding: 12px 20px; border-radius: 8px; text-decoration: none;
                    font-weight: 600; font-size: 14px; transition: all 0.2s; border: none;
                    cursor: pointer; background: #3b82f6; color: white;
                }
                .btn:hover {
                    background: #2563eb; transform: translateY(-1px);
                    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
                }
            </style>
        </head>
        <body>
        <div class="container">
            <div class="icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h1>Acceso Denegado</h1>
            <p class="message">
                El usuario <strong><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></strong>
                no tiene ningún rol asignado para esta aplicación.
            </p>
            <div class="hint">
                Contactá al administrador para que te asigne el rol
                <strong><?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?></strong>
                o <strong><?= htmlspecialchars($adminRole, ENT_QUOTES, 'UTF-8') ?></strong>.
            </div>
            <a href="logout.php" class="btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                Cerrar sesión
            </a>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    public static function renderLoginFailedPage(): void
    {
        http_response_code(401);
        header(self::HEADER_HTML_UTF8);
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>No se pudo iniciar sesión</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: #f5f7fa; color: #333; display: flex; align-items: center;
                    justify-content: center; min-height: 100vh; margin: 0;
                }
                .box {
                    background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);
                    padding: 40px; max-width: 420px; text-align: center;
                }
                .box span { font-size: 48px; }
                h1 { color: #003366; font-size: 1.3rem; margin: 15px 0 10px; }
                p { color: #666; font-size: 14px; line-height: 1.5; margin-bottom: 25px; }
                a {
                    display: inline-block; background: #003366; color: white;
                    text-decoration: none; padding: 10px 24px; border-radius: 6px;
                    font-size: 14px; font-weight: 600;
                }
            </style>
        </head>
        <body>
        <div class="box">
            <span>🔒</span>
            <h1>No se pudo iniciar sesión</h1>
            <p>Ocurrió un problema al validar tus credenciales. Verificá tu usuario y contraseña, o intentá nuevamente en unos minutos. Si el problema continúa, contactá al área de soporte técnico.</p>
            <a href="login.php">Volver a intentar</a>
        </div>
        </body>
        </html>
        <?php
        exit;
    }
}