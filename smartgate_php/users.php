<?php
// ============================================================
// SmartGate V4 - Gestion des élèves (avec filtre classes CPE)
// ============================================================
require_once __DIR__ . '/includes/auth.php';
requireLogin();
$db = getDB();

$error = $success = '';

// ── Ajout élève ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add'])) {
    $nom    = trim($_POST['nom']    ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $class  = trim($_POST['class']  ?? '');
    $rfid   = strtoupper(trim($_POST['rfid']        ?? '')) ?: null;
    $fid    = trim($_POST['fingerprint'] ?? '') ?: null;
    $name   = trim("$prenom $nom");

    // Vérifier que le CPE peut ajouter dans cette classe
    if (isCPE()) {
        $allowed = allowedClasses();
        if (!in_array($class, $allowed)) {
            $error = "❌ Vous n'êtes pas autorisé à ajouter des élèves dans la classe $class.";
        }
    }

    if (!$error) {
        if (!$name) {
            $error = "❌ Le nom est obligatoire.";
        } else {
            // Photo depuis caméra (déjà sauvegardée par camera_widget.js)
            $photo = trim($_POST['photo_filename'] ?? '') ?: null;
            // Fallback : upload fichier classique
            if (!$photo && !empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === 0) {
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                    $fname = strtolower($prenom).'_'.strtolower($nom).'_'.time().'.'.$ext;
                    $dest  = __DIR__ . '/assets/photos/' . $fname;
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
                        $photo = $fname;
                    }
                }
            }
            try {
                $stmt = $db->prepare("
                    INSERT INTO users
                    (name, student_class, rfid_uid, fingerprint_id, photo_filename, account_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $class, $rfid, $fid, $photo, currentAccountId()]);
                $newId = $db->lastInsertId();
                $db->prepare("INSERT INTO system_events (event_type,description,user_id)
                              VALUES ('USER_ADDED',?,?)")
                   ->execute(["Élève ajouté : $name ($class)", $newId]);
                $success = "✅ Élève $name ajouté avec succès.";
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'rfid_uid'))
                    $error = "❌ Ce RFID ($rfid) est déjà utilisé.";
                elseif (str_contains($msg, 'fingerprint_id'))
                    $error = "❌ Cette empreinte (ID $fid) est déjà utilisée.";
                else
                    $error = "❌ " . $e->getMessage();
            }
        }
    }
}

// ── Suppression ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete'])) {
    // Seul l'admin peut supprimer
    if (!isAdmin()) {
        $error = "❌ Seul l'administrateur peut supprimer un élève.";
    } else {
        $uid  = (int)$_POST['user_id'];
        $stmt = $db->prepare("SELECT * FROM users WHERE id=?");
        $stmt->execute([$uid]); $user = $stmt->fetch();
        if ($user) {
            $db->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
            if ($user['fingerprint_id']) {
                $db->prepare("INSERT IGNORE INTO fingerprint_delete_queue (fingerprint_id) VALUES (?)")
                   ->execute([$user['fingerprint_id']]);
            }
            $db->prepare("INSERT INTO system_events (event_type,description,extra_data)
                          VALUES ('USER_DELETED',?,?)")
               ->execute(["Élève supprimé : {$user['name']}", "ID=$uid"]);
            $success = "✅ Élève supprimé.";
        }
    }
}

// ── Déblocage RFID ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_unblock'])) {
    $uid  = (int)$_POST['user_id'];
    $stmt = $db->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([$uid]); $u = $stmt->fetch();
    if ($u && $u['rfid_blocked'] == 1) {
        $db->prepare("UPDATE users SET rfid_blocked=0, renewal_count=0 WHERE id=?")
           ->execute([$uid]);
        $db->prepare("DELETE FROM temporary_cards WHERE user_id=?")->execute([$uid]);
        $db->prepare("INSERT INTO system_events (event_type,description,user_id)
                      VALUES ('RFID_UNBLOCKED_MANUAL',?,?)")
           ->execute(["RFID débloqué : {$u['name']}", $uid]);
        $success = "✅ RFID débloqué.";
    }
}

// ── Liste élèves — filtrée par classes du CPE ────────────────
$filter = accountFilterSQL('u');
$users  = $db->query("
    SELECT id, name, student_class, rfid_uid, fingerprint_id,
           photo_filename, rfid_blocked, renewal_count, created_at
    FROM users u
    WHERE $filter
    ORDER BY student_class, name
")->fetchAll();

// Classes autorisées pour le formulaire d'ajout
$allowed_classes = allowedClasses();

include __DIR__ . '/includes/header.php';
?>

<h1>Gestion des élèves</h1>
<?php if (isCPE()): ?>
<div class="alert alert-info" style="margin-bottom:20px">
  👤 Vous êtes connecté en tant que CPE — vous gérez :
  <strong><?= htmlspecialchars(implode(', ', $allowed_classes)) ?></strong>
</div>
<?php endif; ?>

<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<h2>Élèves (<?= count($users) ?>)</h2>
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Photo</th><th>Nom</th><th>Classe</th><th>RFID</th>
        <th>Empreinte</th><th>Statut RFID</th><th>Renouvellements</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
    <tr>
      <td>
        <?php if ($u['photo_filename']): ?>
          <img class="avatar"
               src="/smartgate/assets/photos/<?= htmlspecialchars($u['photo_filename']) ?>"
               alt="" onerror="this.style.display='none'">
        <?php else: ?>
          <div style="width:40px;height:40px;border-radius:50%;background:#eee;
               display:flex;align-items:center;justify-content:center;font-size:18px">👤</div>
        <?php endif; ?>
      </td>
      <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
      <td><span class="badge badge-blue"><?= htmlspecialchars($u['student_class'] ?? '—') ?></span></td>
      <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($u['rfid_uid'] ?? '—') ?></td>
      <td><?= $u['fingerprint_id'] ?? '—' ?></td>
      <td>
        <?php if ($u['rfid_blocked'] == 2): ?>
          <span class="badge badge-danger">🔒 DÉFINITIF</span>
        <?php elseif ($u['rfid_blocked'] == 1): ?>
          <span class="badge badge-warn">🔒 Temporaire</span>
        <?php else: ?>
          <span class="badge badge-ok">✅ Actif</span>
        <?php endif; ?>
      </td>
      <td style="text-align:center;color:<?= $u['renewal_count']>=3?'#dc2626':'#888' ?>">
        <?= $u['renewal_count'] ?>/3
      </td>
      <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <a href="/smartgate/edit_user.php?id=<?= $u['id'] ?>"
             class="btn btn-orange btn-sm">✏️</a>
          <?php if ($u['rfid_blocked'] == 1): ?>
          <form method="POST" style="display:inline">
            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
            <button type="submit" name="action_unblock" class="btn btn-green btn-sm"
                    onclick="return confirm('Débloquer le RFID ?')">🔓</button>
          </form>
          <?php endif; ?>
          <?php if (isAdmin()): ?>
          <form method="POST" style="display:inline">
            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
            <button type="submit" name="action_delete" class="btn btn-red btn-sm"
                    onclick="return confirm('Supprimer <?= htmlspecialchars(addslashes($u['name'])) ?> ?')">🗑</button>
          </form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($users)): ?>
    <tr><td colspan="8" style="text-align:center;color:#aaa;padding:24px">
      Aucun élève
      <?= isCPE() ? 'dans vos classes' : 'enregistré' ?>
    </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Formulaire ajout -->
<h2>Ajouter un élève</h2>
<div class="form-card">
  <form method="POST" enctype="multipart/form-data">
    <div class="form-row">
      <div>
        <label>Prénom *</label>
        <input type="text" name="prenom" placeholder="Ex: Lucas" required>
      </div>
      <div>
        <label>Nom *</label>
        <input type="text" name="nom" placeholder="Ex: DUPONT" required>
      </div>
    </div>
    <div class="form-group">
      <label>Classe</label>
      <select name="class" required>
        <?php
        // Grouper les classes autorisées
        $groups = [
            'Seconde'   => ['2nde GT A','2nde GT B','2nde GT C','2nde Pro'],
            'Première'  => ['1ère Générale A','1ère Générale B','1ère STI2D','1ère STMG A','1ère STMG B','1ère Pro SN','1ère Pro MELEC'],
            'Terminale' => ['Tle Générale A','Tle Générale B','Tle STI2D','Tle STMG A','Tle STMG B','Tle Pro SN','Tle Pro MELEC'],
            'BTS'       => ['BTS S2CIEL A','BTS S2CIEL B','BTS SIO SLAM A','BTS SIO SLAM B','BTS SIO SISR A','BTS SIO SISR B','BTS SNIR A','BTS SNIR B','BTS ELEC A','BTS ELEC B'],
        ];
        foreach ($groups as $group => $classes):
            $visible = array_filter($classes, fn($c) => in_array($c, $allowed_classes));
            if (empty($visible)) continue;
        ?>
        <optgroup label="── <?= $group ?> ──">
          <?php foreach ($visible as $c): ?>
          <option value="<?= $c ?>"><?= $c ?></option>
          <?php endforeach; ?>
        </optgroup>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Badge RFID</label>
      <div class="scan-row">
        <input type="text" id="rfid" name="rfid" placeholder="UID RFID" readonly>
        <button type="button" class="btn btn-scan" id="btn-rfid"
                onclick="startScan('rfid')">📡 Scanner</button>
      </div>
      <div id="scan-status"></div>
    </div>
    <div class="form-group">
      <label>Empreinte digitale</label>
      <div class="scan-row">
        <input type="text" id="fingerprint" name="fingerprint"
               placeholder="ID empreinte" readonly>
        <button type="button" class="btn btn-scan" id="btn-fp"
                onclick="startScan('fingerprint')">🔏 Enrôler</button>
      </div>
      <div id="scan-status-fp"></div>
    </div>
    <div class="form-group">
      <label>📷 Photo</label>
      <div id="camera-section"></div>
      <input type="hidden" id="photo-captured" name="photo_filename">
    </div>
    <button type="submit" name="action_add" class="btn btn-primary btn-full">
      ➕ Ajouter l'élève
    </button>
  </form>
</div>

<script src="/smartgate/assets/camera_widget.js"></script>
<script>
// Widget caméra initialisé après chargement
document.addEventListener('DOMContentLoaded', function() {
    initCameraWidget('camera-section', 'eleve');
    const prenom = document.querySelector('input[name=prenom]');
    const nom    = document.querySelector('input[name=nom]');
    [prenom, nom].forEach(el => el.addEventListener('input', () => {
        const p = (prenom.value||'eleve').toLowerCase().replace(/[^a-z0-9]/g,'_');
        const n = (nom.value||'').toLowerCase().replace(/[^a-z0-9]/g,'_');
        window._cameraPrefix = p + (n?'_'+n:'');
    }));
});
function setStatus(id, msg, type) {
  const el = document.getElementById(id);
  el.className = type; el.textContent = msg; el.style.display='block';
}
async function startScan(type) {
  const isRfid   = type === 'rfid';
  const inputEl  = document.getElementById(isRfid?'rfid':'fingerprint');
  const btnEl    = document.getElementById(isRfid?'btn-rfid':'btn-fp');
  const statusId = isRfid?'scan-status':'scan-status-fp';
  const clearP   = isRfid?'/api/clear_rfid':'/api/clear_fingerprint';
  const readP    = isRfid?'/api/last_rfid':'/api/last_fingerprint';
  const field    = isRfid?'rfid':'fingerprint';
  btnEl.disabled=true; inputEl.value='';
  await fetch(`/smartgate/api/proxy.php?path=${clearP}`);
  if (!isRfid) {
    await fetch('/smartgate/api/proxy.php?path=/api/trigger_enroll');
    setStatus(statusId,'⏳ Posez le doigt (1ère fois)…','info');
  } else {
    setStatus(statusId,'⏳ Approchez la carte RFID…','info');
  }
  let tries=0;
  const poll=setInterval(async()=>{
    tries++;
    try {
      const data=await(await fetch(`/smartgate/api/proxy.php?path=${readP}`)).json();
      if(data[field]!=null){
        clearInterval(poll); inputEl.value=data[field]; btnEl.disabled=false;
        setStatus(statusId,`✅ ${isRfid?'RFID':'Empreinte'}: ${data[field]}`,'success');
        return;
      }
      if(!isRfid&&tries===8) setStatus(statusId,'⏳ Posez le même doigt (2ème fois)…','info');
    } catch(e) {
      clearInterval(poll); btnEl.disabled=false;
      setStatus(statusId,'❌ Erreur réseau.','error'); return;
    }
    if(tries>=20){clearInterval(poll);btnEl.disabled=false;setStatus(statusId,'⏱️ Temps écoulé.','error');}
  },2000);
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
