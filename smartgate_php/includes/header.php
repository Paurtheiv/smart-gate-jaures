<?php
require_once __DIR__ . '/../config/db.php';
$page    = basename($_SERVER['PHP_SELF'], '.php');
$account = currentUser();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SmartGate V4 — <?= htmlspecialchars(ucfirst($page)) ?></title>
<link rel="stylesheet" href="/smartgate/assets/style.css">
</head>
<body>
<nav class="navbar">
  <div class="nav-brand">⬛ SmartGate V4</div>
  <div class="nav-links">
    <a href="/smartgate/dashboard.php"
       class="<?= $page==='dashboard'       ?'active':'' ?>">Dashboard</a>
    <a href="/smartgate/users.php"
       class="<?= $page==='users'           ?'active':'' ?>">Élèves</a>
    <a href="/smartgate/logs.php"
       class="<?= $page==='logs'            ?'active':'' ?>">Logs</a>
    <a href="/smartgate/temporary_cards.php"
       class="<?= $page==='temporary_cards' ?'active':'' ?>">Cartes temp.</a>
    <?php if (isAdmin()): ?>
    <a href="/smartgate/accounts.php"
       class="<?= $page==='accounts'        ?'active':'' ?>">👑 Comptes</a>
    <?php endif; ?>
    <a href="/smartgate/terminal.php" target="_blank">Terminal ↗</a>
    <!-- Nom connecté + rôle -->
    <span style="color:#aaa;font-size:13px;padding:0 8px;border-left:1px solid #333;margin-left:4px">
      <?= isAdmin() ? '👑' : '👤' ?>
      <?= htmlspecialchars($account['display_name'] ?? $account['username'] ?? '') ?>
    </span>
    <a href="/smartgate/logout.php" class="btn-logout">🚪</a>
  </div>
</nav>
<div class="container">
