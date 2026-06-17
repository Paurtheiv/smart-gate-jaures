<?php
// Proxy vers l'API Flask port 5000
// Usage : /smartgate/api/proxy.php?path=/api/last_rfid
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

$path = $_GET['path'] ?? '';
if (!$path) { echo json_encode(['error' => 'path manquant']); exit; }

$result = apiGet($path);
echo json_encode($result ?? []);
