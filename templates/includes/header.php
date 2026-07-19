<?php
declare(strict_types=1);
use App\Security\SecurityContext;

$currentPage = basename($_SERVER['PHP_SELF']);
$isAdmin = ($currentPage === 'AdminPanel.php');
$currentUser = SecurityContext::getCurrentUser();
$username = $currentUser['preferred_username'] ?? 'Invitado';
$isAdminRole = SecurityContext::hasRole($_ENV['KEYCLOAK_ROLE_ADMIN'] ?? 'ADMIN_ACADEMICO');
?>

<header style="display:flex;justify-content:space-between;align-items:center;background:#003366;color:white;padding:15px 25px;border-radius:8px;margin-bottom:20px;position:relative;">
    <div>
        <h1 style="font-size:1.5rem;margin:0;"><?= $isAdmin ? 'Panel de Administración' : 'Consulta de Registro Académico' ?></h1>
    </div>
    <nav style="position:relative;">
        <button type="button" id="userMenuBtn" aria-haspopup="true" aria-expanded="false"
                style="display:flex;align-items:center;gap:14px;background:transparent;border:none;color:white;padding:8px 4px;font-size:14px;cursor:pointer;">
            <span style="display:flex;align-items:center;gap:6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                <?= htmlspecialchars($username) ?>
            </span>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
        </button>
        <div id="userMenuDropdown"
             style="display:none;position:absolute;top:calc(100% + 12px);right:0;background:white;color:#333;border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,0.18);min-width:260px;overflow:hidden;z-index:1100;">
            <a href="workspace.php"
               style="display:flex;align-items:center;gap:14px;padding:16px 20px;text-decoration:none;font-size:15px;
                      <?= !$isAdmin ? 'background:#e7f0fe;color:#003366;font-weight:600;' : 'color:#333;' ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                Mesa de Consulta
            </a>
            <?php if ($isAdminRole): ?>
                <a href="AdminPanel.php"
                   style="display:flex;align-items:center;gap:14px;padding:16px 20px;text-decoration:none;font-size:15px;
                          <?= $isAdmin ? 'background:#e7f0fe;color:#003366;font-weight:600;' : 'color:#333;' ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                    Panel de Configuración
                </a>
            <?php endif; ?>
            <a href="logout.php"
               style="display:flex;align-items:center;gap:14px;padding:16px 20px;text-decoration:none;font-size:15px;color:#e85d5d;border-top:1px solid #f0f2f5;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                Salir
            </a>
        </div>
    </nav>
</header>
<script>
    (function () {
        const btn = document.getElementById('userMenuBtn');
        const menu = document.getElementById('userMenuDropdown');
        if (!btn || !menu) return;

        function openMenu() { menu.style.display = 'block'; btn.setAttribute('aria-expanded', 'true'); }
        function closeMenu() { menu.style.display = 'none'; btn.setAttribute('aria-expanded', 'false'); }
        function toggleMenu(e) {
            e.stopPropagation();
            menu.style.display === 'block' ? closeMenu() : openMenu();
        }

        btn.addEventListener('click', toggleMenu);
        document.addEventListener('click', (e) => {
            if (!menu.contains(e.target) && e.target !== btn) closeMenu();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeMenu();
        });

        // Resaltado hover para los ítems no activos (los activos ya tienen
        // el fondo azul fijo definido inline).
        menu.querySelectorAll('a').forEach(a => {
            const isActive = a.style.backgroundColor !== '';
            if (isActive) return;
            a.addEventListener('mouseenter', () => { a.style.background = '#f5f7fa'; });
            a.addEventListener('mouseleave', () => { a.style.background = ''; });
        });
    })();
</script>