<?php
require_once 'db.php';
require_once 'auth.php';

if (!isset($_SESSION['user_id']) || (isset($_SESSION['has_pin']) && $_SESSION['has_pin'] === false)) {
    header("Location: index");
    exit;
}

if (isset($_SESSION['app_unlocked']) && $_SESSION['app_unlocked'] === true) {
    header("Location: index");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Locked - Cash Book</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .lock-screen {
            height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: var(--bg-color);
            padding: 2rem;
        }
    .pin-display {
        display: flex;
        justify-content: center;
        gap: 1.25rem;
        margin: 1.5rem 0 2rem;
    }
    .pin-dot {
        width: 14px;
        height: 14px;
        border-radius: 50%;
        border: 2px solid rgba(0, 0, 0, 0.1);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    @media (max-width: 991px) {
        .pin-dot { border-color: rgba(255, 255, 255, 0.3); }
    }
    .pin-dot.filled {
        background: var(--primary);
        border-color: var(--primary);
        box-shadow: 0 0 10px var(--primary);
        transform: scale(1.1);
    }
    .keypad {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1.25rem;
        width: 100%;
        max-width: 280px;
        margin: 0 auto;
    }
    .key {
        aspect-ratio: 1;
        border-radius: 50%;
        border: 1px solid rgba(0, 0, 0, 0.05);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        font-weight: 600;
        cursor: pointer;
        background: rgba(0, 0, 0, 0.02);
        color: var(--text-main);
        transition: all 0.2s ease;
        user-select: none;
    }
    .key:hover {
        background: rgba(0, 0, 0, 0.05);
        border-color: rgba(0, 0, 0, 0.1);
    }
    .key:active {
        background: var(--primary);
        color: white;
        transform: scale(0.92);
        border-color: transparent;
    }
    @media (max-width: 991px) {
        .key {
            border-color: rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            color: white;
        }
        .key:active {
            background: var(--primary);
        }
    }
    .key.action {
        background: transparent;
        border: none;
        color: var(--text-muted);
    }
    @media (max-width: 991px) {
        .key.action { color: rgba(255, 255, 255, 0.6); }
    }    </style>
</head>
<body class="auth-body">
    <?php include 'header.php'; ?>

    <div class="auth-page">
        <!-- Visual Side (Desktop only) -->
        <div class="auth-visual">
            <div class="auth-visual-content">
                <h1>Secure Your Privacy.</h1>
                <p>Your financial data is protected with 256-bit encryption and secure PIN access.</p>
            </div>
        </div>

        <!-- Form Side -->
        <div class="auth-form-side">
            <div class="auth-container">
                <div style="text-align: center; margin-bottom: 2rem;">
                    <div style="width: 60px; height: 60px; background: rgba(99, 102, 241, 0.15); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1rem; border: 1px solid rgba(255, 255, 255, 0.1);">
                        <i class="fa-solid fa-lock" style="font-size: 1.5rem; color: var(--primary);"></i>
                    </div>
                    <h2 style="font-weight: 700; font-size: 1.5rem; margin-bottom: 0.5rem;">Enter PIN</h2>
                    <p id="msg" style="color: var(--text-muted); font-size: 0.875rem;">Your account is locked</p>
                </div>

                <div class="pin-display">
                    <div class="pin-dot"></div>
                    <div class="pin-dot"></div>
                    <div class="pin-dot"></div>
                    <div class="pin-dot"></div>
                </div>

                <div id="faceLink" style="display: <?= $_SESSION['has_bio'] ? 'block' : 'none' ?>; text-align: center; margin-bottom: 2rem; color: var(--primary); font-weight: 600; cursor: pointer; font-size: 0.9rem;" onclick="biometricUnlock()">
                    <i class="fa-solid fa-face-smile"></i> Try Face Unlock
                </div>

                <div class="keypad">
                    <?php for($i=1; $i<=9; $i++): ?>
                        <div class="key" onclick="press(<?= $i ?>)"><?= $i ?></div>
                    <?php endfor; ?>
                    <div class="key action" id="bioBtn" style="display: <?= $_SESSION['has_bio'] ? 'flex' : 'none' ?>;" onclick="biometricUnlock()"><i class="fa-solid fa-fingerprint"></i></div>
                    <div class="key" onclick="press(0)">0</div>
                    <div class="key action" onclick="backspace()"><i class="fa-solid fa-backspace"></i></div>
                </div>

                <div style="display: flex; flex-direction: column; align-items: center; gap: 1.5rem; margin-top: 2rem;">
                    <div id="forgotPin" style="color: var(--primary); font-size: 0.875rem; font-weight: 600; cursor: pointer;" onclick="recoverPin()">
                        Forgot PIN?
                    </div>

                    <a href="logout" style="color: var(--danger); text-decoration: none; font-size: 0.875rem; font-weight: 600; opacity: 0.8;">
                        <i class="fa-solid fa-sign-out-alt"></i> Switch User / Logout
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Recovery Overlay -->
    <div id="recoveryOverlay" class="auth-container" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 3000; align-items: center; justify-content: center; padding: 2rem; backdrop-filter: blur(5px);">
        <div class="stat-card" style="width: 100%; max-width: 400px; padding: 2rem;">
            <h3 style="margin-bottom: 0.5rem;"><i class="fa-solid fa-key" style="color: var(--primary);"></i> Reset PIN</h3>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1.5rem;">Enter your account password to verify your identity and disable PIN security.</p>
            
            <div class="form-group">
                <label class="form-label">Account Password</label>
                <div style="position: relative;">
                    <input type="password" id="recoveryPassword" class="form-control" placeholder="Enter password" style="padding-right: 40px;">
                    <i class="fa-regular fa-eye" id="toggleRecoveryPassword" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-muted); font-size: 1.1rem; padding: 5px;"></i>
                </div>
            </div>
            
            <button onclick="submitRecovery()" class="btn" style="margin-bottom: 1rem;">Verify & Unlock</button>
            <button onclick="closeRecovery()" class="btn btn-secondary">Cancel</button>
        </div>
    </div>

    <!-- Part 29: Face Scan Overlay -->
    <div id="faceOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: #000; z-index: 2000; flex-direction: column; align-items: center; justify-content: center; color: white;">
        <video id="faceVideo" autoplay muted playsinline style="width: 100%; height: 100%; object-fit: cover; position: absolute; top:0; left:0; filter: grayscale(1) brightness(0.7);"></video>
        
        <svg viewBox="0 0 200 200" style="width: 250px; height: 250px; z-index: 2001; position: relative;">
            <defs>
                <linearGradient id="faceGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#6366f1;stop-opacity:1" />
                    <stop offset="100%" style="stop-color:#a855f7;stop-opacity:1" />
                </linearGradient>
            </defs>
            <path d="M100,20 C50,20 20,50 20,100 C20,150 50,180 100,180 C150,180 180,150 180,100 C180,50 150,20 100,20 Z" fill="none" stroke="url(#faceGrad)" stroke-width="2" stroke-dasharray="10 5">
                <animate attributeName="stroke-dashoffset" from="0" to="100" dur="3s" repeatCount="indefinite" />
            </path>
            <rect x="40" y="95" width="120" height="1" fill="#6366f1" opacity="0.5">
                <animate attributeName="y" values="40;160;40" dur="2s" repeatCount="indefinite" />
            </rect>
        </svg>

        <div style="z-index: 2002; margin-top: 2rem; text-align: center;">
            <h3 id="faceStatus">Face Scanning...</h3>
            <p style="font-size: 0.875rem; opacity: 0.7;">Align your face to unlock securely</p>
        </div>
        
        <button type="button" onclick="cancelFaceScan()" style="z-index: 2002; margin-top: 3rem; background: transparent; color: white; border: 1px solid rgba(255,255,255,0.3); padding: 0.6rem 2rem; border-radius: 50px;">Cancel</button>
    </div>

    <script>
        let pin = '';
        const dots = document.querySelectorAll('.pin-dot');
        const msg = document.getElementById('msg');

        function updateDots() {
            dots.forEach((dot, i) => {
                if (i < pin.length) dot.classList.add('filled');
                else dot.classList.remove('filled');
            });
        }

        async function press(num) {
            if (pin.length >= 4) return;
            pin += num;
            updateDots();

            if (pin.length === 4) {
                // Verify via API
                try {
                    const fd = new FormData();
                    fd.append('pin', pin);
                    
                    const r = await fetch('api?action=unlock', {
                        method: 'POST',
                        body: fd
                    });
                    const json = await r.json();
                    
                    if (json.status === 'success') {
                        window.location.href = 'index';
                    } else {
                        msg.innerText = 'Incorrect PIN. Try again.';
                        msg.style.color = 'var(--danger)';
                        pin = '';
                        setTimeout(() => {
                            updateDots();
                            msg.innerText = 'Your account is locked';
                            msg.style.color = 'var(--text-muted)';
                        }, 1000);
                    }
                } catch(e) { 
                    alert('Error connecting to server.');
                    pin = '';
                    updateDots();
                }
            }
        }

        function backspace() {
            pin = pin.slice(0, -1);
            updateDots();
        }

        // Part 28 & 29: Biometric Unlock (Upgraded for Face)
        async function biometricUnlock() {
            if (!window.PublicKeyCredential) return;
            
            const overlay = document.getElementById('faceOverlay');
            const video = document.getElementById('faceVideo');
            let stream = null;

            try {
                // Show face scanning UI
                overlay.style.display = 'flex';
                try {
                    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" } });
                    video.srcObject = stream;
                } catch(e) { console.log("Camera access denied"); }

                const challenge = new Uint8Array(32);
                window.crypto.getRandomValues(challenge);

                await navigator.credentials.get({
                    publicKey: {
                        challenge: challenge,
                        timeout: 60000,
                        userVerification: "required"
                    }
                });

                // On success
                const r = await fetch('api?action=unlock_bio');
                const json = await r.json();
                if (json.status === 'success') {
                    if (stream) stream.getTracks().forEach(t => t.stop());
                    window.location.href = 'index';
                }
            } catch (err) {
                console.error("Biometric failed", err);
                cancelFaceScan();
            }
        }

        function cancelFaceScan() {
            const overlay = document.getElementById('faceOverlay');
            const video = document.getElementById('faceVideo');
            if (video.srcObject) {
                video.srcObject.getTracks().forEach(t => t.stop());
                video.srcObject = null;
            }
            overlay.style.display = 'none';
        }

        // Recovery Logic
        document.getElementById('toggleRecoveryPassword')?.addEventListener('click', function() {
            const pw = document.getElementById('recoveryPassword');
            const type = pw.getAttribute('type') === 'password' ? 'text' : 'password';
            pw.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });

        function recoverPin() {
            document.getElementById('recoveryOverlay').style.display = 'flex';
            document.getElementById('recoveryPassword').focus();
        }
        function closeRecovery() {
            document.getElementById('recoveryOverlay').style.display = 'none';
            document.getElementById('recoveryPassword').value = '';
        }
        async function submitRecovery() {
            const pass = document.getElementById('recoveryPassword').value;
            if (!pass) return;

            try {
                const fd = new FormData();
                fd.append('password', pass);
                const r = await fetch('api?action=reset_pin_with_password', {
                    method: 'POST',
                    body: fd
                });
                const json = await r.json();
                if (json.status === 'success') {
                    window.location.href = 'index';
                } else {
                    alert('Invalid password. Please try again.');
                }
            } catch(e) { alert('Error occurred'); }
        }

        // Auto-trigger on load if enabled
        <?php if ($_SESSION['has_bio']): ?>
        window.addEventListener('DOMContentLoaded', biometricUnlock);
        <?php endif; ?>
    </script>
</body>
</html>
