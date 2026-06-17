<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

// Export CSV
if (isset($_GET['export'])) {
    $db     = getDB();
    $from   = $_GET['from']   ?? date('Y-m-d');
    $to     = $_GET['to']     ?? date('Y-m-d');
    $status = $_GET['status'] ?? null;
    $method = $_GET['method'] ?? null;
    $where  = ["DATE(timestamp) >= ?","DATE(timestamp) <= ?"];
    $params = [$from, $to];
    if ($status) { $where[] = "access_status = ?";        $params[] = $status; }
    if ($method) { $where[] = "authentication_method = ?"; $params[] = $method; }
    $sql  = "SELECT * FROM v_access_logs WHERE " . implode(" AND ", $where);
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $logs = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="smartgate_logs_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8
    echo "Élève;Méthode;Statut;Note;Date\n";
    foreach ($logs as $l) {
        echo implode(";", [
            $l['student_name'], $l['authentication_method'],
            $l['access_status'], $l['note'] ?? '',
            date('d/m/Y H:i:s', strtotime($l['timestamp']))
        ]) . "\n";
    }
    exit;
}

include __DIR__ . '/includes/header.php';
$today = date('Y-m-d');
?>

<h1>Historique</h1>

<!-- Onglets -->
<div class="tabs">
  <button class="tab-btn active" onclick="switchTab('access',this)">🪪 Accès entrée</button>
  <button class="tab-btn" onclick="switchTab('events',this)">⚙️ Événements système</button>
</div>

<!-- ═══ Onglet Accès ═══ -->
<div id="tab-access" class="tab-pane active">
  <div class="toolbar">
    <input type="date" id="f-from" value="<?= $today ?>">
    <input type="date" id="f-to"   value="<?= $today ?>">
    <select id="f-status">
      <option value="">Tous les statuts</option>
      <option value="AUTHORIZED">✅ Autorisé</option>
      <option value="DENIED">❌ Refusé</option>
    </select>
    <select id="f-method">
      <option value="">Toutes méthodes</option>
      <option value="rfid">🪪 RFID</option>
      <option value="fingerprint">🔏 Empreinte</option>
    </select>
    <input type="text" id="f-name" placeholder="🔍 Nom…" style="flex:1;min-width:120px">
    <button class="btn btn-blue" onclick="applyFilters()">Filtrer</button>
    <button class="btn btn-green" onclick="exportCSV()">⬇ CSV</button>
  </div>
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
    <strong>Résultats</strong>
    <span class="live-badge" id="live-access">⬤ EN DIRECT</span>
    <span id="count-access" style="color:#888;font-size:13px"></span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Élève</th><th>Méthode</th><th>Statut</th><th>Note</th><th>Date / Heure</th></tr></thead>
      <tbody id="tbody-access"></tbody>
    </table>
  </div>
</div>

<!-- ═══ Onglet Événements ═══ -->
<div id="tab-events" class="tab-pane">
  <div class="toolbar">
    <input type="date" id="ev-from">
    <input type="date" id="ev-to">
    <input type="text" id="ev-search" placeholder="🔍 Rechercher…" style="flex:1">
    <button class="btn btn-blue" onclick="loadEvents()">Filtrer</button>
  </div>
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
    <strong>Événements</strong>
    <span class="live-badge" id="live-events">⬤ EN DIRECT</span>
    <span id="count-events" style="color:#888;font-size:13px"></span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Type</th><th>Description</th><th>Détail</th><th>Date / Heure</th></tr></thead>
      <tbody id="tbody-events"></tbody>
    </table>
  </div>
</div>

<style>
.ev{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:bold;}
.ev-USER_ADDED{background:#dcfce7;color:#166534;}
.ev-USER_DELETED{background:#fee2e2;color:#991b1b;}
.ev-USER_UPDATED{background:#dbeafe;color:#1e40af;}
.ev-TEMP_CARD_CREATED{background:#fef9c3;color:#854d0e;}
.ev-TEMP_CARD_RENEWED{background:#ffedd5;color:#9a3412;}
.ev-TEMP_CARD_DELETED{background:#f3e8ff;color:#6b21a8;}
.ev-TEMP_CARD_EXPIRED{background:#fee2e2;color:#991b1b;}
.ev-RFID_AUTO_UNBLOCKED{background:#d1fae5;color:#065f46;}
.ev-RFID_UNBLOCKED_MANUAL{background:#d1fae5;color:#065f46;}
.ev-RFID_BLOCKED_PERMANENT{background:#1f2937;color:#f9fafb;}
</style>

<script>
const METHOD = {rfid:'🪪 RFID', fingerprint:'🔏 Empreinte'};
const EV_LABELS = {
  USER_ADDED:'👤 Élève ajouté', USER_DELETED:'🗑 Élève supprimé', USER_UPDATED:'✏️ Élève modifié',
  TEMP_CARD_CREATED:'🪪 Carte temp. créée', TEMP_CARD_RENEWED:'🔄 Carte temp. renouvelée',
  TEMP_CARD_DELETED:'🗑 Carte temp. supprimée', TEMP_CARD_EXPIRED:'⏰ Carte temp. expirée',
  RFID_UNBLOCKED_MANUAL:'🔓 RFID débloqué', RFID_BLOCKED_PERMANENT:'🔒 RFID bloqué définitif',
};
let lastAccessTs = null, autoRefresh = true, currentTab = 'access';

function switchTab(tab, btn) {
  currentTab = tab;
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  if (tab === 'events') loadEvents();
}

function buildQuery() {
  const p = new URLSearchParams();
  const from = document.getElementById('f-from').value;
  const to   = document.getElementById('f-to').value;
  const st   = document.getElementById('f-status').value;
  const me   = document.getElementById('f-method').value;
  if(from) p.set('from',from); if(to) p.set('to',to);
  if(st) p.set('status',st); if(me) p.set('method',me);
  return p.toString();
}

function renderAccess(logs) {
  const name = document.getElementById('f-name').value.toLowerCase();
  const f = logs.filter(l => !name || l.student_name.toLowerCase().includes(name));
  document.getElementById('count-access').textContent = f.length + ' entrée(s)';
  document.getElementById('tbody-access').innerHTML = f.map(l => `
    <tr>
      <td><strong>${l.student_name}</strong></td>
      <td>${METHOD[l.authentication_method]||l.authentication_method}</td>
      <td>${l.access_status==='AUTHORIZED'?'<span class="ok">✅ AUTORISÉ</span>':'<span class="ko">❌ REFUSÉ</span>'}</td>
      <td style="font-size:12px;color:#aaa">${l.note||''}</td>
      <td style="font-size:13px;color:#888">${l.timestamp}</td>
    </tr>
  `).join('') || '<tr><td colspan="5" style="text-align:center;color:#aaa;padding:16px">Aucun résultat</td></tr>';
}

async function applyFilters() {
  autoRefresh = false;
  const logs = await (await fetch('/smartgate/api/logs.php?'+buildQuery())).json();
  renderAccess(logs);
}

function exportCSV() {
  window.location.href = '/smartgate/logs.php?export=1&'+buildQuery();
}

async function refreshAccess() {
  if (!autoRefresh || currentTab !== 'access') return;
  try {
    const today = new Date().toISOString().split('T')[0];
    const logs  = await (await fetch(`/smartgate/api/logs.php?from=${today}&to=${today}`)).json();
    const first = logs.length ? logs[0].timestamp : null;
    if (first !== lastAccessTs) { lastAccessTs = first; renderAccess(logs); }
    document.getElementById('live-access').className   = 'live-badge';
    document.getElementById('live-access').textContent = '⬤ EN DIRECT';
  } catch(e) {
    document.getElementById('live-access').className = 'live-badge off';
  }
}

async function loadEvents() {
  try {
    const p = new URLSearchParams();
    const from = document.getElementById('ev-from').value;
    const to   = document.getElementById('ev-to').value;
    if(from) p.set('from',from); if(to) p.set('to',to);
    const events = await (await fetch('/smartgate/api/events.php?'+p.toString())).json();
    const search = document.getElementById('ev-search').value.toLowerCase();
    const f = events.filter(e => !search || e.description.toLowerCase().includes(search) || e.event_type.toLowerCase().includes(search));
    document.getElementById('count-events').textContent = f.length + ' événement(s)';
    document.getElementById('tbody-events').innerHTML = f.map(e => `
      <tr>
        <td><span class="ev ev-${e.event_type}">${EV_LABELS[e.event_type]||e.event_type}</span></td>
        <td>${e.description||''}</td>
        <td style="font-size:12px;color:#aaa">${e.extra_data||''}</td>
        <td style="font-size:13px;color:#888">${e.timestamp}</td>
      </tr>
    `).join('') || '<tr><td colspan="4" style="text-align:center;color:#aaa;padding:16px">Aucun événement</td></tr>';
    document.getElementById('live-events').className = 'live-badge';
  } catch(e) {
    document.getElementById('live-events').className = 'live-badge off';
  }
}

refreshAccess();
setInterval(refreshAccess, 2000);
setInterval(() => { if(currentTab==='events') loadEvents(); }, 5000);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
