<?php
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
$db = getDB();

$from   = $_GET['from']   ?? null;
$to     = $_GET['to']     ?? null;
$status = $_GET['status'] ?? null;
$method = $_GET['method'] ?? null;

$where  = [];
$params = [];

if ($from)   { $where[] = "DATE(timestamp) >= ?"; $params[] = $from; }
if ($to)     { $where[] = "DATE(timestamp) <= ?"; $params[] = $to; }
if ($status) { $where[] = "access_status = ?";    $params[] = $status; }
if ($method) { $where[] = "authentication_method = ?"; $params[] = $method; }

$sql = "SELECT * FROM v_access_logs";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " LIMIT 500";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Convertir datetime en string
foreach ($logs as &$l) {
    if ($l['timestamp']) $l['timestamp'] = date('d/m/Y H:i:s', strtotime($l['timestamp']));
}

echo json_encode($logs);
