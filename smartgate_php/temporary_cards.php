<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
$db = getDB();
$error = $success = '';

// ── Alertes élèves entrés sans badge (dernières 24h) ────────
// Source : Walid (tablet) → SG_SYSTEM_EVENTS event_type='ACCES_SANS_BADGE'
$alerts_sans_badge = $db->query("
    SELECT se.uid_badge, se.description, se.extra_data, se.timestamp,
           CONCAT(u.prenom, ' ', u.nom) AS nom_eleve,
           u.classe,
           u.id AS user_id
    FROM SG_SYSTEM_EVENTS se
    LEFT JOIN UTILISATEURS u ON UPPER(u.uid_badge) = UPPER(se.uid_badge)
    WHERE se.event_type = 'ACCES_SANS_BADGE'
      AND se.timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ORDER BY se.timestamp DESC
")->fetchAll();

// ── Créer carte temporaire ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create'])) {
    $user_id    = (int)$_POST['user_id'];
    $name       = trim($_POST['student_name'] ?? '');
    $uid        = strtoupper(trim($_POST['uid'] ?? ''));
    $expiration = $_POST['expiration'] ?? '';

    if (!$user_id || !$name || !$uid || !$expiration) {
        $error = "❌ Tous les champs sont obligatoires.";
    } else {
        $u = $db->prepare("SELECT * FROM UTILISATEURS WHERE id=?");
        $u->execute([$user_id]); $user = $u->fetch();

        if ($user && $user['renewal_count'] >= MAX_RENEWALS) {
            $error = "❌ Limite de " . MAX_RENEWALS . " renouvellements atteinte.";
        } elseif ($user && strtoupper($user['uid_badge'] ?? '') === $uid) {
            $error = "❌ Le badge temporaire doit être différent du badge principal.";
        } else {
            // Vérifier que le badge n'appartient pas à un élève dans UTILISATEURS
            $check = $db->prepare("SELECT CONCAT(prenom,' ',nom) AS name FROM UTILISATEURS WHERE UPPER(uid_badge)=?");
            $check->execute([$uid]); $owner = $check->fetch();
            if ($owner) {
                $error = "❌ Ce badge appartient à : {$owner['name']}.";
            } else {
                // Vérifier pas de carte temporaire active avec ce badge
                $check2 = $db->prepare("SELECT student_name FROM SG_TEMPORARY_CARDS WHERE UPPER(temporary_uid)=? AND expiration_time > NOW()");
                $check2->execute([$uid]); $existing = $check2->fetch();
                if ($existing) {
                    $error = "❌ Ce badge est déjà utilisé par : {$existing['student_name']}.";
                } else {
                    try {
                        $uid_badge = $user['uid_badge'] ?? '';
                        $db->prepare("INSERT INTO SG_TEMPORARY_CARDS (uid_badge, student_name, temporary_uid, expiration_time) VALUES (?,?,?,?)")
                           ->execute([$uid_badge, $name, $uid, $expiration]);
                        $db->prepare("UPDATE UTILISATEURS SET rfid_blocked=1, renewal_count=renewal_count+1 WHERE id=?")
                           ->execute([$user_id]);
                        $db->prepare("INSERT INTO SG_SYSTEM_EVENTS (event_type, description, uid_badge, extra_data) VALUES ('TEMP_CARD_CREATED',?,?,?)")
                           ->execute(["Carte temporaire créée pour $name", $uid_badge, "UID=$uid, expire=$expiration"]);
                        $success = "✅ Carte temporaire créée.";
                    } catch (PDOException $e) {
                        $error = "❌ " . $e->getMessage();
                    }
                }
            }
        }
    }
}

// ── Renouveler carte ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_renew'])) {
    $user_id    = (int)$_POST['user_id'];
    $name       = trim($_POST['student_name'] ?? '');
    $expiration = $_POST['expiration'] ?? '';

    if (!$user_id || !$expiration) {
        $error = "❌ La date d'expiration est obligatoire.";
    } else {
        $u = $db->prepare("SELECT * FROM UTILISATEURS WHERE id=?");
        $u->execute([$user_id]); $user = $u->fetch();

        if ($user && $user['renewal_count'] >= MAX_RENEWALS) {
            $error = "❌ Limite de " . MAX_RENEWALS . " renouvellements atteinte.";
        } else {
            $uid_badge = $user['uid_badge'] ?? '';
            // Dernière carte par uid_badge (remplace user_id FK)
            $card = $db->prepare("SELECT * FROM SG_TEMPORARY_CARDS WHERE uid_badge=? ORDER BY expiration_time DESC LIMIT 1");
            $card->execute([$uid_badge]); $c = $card->fetch();
            if (!$c) {
                $error = "❌ Aucune carte temporaire trouvée.";
            } else {
                $db->prepare("UPDATE SG_TEMPORARY_CARDS SET expiration_time=? WHERE id=?")
                   ->execute([$expiration, $c['id']]);
                $db->prepare("UPDATE UTILISATEURS SET renewal_count=renewal_count+1 WHERE id=?")
                   ->execute([$user_id]);
                $newCount = $user['renewal_count'] + 1;
                if ($newCount >= MAX_RENEWALS) {
                    $db->prepare("UPDATE UTILISATEURS SET rfid_blocked=2 WHERE id=?")
                       ->execute([$user_id]);
                    $db->prepare("INSERT INTO SG_SYSTEM_EVENTS (event_type, description, uid_badge) VALUES ('RFID_BLOCKED_PERMANENT',?,?)")
                       ->execute(["RFID bloqué définitivement : $name", $uid_badge]);
                }
                $db->prepare("INSERT INTO SG_SYSTEM_EVENTS (event_type, description, uid_badge, extra_data) VALUES ('TEMP_CARD_RENEWED',?,?,?)")
                   ->execute(["Carte renouvelée pour $name ($newCount/" . MAX_RENEWALS . ")", $uid_badge, "UID={$c['temporary_uid']}, expire=$expiration"]);
                $success = "✅ Carte renouvelée.";
            }
        }
    }
}

// ── Supprimer carte ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete'])) {
    $card_id = (int)$_POST['card_id'];
    $card = $db->prepare("SELECT * FROM SG_TEMPORARY_CARDS WHERE id=?");
    $card->execute([$card_id]); $c = $card->fetch();
    if ($c) {
        $db->prepare("DELETE FROM SG_TEMPORARY_CARDS WHERE id=?")->execute([$card_id]);
        $db->prepare("INSERT INTO SG_SYSTEM_EVENTS (event_type, description, uid_badge, extra_data) VALUES ('TEMP_CARD_DELETED',?,?,?)")
           ->execute(["Carte supprimée pour {$c['student_name']} — RFID reste bloqué", $c['uid_badge'], "UID={$c['temporary_uid']}"]);
        $success = "✅ Carte supprimée.";
    }
}

// ── Liste cartes — remplace la vue v_temporary_cards ─────────
// SG_TEMPORARY_CARDS n'a plus de user_id — jointure via uid_badge
$cards = $db->query("
    SELECT
        tc.id,
        tc.uid_badge,
        tc.student_name,
        tc.temporary_uid,
        tc.expiration_time,
        IF(tc.expiration_time > NOW(), 'ACTIVE', 'EXPIRED') AS status,
        u.id            AS user_id,
        COALESCE(u.renewal_count, 0) AS renewal_count,
        COALESCE(u.rfid_blocked, 0)  AS rfid_blocked
    FROM SG_TEMPORARY_CARDS tc
    LEFT JOIN UTILISATEURS u ON UPPER(u.uid_badge) = UPPER(tc.uid_badge)
    ORDER BY tc.id DESC
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<h1>Cartes temporaires</h1>

<?php if (!empty($alerts_sans_badge)): ?>
<!-- ══ BARRE DE NOTIFICATION — ÉLÈVES SANS BADGE ══════════════════════════ -->
<div class="notif-bar" id="notif-sans-badge">
  <div class="notif-header">
    <div style="display:flex;align-items:center;gap:8px">
      <span style="font-size:18px">🚨</span>
      <strong>
        <?= count($alerts_sans_badge) ?> élève<?= count($alerts_sans_badge) > 1 ? 's' : '' ?>
        entré<?= count($alerts_sans_badge) > 1 ? 's' : '' ?> sans badge aujourd'hui
      </strong>
      <span style="opacity:.85;font-weight:normal;font-size:13px">— Cliquez sur un nom pour préparer la carte</span>
    </div>
    <button class="notif-close" onclick="document.getElementById('notif-sans-badge').style.display='none'" title="Masquer">✕</button>
  </div>
  <div class="notif-list">
    <?php foreach ($alerts_sans_badge as $a):
      $display = $a['nom_eleve'] ?: ('Badge : ' . ($a['uid_badge'] ?: '?'));
      $searchVal = $a['nom_eleve'] ?: ($a['uid_badge'] ?: '');
    ?>
    <div class="notif-item" onclick="prefillSearch('<?= htmlspecialchars($searchVal, ENT_QUOTES) ?>')" title="Cliquer pour chercher cet élève">
      <span class="notif-avatar">👤</span>
      <span class="notif-name"><?= htmlspecialchars($display) ?></span>
      <?php if ($a['classe']): ?>
        <span class="notif-classe"><?= htmlspecialchars($a['classe']) ?></span>
      <?php endif; ?>
      <?php if ($a['extra_data']): ?>
        <span class="notif-extra" title="Détail">📍 <?= htmlspecialchars($a['extra_data']) ?></span>
      <?php endif; ?>
      <span class="notif-time"><?= date('H\hi', strtotime($a['timestamp'])) ?></span>
      <span class="notif-cta">→ Créer carte</span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<style>
.notif-bar {
  background: linear-gradient(135deg, #fff7ed 0%, #fef3c7 100%);
  border: 2px solid #f59e0b;
  border-radius: 10px;
  margin-bottom: 24px;
  overflow: hidden;
  box-shadow: 0 3px 12px rgba(245,158,11,.2);
}
.notif-header {
  background: #f59e0b;
  color: #fff;
  padding: 10px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 14px;
}
.notif-close {
  background: rgba(255,255,255,.25);
  border: none;
  color: #fff;
  font-size: 15px;
  cursor: pointer;
  border-radius: 4px;
  padding: 2px 8px;
  line-height: 1.5;
  transition: background .15s;
}
.notif-close:hover { background: rgba(255,255,255,.4); }
.notif-list {
  padding: 10px 12px;
  display: flex;
  flex-direction: column;
  gap: 5px;
  max-height: 260px;
  overflow-y: auto;
}
.notif-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
  border-radius: 7px;
  background: #fff;
  border: 1px solid #fde68a;
  cursor: pointer;
  transition: background .15s, border-color .15s;
  font-size: 13px;
}
.notif-item:hover {
  background: #fffbeb;
  border-color: #f59e0b;
}
.notif-avatar { font-size: 16px; flex-shrink: 0; }
.notif-name   { font-weight: bold; color: #1e293b; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.notif-classe { color: #64748b; font-size: 12px; white-space: nowrap; }
.notif-extra  { color: #9ca3af; font-size: 11px; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.notif-time   { color: #9ca3af; font-size: 12px; white-space: nowrap; font-variant-numeric: tabular-nums; }
.notif-cta    { color: #d97706; font-weight: bold; font-size: 12px; white-space: nowrap; flex-shrink: 0; }
</style>
<?php endif; ?>
<!-- ══════════════════════════════════════════════════════════ -->

<?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

<h2>Cartes actives (<?= count($cards) ?>)</h2>
<div class="table-wrap">
  <table>
    <thead><tr><th>Élève</th><th>UID temporaire</th><th>Statut</th><th>Expiration</th><th>Renouvellements</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($cards as $c): ?>
    <tr>
      <td><strong><?= htmlspecialchars($c['student_name']) ?></strong></td>
      <td><code><?= htmlspecialchars($c['temporary_uid']) ?></code></td>
      <td>
        <?php if ($c['status'] === 'ACTIVE'): ?>
          <span class="badge badge-ok">✅ Active</span>
        <?php else: ?>
          <span class="badge badge-danger">⏰ Expirée</span>
        <?php endif; ?>
      </td>
      <td style="font-size:13px"><?= $c['expiration_time'] ?></td>
      <td style="text-align:center;color:<?= $c['renewal_count'] >= MAX_RENEWALS ? '#dc2626' : '#888' ?>">
        <?= $c['renewal_count'] ?>/<?= MAX_RENEWALS ?>
      </td>
      <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <?php if ($c['user_id'] && $c['renewal_count'] < MAX_RENEWALS): ?>
          <button class="btn btn-orange btn-sm"
            data-uid="<?= $c['user_id'] ?>"
            data-name="<?= htmlspecialchars($c['student_name']) ?>"
            data-renewals="<?= $c['renewal_count'] ?>"
            onclick="openRenew(this)">🔄 Renouveler</button>
          <?php endif; ?>
          <form method="POST" style="display:inline">
            <input type="hidden" name="card_id" value="<?= $c['id'] ?>">
            <button type="submit" name="action_delete" class="btn btn-red btn-sm"
                    onclick="return confirm('Supprimer cette carte ?')">🗑</button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($cards)): ?>
    <tr><td colspan="6" style="text-align:center;color:#aaa;padding:20px">Aucune carte temporaire</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Créer carte temporaire -->
<h2>Créer une carte temporaire</h2>
<p style="color:#666;font-size:14px;margin-bottom:16px">
  ℹ️ Recherchez l'élève → sélectionnez → scannez le badge temporaire → validez.
  La carte principale sera <strong>bloquée automatiquement</strong>.
</p>

<div class="form-card">
  <!-- Étape 1 : Recherche -->
  <div id="step1">
    <div style="font-weight:bold;font-size:15px;padding-bottom:10px;border-bottom:2px solid #f0f0f0;margin-bottom:16px">
      ① Identifier l'élève
    </div>
    <div class="form-group">
      <label>Rechercher par nom ou RFID</label>
      <input type="text" id="search-input" placeholder="Ex: Dupont, Lucas, ABC123…"
             oninput="searchStudent(this.value)">
    </div>
    <div id="results"></div>
  </div>

  <!-- Étape 2 : Scanner + créer -->
  <div id="step2" style="display:none">
    <div id="sel-box" style="background:#f0fdf4;border:2px solid #22c55e;border-radius:8px;
         padding:14px 16px;margin-bottom:20px;display:flex;align-items:center;gap:14px">
      <div style="flex:1">
        <div style="font-weight:bold;font-size:16px" id="sel-name"></div>
        <div style="color:#888;font-size:13px" id="sel-class"></div>
        <div id="sel-warns" style="font-size:12px;margin-top:4px"></div>
      </div>
      <button type="button" onclick="resetStep1()"
              class="btn btn-gray btn-sm">✕ Changer</button>
    </div>

    <div style="font-weight:bold;font-size:15px;padding-bottom:10px;border-bottom:2px solid #f0f0f0;margin-bottom:16px">
      ② Scanner le badge temporaire
    </div>

    <form method="POST">
      <input type="hidden" name="user_id"      id="h-uid">
      <input type="hidden" name="student_name" id="h-name">

      <div class="form-group">
        <label>Badge temporaire</label>
        <div class="scan-row">
          <input type="text" id="uid-input" name="uid" placeholder="Approchez le badge…" readonly required>
          <button type="button" class="btn btn-scan" id="btn-scan" onclick="scanRFID()">📡 Scanner</button>
        </div>
        <div id="scan-status"></div>
      </div>

      <div class="form-group">
        <label>Date d'expiration</label>
        <input type="datetime-local" name="expiration" required>
      </div>

      <button type="submit" name="action_create" class="btn btn-primary btn-full" id="btn-submit" disabled>
        🔒 Créer la carte et bloquer la principale
      </button>
    </form>
  </div>
</div>

<!-- Modal renouvellement -->
<div class="modal-overlay" id="renew-modal">
  <div class="modal-box">
    <h3>🔄 Renouveler la carte temporaire</h3>
    <p style="font-size:13px;color:#666;margin-bottom:8px">
      La <strong>même carte</strong> sera prolongée avec une nouvelle date.
    </p>
    <div id="renew-warn" style="margin-bottom:16px"></div>
    <form method="POST" id="renew-form">
      <input type="hidden" name="user_id"      id="renew-uid">
      <input type="hidden" name="student_name" id="renew-name">
      <div class="form-group">
        <label>Élève</label>
        <input type="text" id="renew-name-display" readonly>
      </div>
      <div class="form-group">
        <label>Nouvelle date d'expiration</label>
        <input type="datetime-local" name="expiration" required>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" name="action_renew" class="btn btn-orange btn-full">🔄 Renouveler</button>
        <button type="button" class="btn btn-gray" onclick="closeRenew()">Annuler</button>
      </div>
    </form>
  </div>
</div>

<script>
let searchTimer = null;
window._results = [];

// Pré-remplit la recherche depuis la barre de notification
function prefillSearch(name) {
  const inp = document.getElementById('search-input');
  inp.value = name;
  inp.dispatchEvent(new Event('input'));
  inp.focus();
  inp.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function searchStudent(q) {
  clearTimeout(searchTimer);
  const box = document.getElementById('results');
  if (q.length < 2) { box.innerHTML = ''; return; }
  searchTimer = setTimeout(async () => {
    const users = await (await fetch('/smartgate/api/search_user.php?q=' + encodeURIComponent(q))).json();
    if (!users.length) { box.innerHTML = '<p style="color:#aaa;font-size:13px;margin-top:8px">Aucun élève trouvé.</p>'; return; }
    window._results = users;
    box.innerHTML = users.map((u,i) => {
      const photoSrc = u.photo_filename
        ? `/smartgate/assets/photos/${encodeURIComponent(u.photo_filename)}`
        : null;
      const avatar = photoSrc
        ? `<img src="${photoSrc}" alt="" style="width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid #e5e7eb;flex-shrink:0"
               onerror="this.replaceWith(Object.assign(document.createElement('div'),{textContent:'👤',style:'width:42px;height:42px;border-radius:50%;background:#f0f0f0;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0'}))">`
        : `<div style="width:42px;height:42px;border-radius:50%;background:#f0f0f0;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">👤</div>`;
      return `
      <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:#f8f9fa;
           border-radius:6px;margin-bottom:6px;cursor:pointer;border:2px solid transparent;transition:.15s"
           onclick="selectUser(${i})"
           onmouseover="this.style.borderColor='#3b82f6'"
           onmouseout="this.style.borderColor='transparent'">
        ${avatar}
        <div>
          <div style="font-weight:bold;font-size:14px">${u.name}</div>
          <div style="font-size:12px;color:#888">${u.student_class||''} — RFID: ${u.rfid_uid||'Aucun'}</div>
          <div style="font-size:11px;color:${u.renewal_count>=3?'#dc2626':'#888'}">
            🔄 ${u.renewal_count||0}/3 renouvellements
            ${u.rfid_blocked?'— 🔒 RFID bloqué':''}
          </div>
        </div>
      </div>`;
    }).join('');
  }, 300);
}

async function selectUser(i) {
  const u = window._results[i];
  document.getElementById('h-uid').value   = u.id;
  document.getElementById('h-name').value  = u.name;
  document.getElementById('sel-name').textContent  = u.name;
  document.getElementById('sel-class').textContent = u.student_class||'';

  const warns = [];
  if (u.rfid_blocked == 1) warns.push('<div style="color:#d97706">⚠️ RFID déjà bloqué (carte temp. active)</div>');
  if (u.renewal_count >= 3) warns.push('<div style="color:#dc2626;font-weight:bold">🚫 Limite atteinte</div>');
  else warns.push('<div style="color:#d97706">🔄 ' + u.renewal_count + '/3 renouvellements utilisés</div>');

  document.getElementById('sel-warns').innerHTML = warns.join('');
  document.getElementById('step1').style.display = 'none';
  document.getElementById('step2').style.display = 'block';
}

function resetStep1() {
  document.getElementById('step1').style.display = 'block';
  document.getElementById('step2').style.display = 'none';
  document.getElementById('search-input').value  = '';
  document.getElementById('results').innerHTML   = '';
  document.getElementById('uid-input').value     = '';
  document.getElementById('btn-submit').disabled = true;
}

function setScanStatus(msg, type) {
  const el = document.getElementById('scan-status');
  el.className = type; el.textContent = msg; el.style.display = 'block';
}

async function scanRFID() {
  const inp = document.getElementById('uid-input');
  const btn = document.getElementById('btn-scan');
  btn.disabled = true; inp.value = '';
  document.getElementById('btn-submit').disabled = true;
  await fetch('/smartgate/api/proxy.php?path=/api/clear_rfid');
  setScanStatus('⏳ Approchez le badge temporaire…', 'info');
  let tries = 0;
  const poll = setInterval(async () => {
    tries++;
    const data = await (await fetch('/smartgate/api/proxy.php?path=/api/last_rfid')).json();
    if (data.rfid != null) {
      clearInterval(poll); inp.value = data.rfid; btn.disabled = false;
      document.getElementById('btn-submit').disabled = false;
      setScanStatus('✅ Badge : ' + data.rfid, 'success'); return;
    }
    if (tries >= 15) { clearInterval(poll); btn.disabled = false; setScanStatus('⏱️ Temps écoulé.', 'error'); }
  }, 1500);
}

// Modal renouvellement
function openRenew(btn) {
  document.getElementById('renew-uid').value          = btn.dataset.uid;
  document.getElementById('renew-name').value         = btn.dataset.name;
  document.getElementById('renew-name-display').value = btn.dataset.name;
  const left = <?= MAX_RENEWALS ?> - parseInt(btn.dataset.renewals);
  const warn = document.getElementById('renew-warn');
  warn.innerHTML = left <= 1
    ? '<div class="alert alert-warning">⚠️ Dernier renouvellement ! Après ceci, le RFID sera <strong>bloqué définitivement</strong>.</div>'
    : '<div class="alert alert-info">🔄 ' + btn.dataset.renewals + '/<?= MAX_RENEWALS ?> renouvellements utilisés. Il en reste ' + left + '.</div>';
  document.getElementById('renew-modal').classList.add('open');
}
function closeRenew() {
  document.getElementById('renew-modal').classList.remove('open');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
