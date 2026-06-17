<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
$db = getDB();

$id   = (int)($_GET['id'] ?? 0);
$user = $db->prepare("SELECT * FROM users WHERE id=?");
$user->execute([$id]);
$u    = $user->fetch();
if (!$u) { header('Location: /smartgate/users.php'); exit; }

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom    = trim($_POST['nom']    ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $class  = trim($_POST['class']  ?? '');
    $rfid   = strtoupper(trim($_POST['rfid']        ?? '')) ?: null;
    $fid    = trim($_POST['fingerprint'] ?? '') ?: null;
    $name   = trim("$prenom $nom");

    $photo = trim($_POST['photo_filename'] ?? '') ?: $u['photo_filename'];
    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $fname = strtolower($prenom) . '_' . strtolower($nom) . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['photo']['tmp_name'], __DIR__ . '/assets/photos/' . $fname);
            $photo = $fname;
        }
    }

    try {
        $db->prepare("UPDATE users SET name=?,student_class=?,rfid_uid=?,
                      fingerprint_id=?,photo_filename=? WHERE id=?")
           ->execute([$name, $class, $rfid, $fid, $photo, $id]);
        $db->prepare("INSERT INTO system_events (event_type,description,user_id)
                      VALUES ('USER_UPDATED',?,?)")
           ->execute(["Élève modifié : $name", $id]);
        $success = "✅ Modifications enregistrées.";
        // Recharger
        $user->execute([$id]); $u = $user->fetch();
    } catch (PDOException $e) {
        $error = "❌ " . $e->getMessage();
    }
}

// Extraire prénom/nom
$parts  = explode(' ', $u['name'], 2);
$prenom = $parts[0] ?? '';
$nom    = $parts[1] ?? '';

$classes = ['2nde GT A','2nde GT B','2nde GT C','2nde Pro',
            '1ère Générale A','1ère Générale B','1ère STI2D','1ère STMG A','1ère STMG B','1ère Pro SN','1ère Pro MELEC',
            'Tle Générale A','Tle Générale B','Tle STI2D','Tle STMG A','Tle STMG B','Tle Pro SN','Tle Pro MELEC',
            'BTS S2CIEL A','BTS S2CIEL B','BTS SIO SLAM A','BTS SIO SLAM B',
            'BTS SIO SISR A','BTS SIO SISR B','BTS SNIR A','BTS SNIR B','BTS ELEC A','BTS ELEC B'];

include __DIR__ . '/includes/header.php';
?>

<h1>Modifier un élève</h1>
<a href="/smartgate/users.php" class="btn btn-gray" style="margin-bottom:20px">← Retour</a>

<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<!-- Statut RFID -->
<div class="form-card" style="margin-bottom:20px">
  <div style="font-weight:bold;font-size:15px;margin-bottom:12px">🔐 Statut badge RFID</div>
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
    <?php if ($u['rfid_blocked'] == 2): ?>
      <span class="badge badge-danger">🔒 Bloqué DÉFINITIVEMENT</span>
      <span style="font-size:12px;color:#666">Limite de renouvellements atteinte — non modifiable</span>
    <?php elseif ($u['rfid_blocked'] == 1): ?>
      <span class="badge badge-warn">🔒 Bloqué temporairement</span>
      <span style="font-size:12px;color:#666">Carte temporaire active</span>
    <?php else: ?>
      <span class="badge badge-ok">✅ Actif</span>
    <?php endif; ?>
    <span style="font-size:12px;color:#888">Renouvellements : <?= $u['renewal_count'] ?>/3</span>
  </div>

  <?php if ($u['rfid_blocked'] == 1): ?>
  <div style="margin-top:14px">
    <form method="POST" action="/smartgate/users.php">
      <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
      <button type="submit" name="action_unblock" class="btn btn-green"
              onclick="return confirm('Débloquer le RFID ? Cela supprime toutes les cartes temp et remet le compteur à 0.')">
        🔓 Débloquer le RFID (reset complet)
      </button>
    </form>
    <p style="font-size:12px;color:#888;margin-top:6px">
      Supprime les cartes temp. et remet le compteur à 0.
    </p>
  </div>
  <?php endif; ?>
</div>

<!-- Formulaire modification -->
<div class="form-card">
  <?php if ($u['photo_filename']): ?>
  <div style="text-align:center;margin-bottom:16px">
    <img src="/smartgate/assets/photos/<?= htmlspecialchars($u['photo_filename']) ?>"
         style="width:70px;height:70px;border-radius:50%;object-fit:cover;border:3px solid #22c55e" alt="">
    <div style="font-size:12px;color:#888;margin-top:4px">Photo actuelle</div>
  </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data">
    <div class="form-row">
      <div>
        <label>Prénom</label>
        <input type="text" name="prenom" value="<?= htmlspecialchars($prenom) ?>" required>
      </div>
      <div>
        <label>Nom</label>
        <input type="text" name="nom" value="<?= htmlspecialchars($nom) ?>" required>
      </div>
    </div>
    <div class="form-group">
      <label>Classe</label>
      <select name="class">
        <?php foreach ($classes as $c): ?>
        <option value="<?= $c ?>" <?= $u['student_class'] === $c ? 'selected' : '' ?>><?= $c ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Badge RFID</label>
      <div class="scan-row">
        <input type="text" id="rfid" name="rfid"
               value="<?= htmlspecialchars($u['rfid_uid'] ?? '') ?>" readonly>
        <button type="button" class="btn btn-scan" onclick="startScan('rfid')">📡 Scanner</button>
      </div>
      <div id="scan-status"></div>
    </div>
    <div class="form-group">
      <label>Empreinte</label>
      <div class="scan-row">
        <input type="text" id="fingerprint" name="fingerprint"
               value="<?= htmlspecialchars($u['fingerprint_id'] ?? '') ?>" readonly>
        <button type="button" class="btn btn-scan" onclick="startScan('fingerprint')">🔏 Enrôler</button>
      </div>
      <div id="scan-status-fp"></div>
    </div>
    <div class="form-group">
      <label>📷 Nouvelle photo (laisser vide pour garder l'actuelle)</label>
      <div id="camera-section"></div>
      <input type="hidden" id="photo-captured" name="photo_filename">
    </div>
    <div style="display:flex;gap:8px;margin-top:8px">
      <button type="submit" class="btn btn-primary btn-full">💾 Enregistrer</button>
      <a href="/smartgate/users.php" class="btn btn-gray btn-full" style="text-align:center">Annuler</a>
    </div>
  </form>
</div>

<script>
function previewPhoto(input) {
  const p = document.getElementById('photo-preview');
  if (input.files[0]) { p.src = URL.createObjectURL(input.files[0]); p.style.display = 'block'; }
}
function setStatus(id, msg, type) {
  const el = document.getElementById(id);
  el.className = type; el.textContent = msg; el.style.display = 'block';
}
async function startScan(type) {
  const isRfid   = type === 'rfid';
  const inputEl  = document.getElementById(isRfid ? 'rfid' : 'fingerprint');
  const statusId = isRfid ? 'scan-status' : 'scan-status-fp';
  const clearP   = isRfid ? '/api/clear_rfid' : '/api/clear_fingerprint';
  const readP    = isRfid ? '/api/last_rfid'   : '/api/last_fingerprint';
  const field    = isRfid ? 'rfid' : 'fingerprint';
  inputEl.value  = '';
  await fetch(`/smartgate/api/proxy.php?path=${clearP}`);
  if (!isRfid) { await fetch('/smartgate/api/proxy.php?path=/api/trigger_enroll'); setStatus(statusId,'⏳ Posez le doigt…','info'); }
  else setStatus(statusId,'⏳ Approchez la carte…','info');
  let tries=0;
  const poll=setInterval(async()=>{
    tries++;
    const data=await(await fetch(`/smartgate/api/proxy.php?path=${readP}`)).json();
    if(data[field]!=null){clearInterval(poll);inputEl.value=data[field];setStatus(statusId,`✅ ${data[field]}`,'success');return;}
    if(!isRfid&&tries===8)setStatus(statusId,'⏳ Posez le même doigt…','info');
    if(tries>=20){clearInterval(poll);setStatus(statusId,'⏱️ Temps écoulé.','error');}
  },2000);
}

document.addEventListener('DOMContentLoaded', function() {
    initCameraWidget('camera-section', 'photo_eleve');
});
</script>
<script src="/smartgate/assets/camera_widget.js"></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
