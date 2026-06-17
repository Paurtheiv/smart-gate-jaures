/**
 * SmartGate V4 - Widget caméra tablette
 * À inclure dans users.php et edit_user.php
 * 
 * Usage HTML :
 *   <div id="camera-section"></div>
 *   <input type="hidden" id="photo-captured" name="photo_filename">
 */

// Injecter le widget caméra dans la page
function initCameraWidget(containerId, filenamePrefix) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = `
        <div style="margin-bottom:8px">
            <button type="button" class="btn btn-blue" id="btn-open-camera" onclick="openCamera()">
                📷 Prendre une photo
            </button>
            <span style="color:#888;font-size:12px;margin-left:10px">ou</span>
            <label class="btn btn-gray" style="cursor:pointer;margin-left:6px">
                📁 Choisir un fichier
                <input type="file" id="file-input" accept="image/*"
                       style="display:none" onchange="loadFromFile(this)">
            </label>
        </div>

        <!-- Aperçu photo prise -->
        <div id="photo-preview-box" style="display:none;margin-top:10px">
            <img id="photo-preview-img"
                 style="width:120px;height:120px;object-fit:cover;
                        border-radius:8px;border:3px solid #22c55e">
            <div style="font-size:12px;color:#22c55e;margin-top:4px">✅ Photo prête</div>
            <button type="button" onclick="resetPhoto()"
                    style="background:none;border:none;color:#ef4444;
                           font-size:12px;cursor:pointer;margin-top:4px">
                🗑 Changer
            </button>
        </div>
    `;

    // Modal caméra
    if (!document.getElementById('camera-modal')) {
        document.body.insertAdjacentHTML('beforeend', `
            <div id="camera-modal" class="modal-overlay">
                <div class="modal-box" style="max-width:500px;text-align:center">
                    <h3 style="margin-bottom:16px">📷 Prendre une photo</h3>
                    <video id="camera-stream"
                           style="width:100%;border-radius:8px;background:#000;
                                  max-height:300px;object-fit:cover"
                           autoplay playsinline></video>
                    <canvas id="camera-canvas" style="display:none"></canvas>
                    <div style="margin-top:16px;display:flex;gap:8px;justify-content:center">
                        <button type="button" class="btn btn-green"
                                onclick="capturePhoto()" id="btn-capture">
                            📸 Capturer
                        </button>
                        <button type="button" class="btn btn-orange"
                                onclick="switchCamera()" id="btn-switch">
                            🔄 Caméra
                        </button>
                        <button type="button" class="btn btn-gray"
                                onclick="closeCamera()">
                            ✕ Annuler
                        </button>
                    </div>
                    <div id="camera-error" style="color:#ef4444;font-size:13px;
                         margin-top:10px;display:none"></div>
                </div>
            </div>
        `);
    }

    window._cameraPrefix = filenamePrefix || 'eleve';
}

let _stream       = null;
let _facingMode   = 'user';   // 'user' = caméra avant, 'environment' = arrière

async function openCamera() {
    const modal = document.getElementById('camera-modal');
    const video = document.getElementById('camera-stream');
    const err   = document.getElementById('camera-error');
    err.style.display = 'none';
    modal.classList.add('open');

    try {
        _stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: _facingMode, width: 640, height: 480 },
            audio: false
        });
        video.srcObject = _stream;
    } catch(e) {
        err.style.display = 'block';
        err.textContent   = '❌ Impossible d\'accéder à la caméra : ' + e.message;
    }
}

async function switchCamera() {
    _facingMode = _facingMode === 'user' ? 'environment' : 'user';
    if (_stream) {
        _stream.getTracks().forEach(t => t.stop());
    }
    await openCamera();
}

function closeCamera() {
    if (_stream) _stream.getTracks().forEach(t => t.stop());
    document.getElementById('camera-modal').classList.remove('open');
}

async function capturePhoto() {
    const video  = document.getElementById('camera-stream');
    const canvas = document.getElementById('camera-canvas');
    canvas.width  = video.videoWidth  || 640;
    canvas.height = video.videoHeight || 480;
    canvas.getContext('2d').drawImage(video, 0, 0);

    const imageData = canvas.toDataURL('image/jpeg', 0.85);
    await savePhoto(imageData);
    closeCamera();
}

async function loadFromFile(input) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = async (e) => await savePhoto(e.target.result);
    reader.readAsDataURL(input.files[0]);
}

async function savePhoto(imageData) {
    const ts       = Date.now();
    const filename = (window._cameraPrefix || 'eleve') + '_' + ts + '.jpg';

    try {
        const res  = await fetch('/smartgate/api/capture_photo.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ image: imageData, filename: filename })
        });
        const data = await res.json();

        if (data.ok) {
            // Afficher aperçu
            document.getElementById('photo-preview-img').src = imageData;
            document.getElementById('photo-preview-box').style.display = 'block';
            // Stocker le nom du fichier dans le champ caché
            const hidden = document.getElementById('photo-captured');
            if (hidden) hidden.value = data.filename;
        } else {
            alert('❌ Erreur : ' + data.error);
        }
    } catch(e) {
        alert('❌ Erreur réseau : ' + e.message);
    }
}

function resetPhoto() {
    document.getElementById('photo-preview-box').style.display  = 'none';
    document.getElementById('photo-preview-img').src = '';
    const hidden = document.getElementById('photo-captured');
    if (hidden) hidden.value = '';
}
