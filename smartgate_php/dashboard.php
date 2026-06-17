<?php
// ============================================================
// SmartGate V4 - Dashboard
// ============================================================
require_once __DIR__ . '/includes/auth.php';
requireLogin();
$db = getDB();

// Stats
$stats = $db->query("SELECT * FROM v_stats_today")->fetch();

// Derniers accès (aujourd'hui)
$logs = $db->query("
    SELECT * FROM v_access_logs
    WHERE DATE(timestamp) = CURDATE()
    LIMIT 10
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<h1>SmartGate V4</h1>
<div class="subtitle">Lycée Jean Jaurès — Argenteuil — Zone 1 : Entrée principale</div>

<!-- Stats -->
<div class="stats">
  <div class="stat-card blue">
    <div class="num" id="s-users"><?= $stats['total_users'] ?></div>
    <div class="lbl">Élèves enregistrés</div>
  </div>
  <div class="stat-card green">
    <div class="num" id="s-ok"><?= $stats['today_authorized'] ?></div>
    <div class="lbl">Accès autorisés aujourd'hui</div>
  </div>
  <div class="stat-card red">
    <div class="num" id="s-ko"><?= $stats['today_denied'] ?></div>
    <div class="lbl">Accès refusés aujourd'hui</div>
  </div>
  <div class="stat-card orange">
    <div class="num" id="s-temp"><?= $stats['active_temp_cards'] ?></div>
    <div class="lbl">Cartes temporaires actives</div>
  </div>
</div>

<!-- Derniers accès -->
<h2>
  Derniers accès
  <span class="live-badge" id="live">⬤ EN DIRECT</span>
</h2>
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Élève</th><th>Méthode</th><th>Statut</th>
        <th>Note</th><th>Heure</th>
      </tr>
    </thead>
    <tbody id="tbody">
      <?php foreach ($logs as $l): ?>
      <tr>
        <td><strong><?= htmlspecialchars($l['student_name']) ?></strong></td>
        <td><?= $l['authentication_method'] === 'rfid' ? '🪪 RFID' : '🔏 Empreinte' ?></td>
        <td>
          <?php if ($l['access_status'] === 'AUTHORIZED'): ?>
            <span class="ok">✅ AUTORISÉ</span>
          <?php else: ?>
            <span class="ko">❌ REFUSÉ</span>
          <?php endif; ?>
        </td>
        <td style="font-size:12px;color:#aaa"><?= htmlspecialchars($l['note'] ?? '') ?></td>
        <td style="font-size:12px;color:#888"><?= $l['timestamp'] ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($logs)): ?>
      <tr><td colspan="5" style="text-align:center;color:#aaa;padding:20px">
        Aucun accès aujourd'hui
      </td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
const METHOD = {rfid:'🪪 RFID', fingerprint:'🔏 Empreinte'};
let lastFirstTs = null;

async function refresh() {
  try {
    const today = new Date().toISOString().split('T')[0];
    const [logsR, statsR] = await Promise.all([
      fetch(`/smartgate/api/logs.php?from=${today}&to=${today}`),
      fetch('/smartgate/api/stats.php')
    ]);
    const logs  = await logsR.json();
    const stats = await statsR.json();

    document.getElementById('s-users').textContent = stats.total_users       ?? '—';
    document.getElementById('s-ok').textContent    = stats.today_authorized  ?? '—';
    document.getElementById('s-ko').textContent    = stats.today_denied      ?? '—';
    document.getElementById('s-temp').textContent  = stats.active_temp_cards ?? '—';

    const top = logs.slice(0, 10);
    const firstTs = top.length ? top[0].timestamp : null;
    if (firstTs !== lastFirstTs) {
      lastFirstTs = firstTs;
      document.getElementById('tbody').innerHTML = top.map(l => `
        <tr>
          <td><strong>${l.student_name}</strong></td>
          <td>${METHOD[l.authentication_method] || l.authentication_method}</td>
          <td>${l.access_status === 'AUTHORIZED'
            ? '<span class="ok">✅ AUTORISÉ</span>'
            : '<span class="ko">❌ REFUSÉ</span>'}</td>
          <td style="font-size:12px;color:#aaa">${l.note || ''}</td>
          <td style="font-size:12px;color:#888">${l.timestamp}</td>
        </tr>
      `).join('') || '<tr><td colspan="5" style="text-align:center;color:#aaa;padding:20px">Aucun accès aujourd\'hui</td></tr>';
    }
    document.getElementById('live').className   = 'live-badge';
    document.getElementById('live').textContent = '⬤ EN DIRECT';
  } catch(e) {
    document.getElementById('live').className   = 'live-badge off';
    document.getElementById('live').textContent = '⬤ DÉCONNECTÉ';
  }
}
refresh();
setInterval(refresh, 3000);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
