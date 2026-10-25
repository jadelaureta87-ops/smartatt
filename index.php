<?php
require_once 'kiosk_auth.php';

$loginError = '';
if (isset($_GET['logout'])) {
    logoutKiosk();
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kiosk_password'])) {
    if (authenticateKiosk((string) $_POST['kiosk_password'])) {
        header('Location: index.php');
        exit;
    }
    $loginError = 'Incorrect kiosk password.';
}

if (!isKioskAuthenticated()):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Baggao NAS Kiosk Login</title>
    <style>
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background:
                radial-gradient(700px 400px at 100% 0%, rgba(16,185,129,0.18), transparent),
                radial-gradient(600px 360px at 0% 100%, rgba(6,95,70,0.14), transparent),
                #eef7f0;
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 20px;
            box-sizing: border-box;
        }
        .login-container {
            max-width: 400px;
            width: 100%;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 14px 38px rgba(2,44,34,0.16);
            border: 1px solid rgba(16,185,129,0.18);
            padding: 32px 28px;
            text-align: center;
            box-sizing: border-box;
        }
        img { max-width: 150px; margin-bottom: 16px; }
        h1 { color: #065f46; font-size: 1.45rem; margin: 0 0 20px; font-weight: 800; letter-spacing: -0.3px; }
        label { display: block; text-align: left; font-weight: 600; margin-bottom: 8px; font-size: 13px; color: #3d5649; }
        input {
            width: 100%;
            padding: 12px 14px;
            box-sizing: border-box;
            border: 1.5px solid #d1e5d8;
            border-radius: 10px;
            font-size: 1rem;
            font-family: inherit;
            margin-bottom: 14px;
            background: #fbfefc;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        input:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 4px rgba(16,185,129,0.15); }
        button {
            width: 100%;
            padding: 12px;
            border: 0;
            border-radius: 10px;
            background: linear-gradient(180deg,#10b981,#059669 55%,#047857);
            color: #fff;
            font-size: 1rem;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: all .2s ease;
            box-shadow: 0 1px 2px rgba(2,44,34,0.06);
        }
        button:hover { background: linear-gradient(180deg,#059669,#047857 60%,#064e3b); transform: translateY(-1px); box-shadow: 0 8px 24px rgba(16,185,129,0.35); }
        .error { color: #881337; background: #fee2e2; border: 1px solid #fecdd3; border-radius: 10px; padding: 12px; margin-bottom: 14px; font-size: 14px; }
    </style>
</head>
<body>
    <main class="login-container">
        <img src="bnas%20logo.png" alt="BNAS Logo">
        <h1>Kiosk Access</h1>
        <?php if ($loginError !== ''): ?><div class="error" role="alert"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
            <label for="kiosk_password">Kiosk password</label>
            <input id="kiosk_password" name="kiosk_password" type="password" required autofocus>
            <button type="submit">Enter Scanner</button>
        </form>
    </main>
</body>
</html>
<?php exit; endif; ?>
<?php
require_once 'db.php';
require_once 'admin_helpers.php';
$geotagRequired = isGeotaggingEnabled($conn);
$allowedPunchTypes = getAllowedPunchTypes($conn);
$punchLabels = ['AM_IN' => 'AM IN', 'AM_OUT' => 'AM OUT', 'PM_IN' => 'PM IN', 'PM_OUT' => 'PM OUT'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Baggao NAS QR Gate Kiosk</title>
    <script src="https://unpkg.com/html5-qrcode"></script>
    <style>
        :root { --brand: #059669; --brand-contrast: #ffffff; --muted: #64748b; --page-bg: #eef7f0; --surface: #ffffff; --accent: #10b981 }
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background:
                radial-gradient(900px 480px at 100% 0%, rgba(16,185,129,0.16), transparent),
                radial-gradient(760px 420px at 0% 100%, rgba(6,95,70,0.12), transparent),
                var(--page-bg);
            margin: 0;
            padding: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .kiosk-container {
            max-width: 620px;
            width: 100%;
            background: var(--surface);
            border-radius: 18px;
            box-shadow: 0 14px 38px rgba(2,44,34,0.16);
            border: 1px solid rgba(16,185,129,0.18);
            padding: 30px;
            text-align: center;
        }
        h1 { margin-top: 10px; color: #065f46; font-weight: 800; font-size: 22px; letter-spacing: -0.3px; }
        .kiosk-container > p:not(.hint) { color: #64748b; font-size: 14px; }
        #reader {
            width: 100%;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid #d1e5d8;
            background: #fbfefc;
        }
        .alert {
            margin-bottom: 16px;
            padding: 16px;
            border-radius: 12px;
            font-size: 1.05rem;
            font-weight: 700;
            display: none;
        }
        .alert-success { background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-warning { background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .alert-error   { background-color: #fee2e2; color: #881337; border: 1px solid #fecdd3; }
        .punch-controls { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 18px 0; }
        .punch-button {
            padding: 13px 8px;
            border: 1.5px solid #d1e5d8;
            border-radius: 12px;
            background: #ffffff;
            color: #047857;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: all .2s ease;
        }
        .punch-button:hover { border-color: #10b981; background: #f0fdf4; }
        .punch-button.selected {
            background: linear-gradient(180deg,#10b981,#059669);
            color: #fff;
            border-color: #059669;
            box-shadow: 0 8px 20px rgba(16,185,129,0.4);
        }
        @media (max-width:520px) { .punch-controls { grid-template-columns: repeat(2, 1fr); } }
        a { color: #047857; font-weight: 600; }
        a.button {
            display: inline-block;
            background: linear-gradient(180deg,#10b981,#059669 55%,#047857);
            color: var(--brand-contrast);
            padding: 9px 14px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all .2s ease;
            margin: 2px;
        }
        a.button:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(16,185,129,0.35); }
        a.link-plain { padding: 9px 14px; display: inline-block; }
    </style>
</head>
<body>

<div class="kiosk-container">
    <img src="bnas%20logo.png" alt="BNAS Logo" style="max-width: 170px; margin-bottom: 16px;">
    <h1>Baggao NAS Student Monitoring and Attendance Recording System
</h1>
    <p class="hint">Position student QR code inside the frame below</p>
    <p>
        <a class="link-plain" href="index.php?logout=1">Lock Kiosk</a>
        <a class="button" href="admin.php">Admin Panel</a>
        <a class="button" href="teacher.php">Teacher</a>
        <a class="button" href="student.php">Student</a>
    </p>
    
    <div class="punch-controls" role="group" aria-label="Attendance action" style="grid-template-columns: repeat(<?= count($allowedPunchTypes) ?>, 1fr);">
        <?php foreach ($allowedPunchTypes as $index => $punchType): ?>
            <button type="button" class="punch-button<?= $index === 0 ? ' selected' : '' ?>" data-punch-type="<?= htmlspecialchars($punchType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($punchLabels[$punchType], ENT_QUOTES, 'UTF-8') ?></button>
        <?php endforeach; ?>
    </div>

    <div id="alertBox" class="alert"></div>

    <div id="reader"></div>
</div>

<script>
    let isProcessing = false;
    let selectedPunchType = document.querySelector('.punch-button')?.dataset.punchType || 'AM_IN';
    const geotagRequired = <?= $geotagRequired ? 'true' : 'false' ?>;

    function requestGeolocation() {
        return new Promise((resolve, reject) => {
            if (!navigator.geolocation) {
                reject(new Error('Location is not supported on this device.'));
                return;
            }
            navigator.geolocation.getCurrentPosition(
                pos => resolve({ latitude: pos.coords.latitude, longitude: pos.coords.longitude }),
                err => {
                    if (err && err.code === 1) {
                        reject(new Error('Location permission was denied. Allow location access to scan.'));
                    } else {
                        reject(new Error('Unable to determine the device location. Make sure location is turned on and try again.'));
                    }
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
            );
        });
    }

    document.querySelectorAll('.punch-button').forEach(button => {
        button.addEventListener('click', () => {
            selectedPunchType = button.dataset.punchType;
            document.querySelectorAll('.punch-button').forEach(item => item.classList.toggle('selected', item === button));
        });
    });

    function showAlert(message, type) {
        const box = document.getElementById('alertBox');
        box.className = `alert alert-${type}`;
        box.innerText = message;
        box.style.display = 'block';

        // Auto-clear alert after 4 seconds
        setTimeout(() => {
            box.style.display = 'none';
        }, 4000);
    }

    function speak(message) {
        if (!('speechSynthesis' in window)) return;

        const cleanMessage = String(message || '').trim();
        if (!cleanMessage) return;

        const utterance = new SpeechSynthesisUtterance(cleanMessage);
        utterance.lang = 'en-US';
        utterance.rate = 1.0;
        utterance.pitch = 1.0;
        utterance.volume = 1.0;

        const applyPreferredVoice = () => {
            const voices = window.speechSynthesis.getVoices();
            const preferredVoice = voices.find(voice => /male|en-us|en_us|en-gb|en_gb/i.test(voice.name))
                || voices.find(voice => voice.lang && voice.lang.startsWith('en'))
                || voices[0];
            if (preferredVoice) utterance.voice = preferredVoice;
        };

        window.speechSynthesis.cancel();
        window.speechSynthesis.resume();
        applyPreferredVoice();
        window.speechSynthesis.speak(utterance);

        if (!window.speechSynthesis.getVoices().length) {
            setTimeout(() => {
                applyPreferredVoice();
                window.speechSynthesis.cancel();
                window.speechSynthesis.speak(utterance);
            }, 500);
        }
    }

    function playScanAnnouncement(data) {
        if (data.status === 'success') {
            speak((data.log_type || selectedPunchType).replace('_', ' ') + ' recorded.');
        } else if (data.status === 'warning') {
            speak(data.message || 'Please try again.');
        } else {
            speak(data.message || 'Scan error.');
        }
    }

    async function onScanSuccess(decodedText, decodedResult) {
        if (isProcessing) return; // Prevent multiple requests at once
        isProcessing = true;

        let geolocation = null;
        if (geotagRequired) {
            try {
                geolocation = await requestGeolocation();
            } catch (err) {
                const geoMessage = err && err.message ? err.message : 'Location access is required to scan.';
                playScanAnnouncement({ status: 'error', message: geoMessage });
                showAlert(geoMessage, 'error');
                setTimeout(() => { isProcessing = false; }, 2500);
                return;
            }
        }

        // The scan must be sent as application/x-www-form-urlencoded.
        // FormData would use multipart/form-data, and the hosting web server
        // (openresty) rejects every multipart/form-data POST with a 403 Forbidden
        // HTML page, so the request would never reach api.php.
        const formData = new URLSearchParams();
        formData.append('student_id', decodedText);
        formData.append('punch_type', selectedPunchType);
        if (geolocation) {
            formData.append('latitude', geolocation.latitude);
            formData.append('longitude', geolocation.longitude);
        }

        fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: formData.toString()
        })
        .then(async response => {
            const responseBody = await response.text();
            let data;
            try {
                data = JSON.parse(responseBody);
            } catch (error) {
                const responsePreview = responseBody.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180);
                console.error('Scan endpoint returned a non-JSON response.', {
                    status: response.status,
                    url: response.url,
                    body: responseBody
                });
                const detail = responsePreview ? ` Response: ${responsePreview}` : '';
                const hint = response.status === 403
                    ? ' The web server blocked the request before it reached the app. The host rejects multipart/form-data uploads, so requests must use application/x-www-form-urlencoded.'
                    : '';
                throw new Error(`Scan endpoint returned HTTP ${response.status} instead of JSON.${detail}${hint}`);
            }
            return data;
        })
        .then(data => {
            const action = data.log_type || selectedPunchType;
            const smsSummary = data.sms_status === 'sent'
                ? 'Parent SMS sent.'
                : data.sms_status === 'failed'
                    ? 'Parent SMS failed.'
                    : data.sms_status === 'missing_phone'
                        ? 'No parent phone number available.'
                        : data.sms_status === 'disabled'
                            ? 'Parent SMS notifications are off.'
                            : data.sms_status === 'limit_reached'
                                ? 'Daily SMS limit reached; parent not notified.'
                                : 'SMS not attempted.';

            if (data.status === 'success') {
                playScanAnnouncement(data);
                showAlert(`${data.student_name} - ${action} at ${data.time}. ${smsSummary}`, 'success');
            } else if (data.status === 'warning') {
                playScanAnnouncement(data);
                showAlert(`${data.message} ${smsSummary}`.trim(), 'warning');
            } else {
                playScanAnnouncement(data);
                showAlert(data.message, 'error');
            }
        })
        .catch(err => {
            showAlert(err && err.message ? err.message : 'Server error processing scan.', 'error');
            console.error(err);
        })
        .finally(() => {
            // Re-enable scanner after 2.5 seconds cooling delay
            setTimeout(() => { isProcessing = false; }, 2500);
        });
    }

    // Initialize WebCam Scanner
    const html5QrcodeScanner = new Html5QrcodeScanner(
        "reader", 
        { fps: 10, qrbox: { width: 250, height: 250 } }, 
        /* verbose= */ false
    );
    html5QrcodeScanner.render(onScanSuccess);
</script>
<b>Developed by : VJ Laureta Alrights Reserved © 2026</b>
</body>
</html>