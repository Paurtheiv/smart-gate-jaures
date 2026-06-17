<?php
// ============================================================
// SmartGate V4 - API capture photo depuis caméra
// ============================================================
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['account'])) {
    echo json_encode(['ok' => false, 'error' => 'Non autorisé']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$image_data = $data['image'] ?? '';
$filename   = $data['filename'] ?? '';

if (!$image_data || !$filename) {
    echo json_encode(['ok' => false, 'error' => 'Données manquantes']);
    exit;
}

// Nettoyer le nom de fichier
$filename = preg_replace('/[^a-z0-9_\-\.]/', '', strtolower($filename));
if (!$filename) $filename = 'photo_' . time() . '.jpg';
if (!str_ends_with($filename, '.jpg')) $filename .= '.jpg';

// Décoder le base64
$image_data = preg_replace('/^data:image\/\w+;base64,/', '', $image_data);
$image_data = base64_decode($image_data);

if (!$image_data) {
    echo json_encode(['ok' => false, 'error' => 'Image invalide']);
    exit;
}

// Sauvegarder
$dest = __DIR__ . '/../assets/photos/' . $filename;
if (file_put_contents($dest, $image_data)) {
    echo json_encode(['ok' => true, 'filename' => $filename]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Impossible de sauvegarder']);
}
