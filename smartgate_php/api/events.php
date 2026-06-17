<?php
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
$db = getDB();

$from = $_GET['from'] ?? null;
$to   = $_GET['to']   ?? null;
$where = []; $params = [];
if ($from) { $where[] = "DATE(timestamp) >= ?"; $params[] = $from; }
if ($to)   { $where[] = "DATE(timestamp) <= ?"; $params[] = $to; }

$sql = "SELECT * FROM SG_SYSTEM_EVENTS"; // Fix: nom correct sur MySQL Linux (case-sensitive)
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY timestamp DESC LIMIT 500";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

foreach ($events as &$e) {
    if ($e['timestamp']) $e['timestamp'] = date('d/m/Y H:i:s', strtotime($e['timestamp']));
}
echo json_encode($events);
