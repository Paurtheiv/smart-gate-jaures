<?php
// ============================================================
// SmartGate V4 - Gestion des comptes (ADMIN seulement)
// ============================================================
require_once __DIR__ . '/includes/auth.php';
requireAdmin();
$db = getDB();

$error = $success = '';

// ── Créer un compte ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create'])) {
    $username     = trim($_POST['username']      ?? '');
    $password     = trim($_POST['password']      ?? '');
    $role         = $_POST['role']               ?? 'cpe';
    $display_name = trim($_POST['display_name']  ?? '');
    $zone         = trim($_POST['zone']          ?? 'GATE_1');
    $finger_pass  = trim($_POST['finger_password'] ?? '4A4A3200');
    // Classes cochées
    $classes_arr  = $_POST['classes'] ?? [];
    $classes_str  = $role === 'admin' ? 'ALL' : implode(',', $classes_arr);

    if (!$username || !$password) {
        $error = "❌ Identifiant et mot de passe obligatoires.";
    } elseif ($role === 'cpe' && empty($classes_arr)) {
        $error = "❌ Sélectionnez au moins une classe pour ce CPE.";
    } else {
        try {
            $db->prepare("
                INSERT INTO admin_accounts
                (username, password_hash, role, display_name, zone, finger_password, classes)
                VALUES (?, SHA2(?,256), ?, ?, ?, ?, ?)
            ")->execute([$username, $password, $role,
                         $display_name, $zone, $finger_pass, $classes_str]);
            $success = "✅ Compte '$username' créé avec " . count($classes_arr) . " classe(s).";
        } catch (PDOException $e) {
            $error = str_contains($e->getMessage(), 'Duplicate')
                   ? "❌ Cet identifiant existe déjà."
                   : "❌ " . $e->getMessage();
        }
    }
}

// ── Supprimer un compte ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete'])) {
    $id = (int)$_POST['account_id'];
    if ($id === (int)currentAccountId()) {
        $error = "❌ Vous ne pouvez pas supprimer votre propre compte.";
    } else {
        $db->prepare("DELETE FROM admin_accounts WHERE id=?")->execute([$id]);
        $success = "✅ Compte supprimé.";
    }
}

// ── Changer mot de passe ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_pwd'])) {
    $id      = (int)$_POST['account_id'];
    $new_pwd = trim($_POST['new_password'] ?? '');
    if (!$new_pwd) { $error = "❌ Mot de passe vide."; }
    else {
        $db->prepare("UPDATE admin_accounts SET password_hash=SHA2(?,256) WHERE id=?")
           ->execute([$new_pwd, $id]);
        $success = "✅ Mot de passe modifié.";
    }
}

// ── Activer / Désactiver ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_toggle'])) {
    $id = (int)$_POST['account_id'];
    $db->prepare("UPDATE admin_accounts SET active = 1 - active WHERE id=?")
       ->execute([$id]);
    $success = "✅ Statut modifié.";
}

$accounts = $db->query("SELECT * FROM admin_accounts ORDER BY role, username")->fetchAll();

// Grouper les classes par niveau pour l'affichage
$class_groups = [
    'Seconde'    => ['2nde GT A','2nde GT B','2nde GT C','2nde Pro'],
    'Première'   => ['1ère Générale A','1ère Générale B','1ère STI2D','1ère STMG A','1ère STMG B','1ère Pro SN','1ère Pro MELEC'],
    'Terminale'  => ['Tle Générale A','Tle Générale B','Tle STI2D','Tle STMG A','Tle STMG B','Tle Pro SN','Tle Pro MELEC'],
    'BTS'        => ['BTS S2CIEL A','BTS S2CIEL B','BTS SIO SLAM A','BTS SIO SLAM B','BTS SIO SISR A','BTS SIO SISR B','BTS SNIR A','BTS SNIR B','BTS ELEC A','BTS ELEC B'],
];

include __DIR__ . '/includes/header.php';
?>

<h1>Gestion des comptes</h1>
<p class="subtitle">🔒 Page réservée à l'administrateur</p>

<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<!-- Liste des comptes -->
<h2>Comptes (<?= count($accounts) ?>)</h2>
<div class="table-wrap" style="margin-bottom:32px">
  <table>
    <thead>
      <tr>
        <th>Identifiant</th><th>Nom</th><th>Rôle</th>
        <th>Classes gérées</th><th>Mot de passe capteur</th>
        <th>Statut</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($accounts as $a): ?>
    <tr>
      <td><strong><?= htmlspecialchars($a['username']) ?></strong></td>
      <td><?= htmlspecialchars($a['display_name'] ?? '—') ?></td>
      <td>
        <?= $a['role']==='admin'
            ? '<span class="badge badge-danger">👑 Admin</span>'
            : '<span class="badge badge-blue">👤 CPE</span>' ?>
      </td>
      <td style="font-size:12px;max-width:200px">
        <?php if ($a['classes'] === 'ALL' || $a['role'] === 'admin'): ?>
          <span class="badge badge-ok">Toutes les classes</span>
        <?php else: ?>
          <?php foreach (explode(',', $a['classes'] ?? '') as $c): ?>
            <span class="badge badge-blue" style="margin:2px"><?= htmlspecialchars(trim($c)) ?></span>
          <?php endforeach; ?>
        <?php endif; ?>
      </td>
      <td>
        <code style="background:#1a1a2e;color:#22c55e;padding:3px 8px;border-radius:4px">
          0x<?= htmlspecialchars($a['finger_password'] ?? '—') ?>
        </code>
      </td>
      <td>
        <?= $a['active']
            ? '<span class="badge badge-ok">✅ Actif</span>'
            : '<span class="badge badge-danger">❌ Désactivé</span>' ?>
      </td>
      <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button class="btn btn-blue btn-sm"
                  onclick="openPwd(<?= $a['id'] ?>, '<?= htmlspecialchars($a['username']) ?>')">
            🔑 MDP
          </button>
          <?php if ($a['id'] !== (int)currentAccountId()): ?>
          <form method="POST" style="display:inline">
            <input type="hidden" name="account_id" value="<?= $a['id'] ?>">
            <button type="submit" name="action_toggle"
                    class="btn btn-sm <?= $a['active'] ? 'btn-orange':'btn-green' ?>">
              <?= $a['active'] ? '⏸':'▶' ?>
            </button>
          </form>
          <form method="POST" style="display:inline">
            <input type="hidden" name="account_id" value="<?= $a['id'] ?>">
            <button type="submit" name="action_delete" class="btn btn-red btn-sm"
                    onclick="return confirm('Supprimer ce compte ?')">🗑</button>
          </form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Créer un compte -->
<h2>Créer un compte CPE</h2>
<div class="form-card" style="max-width:680px">
  <form method="POST">
    <div class="form-row">
      <div>
        <label>Identifiant *</label>
        <input type="text" name="username" placeholder="Ex: cpe_zone2" required>
      </div>
      <div>
        <label>Mot de passe *</label>
        <input type="password" name="password" placeholder="••••••••" required>
      </div>
    </div>
    <div class="form-row">
      <div>
        <label>Nom affiché</label>
        <input type="text" name="display_name" placeholder="Ex: M. Dupont — CPE">
      </div>
      <div>
        <label>Rôle</label>
        <select name="role" id="role-select" onchange="toggleClasses(this.value)">
          <option value="cpe">👤 CPE</option>
          <option value="admin">👑 Admin</option>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div>
        <label>Zone terminal</label>
        <select name="zone">
          <option value="GATE_1">GATE_1 — Entrée principale</option>
          <option value="GATE_2">GATE_2 — Entrée secondaire</option>
          <option value="ALL">ALL — Toutes zones</option>
        </select>
      </div>
      <div>
        <label>Mot de passe capteur ESP32 (hex)</label>
        <input type="text" name="finger_password" value="4A4A3202"
               placeholder="Ex: 4A4A3202"
               title="32 bits hex — unique par CPE">
      </div>
    </div>

    <!-- Sélection des classes -->
    <div id="classes-section">
      <label style="margin-bottom:10px;display:block">
        Classes gérées par ce CPE *
        <span style="font-size:12px;color:#888;font-weight:normal">
          (cochez toutes les classes dont ce CPE est responsable)
        </span>
      </label>
      <?php foreach ($class_groups as $group => $classes): ?>
      <div style="margin-bottom:14px">
        <div style="font-size:12px;font-weight:bold;color:#555;
                    margin-bottom:6px;text-transform:uppercase;letter-spacing:1px">
          <?= $group ?>
          <button type="button" onclick="toggleGroup('<?= $group ?>')"
                  style="background:none;border:1px solid #ddd;border-radius:4px;
                         padding:2px 8px;cursor:pointer;font-size:11px;margin-left:6px">
            Tout cocher
          </button>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:8px" id="group-<?= $group ?>">
          <?php foreach ($classes as $c): ?>
          <label style="display:flex;align-items:center;gap:6px;
                        background:#f8f9fa;padding:6px 12px;border-radius:6px;
                        cursor:pointer;font-size:13px;border:1px solid #eee;
                        transition:.15s" class="class-label-<?= $group ?>">
            <input type="checkbox" name="classes[]" value="<?= htmlspecialchars($c) ?>">
            <?= htmlspecialchars($c) ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <button type="submit" name="action_create" class="btn btn-primary btn-full"
            style="margin-top:16px">
      ➕ Créer le compte
    </button>
  </form>
</div>

<!-- Modal changement mot de passe -->
<div class="modal-overlay" id="pwd-modal">
  <div class="modal-box">
    <h3>🔑 Changer le mot de passe</h3>
    <p style="font-size:13px;color:#666;margin-bottom:16px" id="pwd-username"></p>
    <form method="POST">
      <input type="hidden" name="account_id" id="pwd-id">
      <div class="form-group">
        <label>Nouveau mot de passe</label>
        <input type="password" name="new_password" placeholder="••••••••" required autofocus>
      </div>
      <div style="display:flex;gap:8px;margin-top:8px">
        <button type="submit" name="action_pwd" class="btn btn-primary btn-full">
          💾 Enregistrer
        </button>
        <button type="button" class="btn btn-gray"
                onclick="document.getElementById('pwd-modal').classList.remove('open')">
          Annuler
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openPwd(id, username) {
  document.getElementById('pwd-id').value = id;
  document.getElementById('pwd-username').textContent = 'Compte : ' + username;
  document.getElementById('pwd-modal').classList.add('open');
}

function toggleClasses(role) {
  document.getElementById('classes-section').style.display =
    role === 'admin' ? 'none' : 'block';
}

function toggleGroup(group) {
  const checkboxes = document.querySelectorAll(
    '#group-' + group + ' input[type=checkbox]'
  );
  const allChecked = [...checkboxes].every(c => c.checked);
  checkboxes.forEach(c => c.checked = !allChecked);
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
