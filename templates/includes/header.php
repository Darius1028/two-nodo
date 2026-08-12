<?php
// templates/includes/header.php
// Header compartido con control de acceso por roles

use App\Security\SecurityContext;

// Determinar roles del usuario actual
$adminRole = $_ENV['KEYCLOAK_ROLE_ADMIN'] ?? 'ADMIN_ACADEMICO';
$userRole  = $_ENV['KEYCLOAK_ROLE_USER']  ?? 'SECRE_ACADEMICO';

$hasAdmin = SecurityContext::hasRole($adminRole);
$hasUser  = SecurityContext::hasRole($userRole);
?>

<header style="display:flex;justify-content:space-between;align-items:center;background:#003366;color:white;padding:15px 25px;border-radius:8px;margin-bottom:20px;position:relative;">
    <div>
        <h1 style="font-size:1.5rem;margin:0;">Panel de Administración</h1>
    </div>
    <nav style="position:relative;">
        <button type="button" id="userMenuBtn" aria-haspopup="true" aria-expanded="false" style="display:flex;align-items:center;gap:14px;background:transparent;border:none;color:white;padding:8px 4px;font-size:14px;cursor:pointer;">
            <span style="display:flex;align-items:center;gap:6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                <?= htmlspecialchars($currentUser['preferred_username'] ?? '') ?>
            </span>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
        </button>
        <div id="userMenuDropdown" style="display: none; position: absolute; top: calc(100% + 12px); right: 0px; background: white; color: rgb(51, 51, 51); border-radius: 12px; box-shadow: rgba(0, 0, 0, 0.18) 0px 12px 32px; min-width: 260px; overflow: hidden; z-index: 1100;">
            <?php if ($hasUser): ?>
                <a href="workspace.php" style="display:flex;align-items:center;gap:14px;padding:16px 20px;text-decoration:none;font-size:15px;color:#333;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                    Mesa de Consulta
                </a>
            <?php endif; ?>

            <?php if ($hasAdmin): ?>
                <a href="AdminPanel.php" style="display:flex;align-items:center;gap:14px;padding:16px 20px;text-decoration:none;font-size:15px;background:#e7f0fe;color:#003366;font-weight:600;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                    Panel de Configuración
                </a>
            <?php endif; ?>

            <a href="logout.php" style="display:flex;align-items:center;gap:14px;padding:16px 20px;text-decoration:none;font-size:15px;color:#e85d5d;border-top:1px solid #f0f2f5;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                Salir
            </a>
        </div>
    </nav>
</header>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const btn = document.getElementById('userMenuBtn');
        const dropdown = document.getElementById('userMenuDropdown');

        if (btn && dropdown) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const isVisible = dropdown.style.display === 'block';
                dropdown.style.display = isVisible ? 'none' : 'block';
                btn.setAttribute('aria-expanded', !isVisible);
            });

            document.addEventListener('click', function(e) {
                if (!dropdown.contains(e.target) && e.target !== btn) {
                    dropdown.style.display = 'none';
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        }
    });
</script>