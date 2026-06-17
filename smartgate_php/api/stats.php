<?php
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
$db    = getDB();
$stats = $db->query("SELECT * FROM v_stats_today")->fetch();
echo json_encode($stats);
