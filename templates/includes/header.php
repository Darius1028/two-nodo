<?php
declare(strict_types=1);
use App\Security\SecurityContext;

$currentPage = basename($_SERVER['PHP_SELF']);
$isAdmin = ($currentPage === 'AdminPanel.php');
$currentUser = SecurityContext::getCurrentUser();
$username = $currentUser['preferred_username'] ?? 'Invitado';
?>

<header style="display:flex;justify-content:space-between;align-items:center;background:#003366;color:white;padding:15px 25px;border-radius:8px;margin-bottom:20px;">
    <div>
        <h1 style="font-size:1.5rem;margin:0;"><?= $isAdmin ? 'Panel de Administración' : 'Consulta de Registro Académico' ?></h1>
    </div>
    <nav style="display:flex;align-items:center;gap:20px;">
        <span style="font-size:14px;">👤 <?= htmlspecialchars($username) ?></span>
        <a href="workspace.php" style="color:white;text-decoration:none;">Mesa</a>
        <?php if (SecurityContext::hasRole($_ENV['KEYCLOAK_ROLE_ADMIN'] ?? 'ROLE_ADMIN')): ?>
            <a href="AdminPanel.php" style="color:white;text-decoration:none;">Admin</a>
        <?php endif; ?>
        <a href="logout.php" style="color:#ff9999;text-decoration:none;">Salir</a>
    </nav>
</header>