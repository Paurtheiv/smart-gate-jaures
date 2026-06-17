<?php
// ============================================================
// SmartGate V4 - Login multi-comptes
// ============================================================
require_once __DIR__ . '/config/db.php';
session_start();

if (!empty($_SESSION['account'])) {
    header('Location: /smartgate/dashboard.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username && $password) {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT * FROM admin_accounts
            WHERE username = ?
            AND password_hash = SHA2(?, 256)
            AND active = 1
        ");
        $stmt->execute([$username, $password]);
        $account = $stmt->fetch();

        if ($account) {
            $_SESSION['account']    = $account;
            $_SESSION['login_time'] = time();
            header('Location: /smartgate/dashboard.php'); exit;
        }
        $error = "❌ Identifiants incorrects.";
    } else {
        $error = "❌ Remplissez tous les champs.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>SmartGate V4 — Connexion</title>
<link rel="stylesheet" href="/smartgate/assets/style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">🔐</div>
    <div class="login-title">SmartGate V4</div>
    <div class="login-sub">Lycée Jean Jaurès — Administration</div>

    <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group" style="text-align:left">
        <label>Identifiant</label>
        <input type="text" name="username" placeholder="Ex: admin ou cpe_zone1"
               autofocus required>
      </div>
      <div class="form-group" style="text-align:left">
        <label>Mot de passe</label>
        <input type="password" name="password" placeholder="••••••••••" required>
      </div>
      <button type="submit" class="btn btn-primary btn-full" style="margin-top:8px">
        🔓 Connexion
      </button>
    </form>

    <div style="margin-top:24px;font-size:12px;color:#bbb">
      SmartGate V4 — Zone 1 Entrée principale
    </div>
  </div>
</div>
</body>
</html>
