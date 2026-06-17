<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>SmartGate V4 — Terminal</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:Arial,sans-serif;background:#050505;color:white;
       display:flex;flex-direction:column;align-items:center;
       justify-content:center;min-height:100vh;text-align:center;user-select:none;}
  header{position:fixed;top:0;width:100%;display:flex;justify-content:space-between;
         align-items:center;padding:14px 28px;background:#0a0a0a;
         border-bottom:1px solid #1a1a1a;}
  .logo{font-size:14px;color:#444;letter-spacing:3px;text-transform:uppercase;}
  #clock{font-size:13px;color:#333;letter-spacing:2px;}
  #card{background:#111;border-radius:24px;padding:52px 72px;min-width:300px;
        border:3px solid #1a1a1a;transition:border-color .4s,box-shadow .4s;}
  #card.authorized{border-color:#22c55e;box-shadow:0 0 60px rgba(34,197,94,.2);}
  #card.denied{border-color:#ef4444;box-shadow:0 0 60px rgba(239,68,68,.2);}
  #photo{width:160px;height:160px;border-radius:50%;object-fit:cover;
         border:4px solid #222;margin-bottom:28px;display:none;}
  #avatar{width:160px;height:160px;border-radius:50%;background:#1a1a1a;
          font-size:80px;display:flex;align-items:center;justify-content:center;
          margin:0 auto 28px;}
  #status-text{font-size:30px;font-weight:bold;letter-spacing:3px;}
  #status-text.authorized{color:#22c55e;}
  #status-text.denied{color:#ef4444;}
  #bar-wrap{width:100%;height:5px;background:#1a1a1a;border-radius:4px;
            margin-top:28px;overflow:hidden;display:none;}
  #bar{height:100%;width:100%;}
  #waiting{font-size:15px;color:#2a2a2a;padding:16px 0;letter-spacing:1px;}
</style>
</head>
<body>
<header>
  <div class="logo">⬛ SmartGate V4 — Zone 1 — Lycée Jean Jaurès</div>
  <div id="clock"></div>
</header>

<div id="card">
  <div id="waiting">En attente d'identification…</div>
  <div id="result" style="display:none">
    <img id="photo" src="" alt="">
    <div id="avatar" style="display:none">👤</div>
    <div id="status-text"></div>
    <div id="bar-wrap"><div id="bar"></div></div>
  </div>
</div>

<script>
const DISPLAY_SEC = 5;
let hideTimeout = null;
let lastShownTs = null;

function tick() {
  const n = new Date();
  document.getElementById('clock').textContent =
    n.toLocaleDateString('fr-FR',{weekday:'long',day:'2-digit',month:'long',year:'numeric'})
    + '  —  ' + n.toLocaleTimeString('fr-FR');
}
setInterval(tick, 1000); tick();

async function poll() {
  try {
    // Le terminal appelle directement l'API Flask port 5000
    const data = await (await fetch('/smartgate/api/proxy.php?path=/api/last_access')).json();
    if (!data || !data.status) return;
    if (data.ts === lastShownTs) return;
    lastShownTs = data.ts;

    const card    = document.getElementById('card');
    const waiting = document.getElementById('waiting');
    const result  = document.getElementById('result');
    const photo   = document.getElementById('photo');
    const avatar  = document.getElementById('avatar');
    const statusEl= document.getElementById('status-text');
    const barWrap = document.getElementById('bar-wrap');
    const bar     = document.getElementById('bar');

    waiting.style.display = 'none';
    result.style.display  = 'block';

    if (data.status === 'AUTHORIZED') {
      card.className = 'authorized';
      statusEl.className = 'authorized';
      statusEl.textContent = '✅  ACCÈS AUTORISÉ';
      bar.style.background = '#22c55e';
      if (data.photo) {
        photo.src = '/smartgate/assets/photos/' + data.photo;
        photo.style.display = 'block'; avatar.style.display = 'none';
        photo.onerror = () => { photo.style.display='none'; avatar.style.display='flex'; avatar.textContent='👤'; };
      } else {
        photo.style.display = 'none'; avatar.style.display = 'flex'; avatar.textContent = '👤';
      }
    } else {
      card.className = 'denied';
      statusEl.className = 'denied';
      statusEl.textContent = '❌  ACCÈS REFUSÉ';
      bar.style.background = '#ef4444';
      photo.style.display = 'none'; avatar.style.display = 'flex'; avatar.textContent = '⛔';
    }

    barWrap.style.display = 'block';
    bar.style.transition  = 'none'; bar.style.width = '100%';
    requestAnimationFrame(() => { bar.style.transition = `width ${DISPLAY_SEC}s linear`; bar.style.width = '0%'; });

    clearTimeout(hideTimeout);
    hideTimeout = setTimeout(() => {
      card.className = ''; waiting.style.display = 'block';
      result.style.display = 'none'; barWrap.style.display = 'none';
      photo.style.display = 'none';
    }, DISPLAY_SEC * 1000);
  } catch(e) {}
}
poll();
setInterval(poll, 1500);
</script>
</body>
</html>
