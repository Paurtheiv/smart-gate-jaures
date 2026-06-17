<?php
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
$db = getDB();
$q  = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode([]); exit; }

// MIGRÉ vers base unifiée smartgate_db (07/05/2026)
// Colonnes renommées : name→CONCAT(prenom,nom), student_class→classe, rfid_uid→uid_badge
// Alias conservés pour compatibilité JS (name, student_class, rfid_uid)
$stmt = $db->prepare("
    SELECT id,
           CONCAT(prenom, ' ', nom)  AS name,
           classe                    AS student_class,
           uid_badge                 AS rfid_uid,
           fingerprint_id,
           photo_filename,
           rfid_blocked,
           renewal_count
    FROM UTILISATEURS
    WHERE CONCAT(prenom, ' ', nom) LIKE ?
       OR nom   LIKE ?
       OR prenom LIKE ?
       OR UPPER(uid_badge) = ?
    ORDER BY nom, prenom
    LIMIT 10
");
$stmt->execute(["%$q%", "%$q%", "%$q%", strtoupper($q)]);
echo json_encode($stmt->fetchAll());
