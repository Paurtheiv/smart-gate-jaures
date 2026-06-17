<?php
// ============================================================
// SmartGate V4 - Authentification multi-comptes
// ============================================================
require_once __DIR__ . '/../config/db.php';

function requireLogin() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['account'])) {
        header('Location: /smartgate/login.php');
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: /smartgate/dashboard.php?error=not_admin');
        exit;
    }
}
