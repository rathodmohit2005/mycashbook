<?php
require_once 'db.php';
require_once 'auth.php';
requireLogin();

$pageTitle = 'Help & Terms';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Help & Terms - Cash Book</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .help-section {
            background: var(--surface);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border);
        }

        .help-section h3 {
            color: var(--primary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.1rem;
        }

        .help-list {
            list-style: none;
            padding: 0;
        }

        .help-list li {
            margin-bottom: 1rem;
            padding-left: 1.5rem;
            position: relative;
            font-size: 0.9rem;
            line-height: 1.5;
            color: var(--text-main);
        }

        .help-list li::before {
            content: "\f058";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 0;
            color: var(--success);
        }

        .terms-text {
            font-size: 0.85rem;
            color: var(--text-muted);
            line-height: 1.6;
        }

        .step-badge {
            background: var(--primary);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 700;
            margin-right: 5px;
        }

        /* Hide Google Translate Toolbar */
        .goog-te-banner-frame {
            display: none !important;
        }
        body {
            top: 0 !important;
        }
        .skiptranslate {
            display: none !important;
        }
    </style>
</head>

<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <h2 class="section-title" style="margin-bottom: 0;"><i class="fa-solid fa-circle-question"></i> How to Use</h2>
            <div class="notranslate" style="display: flex; gap: 0.6rem; align-items: center;">
                <select id="voiceLang" class="form-control" style="width: auto; padding: 0.4rem 2.2rem 0.4rem 0.8rem; font-size: 0.8rem; height: 38px; border-radius: var(--radius-md); background-color: var(--surface);">
                    <option value="en-US">🇺🇸 English</option>
                    <option value="hi-IN">🇮🇳 Hindi</option>
                    <option value="gu-IN">🇮🇳 Gujarati</option>
                </select>
                <button id="ttsBtn" class="btn-icon" title="Listen to Instructions" style="background: var(--primary); color: white; width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-md); border-radius: 50%;">
                    <i class="fa-solid fa-volume-high"></i>
                </button>
            </div>
        </div>

        <!-- Hidden Google Translate Element -->
        <div id="google_translate_element" style="display:none;"></div>


        <div class="help-section">
            <h3><i class="fa-solid fa-wallet"></i> 1. Managing Accounts & Books</h3>
            <ul class="help-list">
                <li><strong>Multiple Accounts:</strong> You can create separate "Books" for your Bank, Cash-in-hand, and digital wallets. This helps you keep your personal and business money separate.</li>
                <li><strong>Switching:</strong> Tap the Account Switcher on the Dashboard to filter transactions.
                    <ul>
                        <li style="margin-top:0.5rem; list-style:none;">&bull; <strong>Mobile:</strong> Appears as a swipeable bottom sheet.</li>
                        <li style="margin-top:0.3rem; list-style:none;">&bull; <strong>Desktop:</strong> Appears as a professional centered modal for easy navigation.</li>
                    </ul>
                </li>
            </ul>

            <h3><i class="fa-solid fa-pen-to-square"></i> 2. Logging Transactions (The "Add" Page)</h3>
            <ul class="help-list">
                <li><strong>Manual Entry:</strong> Tap the (+) button. Choose "Cash Out" for expenses (Red) or "Cash In" for income (Green).
                    <ul>
                        <li style="margin-top:0.5rem; list-style:none;">&bull; <strong>Select Account:</strong> Choose which wallet or bank the money is coming from.</li>
                        <li style="margin-top:0.3rem; list-style:none;">&bull; <strong>Fixed Currency:</strong> To keep your books accurate, the currency is locked to your <strong>Main Currency</strong> (set in Settings). This prevents mistakes and ensures all reports match.</li>
                        <li style="margin-top:0.3rem; list-style:none;">&bull; <strong>Custom Categories:</strong> If you don't see a category you like, select <strong>"Other"</strong> to type your own name.</li>
                    </ul>
                </li>
                <li><strong>Smart Input & Voice:</strong> Tap the <strong>Smart Box</strong> or Microphone icon.
                    <ul>
                        <li style="margin-top:0.5rem; list-style:none;">&bull; <strong>How it works:</strong> Type or say: <em>"Spent 200 for pizza"</em>. The app automatically detects the Amount (200), Type (Cash Out), and Note (pizza).</li>
                        <li style="margin-top:0.3rem; list-style:none;">&bull; <strong>Account Detection:</strong> You can even say: <em>"Received 500 in Bank"</em> to automatically select the account!</li>
                    </ul>
                </li>
                <li><strong>Recurring Payments:</strong> For monthly bills like Rent or Netflix, check the <strong>"Make Recurring"</strong> box at the bottom. The app will automatically log the transaction for you in the future!</li>
            </ul>

            <h3><i class="fa-solid fa-chart-pie"></i> 3. Reports & Analytics</h3>
            <ul class="help-list">
                <li><strong>Visual Charts:</strong> Visit the <strong>Reports</strong> section to see a breakdown of your spending by category.</li>
                <li><strong>PDF Export:</strong> Generate a professional PDF report of your filtered transactions for your records or for tax purposes.</li>
                <li><strong>Budget Tracking:</strong> Set a monthly budget in Settings. A progress bar on the Dashboard will warn you if you reach 80% (Yellow) or exceed it (Red).</li>
            </ul>

            <h3><i class="fa-solid fa-shield-halved"></i> 4. Security & Privacy</h3>
            <ul class="help-list">
                <li><strong>PIN Lock:</strong> Set a 4-digit code in Settings. The app will lock itself when closed or after 5 minutes of inactivity.</li>
                <li><strong>PIN Recovery:</strong> If you forget your PIN, you can reset it by verifying your account password on the lock screen.</li>
                <li><strong>Biometrics:</strong> Enable Fingerprint or FaceID for faster, secure access.</li>
                <li><strong>Local Storage:</strong> Your data is stored on your own server. We do not track or sell your financial information.</li>
            </ul>

            <h3><i class="fa-solid fa-cloud-arrow-up"></i> 5. Backups & CSV Import Guide</h3>
            <ul class="help-list">
                <li><strong>CSV Format for Import:</strong> Use headers: <em>Transaction Type, Amount, Currency, Date, Note, Category, Account Name</em>.</li>
                <li><strong>Offline Export:</strong> You can download a CSV of your data even without internet! The app generates the file directly in your browser.</li>
                <li><strong>Offline Import:</strong> You can upload a CSV while offline. The app will save your data locally and sync it to the server once you are back online.</li>
            </ul>

            <h3><i class="fa-solid fa-mobile-screen-button"></i> 6. Installing as an App (PWA)</h3>
            <ul class="help-list">
                <li><strong>Android:</strong> Open the app in Chrome, tap the 3 dots, and select "Install App" or "Add to Home Screen".</li>
                <li><strong>iOS (iPhone):</strong> Open in Safari, tap the Share icon, and select "Add to Home Screen".</li>
                <li><strong>Offline Use:</strong> Once installed, the app works even without an internet connection!</li>
                <li><strong>Unlimited Offline Logging:</strong> You can create multiple accounts and add unlimited transactions while offline. They will sync automatically when you are back online.</li>
            </ul>

            <h3><i class="fa-solid fa-filter"></i> 7. Advanced Search & Filters</h3>
            <ul class="help-list">
                <li><strong>Search:</strong> Tap the Search icon on the Dashboard to find transactions by note or description.</li>
                <li><strong>Date Range:</strong> Filter your history by selecting a "Start Date" and "End Date" to see exactly how much you spent during a trip or a specific week.</li>
            </ul>

            <h3><i class="fa-solid fa-cloud-slash"></i> 8. Offline Limitations</h3>
            <p style="font-size: 0.85rem; margin-bottom: 0.5rem; color: var(--danger); font-weight: 600;">The following features require an active internet connection to work:</p>
            <ul class="help-list" style="color: var(--text-main);">
                <li><strong>Global Currency Conversion:</strong> Changing your default currency requires a live connection to fetch real-time exchange rates.</li>
                <li><strong>Voice Assistant:</strong> Speech-to-text processing (the microphone button) generally requires internet to translate your voice into text.</li>
                <li><strong>Auto Backup & Sync:</strong> Saving data to your Google Drive or receiving Email alerts requires a cloud connection.</li>
                <li><strong>App Installation:</strong> You must be online at least once to install this PWA to your home screen.</li>
                <li><strong>Daily Reminders:</strong> Setting up new push notifications requires an initial internet connection.</li>
            </ul>

            <h3><i class="fa-solid fa-trash-can"></i> 9. Data Management & Cleanup</h3>
            <ul class="help-list">
                <li><strong>Bulk Deletion:</strong> Use the "Data Management" tool in Settings to delete all transactions for a specific year or month.</li>
                <li><strong>Smart Filtering:</strong> The system automatically detects which years and months have transactions, making it easy to find and clean up old data.</li>
                <li><strong>Safe Cleanup:</strong> This is a permanent action. We recommend taking a backup before performing a bulk deletion.</li>
            </ul>

            <h3><i class="fa-solid fa-palette"></i> 10. Personalization & Themes</h3>
            <ul class="help-list">
                <li><strong>Dark Mode:</strong> Tap the Moon/Sun icon in the header to switch themes. This also helps save battery on OLED screens.</li>
                <li><strong>Custom Currencies:</strong> You can change your primary currency in Settings anytime.</li>
            </ul>

            <h3><i class="fa-solid fa-star"></i> 11. Expert Tips & Shortcuts</h3>
            <ul class="help-list">
                <li><strong>Fast Add:</strong> Long-press the (+) button to jump straight to the manual entry form.</li>
                <li><strong>Smart Search:</strong> Use the Search bar to quickly find transactions from years ago by just typing a keyword like "Rent" or "Pizza".</li>
                <li><strong>Regular Audits:</strong> We recommend exporting a PDF report at the end of every month to keep a permanent offline record of your growth.</li>
            </ul>

            <h3><i class="fa-solid fa-globe"></i> 12. Automated Currency Conversion</h3>
            <ul class="help-list">
                <li><strong>Real-Time API:</strong> When you change your Default Currency in Settings, the app connects to <strong>ExchangeRate-API</strong> to fetch the exact market price for that moment.</li>
                <li><strong>Past History Update:</strong> The app automatically goes back and multiplies every past transaction by the live rate. For example, if you change from USD to INR, a $10.00 transaction will instantly become ₹835.00 (or whatever the current rate is).</li>
                <li><strong>Online Requirement:</strong> To prevent data errors, the currency conversion only works when you are <strong>online</strong>. If you are offline, the app will block the change until you have an internet connection to get the latest price.</li>
            </ul>
        </div>


    <div class="section-header" style="margin-top: 3rem;">
        <h2 class="section-title"><i class="fa-solid fa-file-contract"></i> Terms & Conditions</h2>
    </div>

    <div class="help-section">
        <ul class="help-list">
            <li><strong>Data Privacy:</strong> All financial records are stored locally on your server or your personal Google Drive. We do not have access to your data.</li>
            <li><strong>User Responsibility:</strong> You are responsible for maintaining the security of your device and backup files.</li>
            <li><strong>No Warranty:</strong> This app is provided "as is" without any warranties. Always keep a manual backup for important data.</li>
            <li><strong>Usage:</strong> By using this app, you agree to use it only for personal or legal business financial tracking.</li>
        </ul>
    </div>

    <div style="text-align: center; margin-top: 2rem; margin-bottom: 4rem;">
        <p style="font-size: 0.8rem; color: var(--text-muted);">Cash Book v2.0 - Made with ❤️ for your finances.</p>
    </div>
    </div>

    <?php include 'nav.php'; ?>
    <script>
        let isSpeaking = false;
        const synth = window.speechSynthesis;
        let utterance = null;

        function toggleTTS() {
            if (isSpeaking) {
                synth.cancel();
                stopUI();
                return;
            }

            // Get all help sections
            const helpSections = document.querySelectorAll('.help-section');
            if (helpSections.length === 0) return;

            let textToRead = "";
            helpSections.forEach((section, index) => {
                const title = section.previousElementSibling?.querySelector('.section-title')?.innerText || "";
                if (title) textToRead += title + ". ";
                
                textToRead += section.innerText
                    .replace(/\d+\./g, 'Section $&') 
                    .replace(/•/g, 'Point:')
                    .trim() + " ";
            });

            utterance = new SpeechSynthesisUtterance(textToRead);

            // Set Language from Local Dropdown
            const selectedLang = document.getElementById('voiceLang').value;
            utterance.lang = selectedLang;

            // Find a matching voice for the selected language
            const voices = synth.getVoices();
            const matchingVoice = voices.find(v => v.lang.startsWith(selectedLang.split('-')[0]));
            if (matchingVoice) utterance.voice = matchingVoice;

            utterance.rate = 1.0;
            utterance.pitch = 1.0;

            utterance.onstart = () => {
                isSpeaking = true;
                document.getElementById('ttsBtn').innerHTML = '<i class="fa-solid fa-circle-stop"></i>';
                document.getElementById('ttsBtn').style.background = 'var(--danger)';
            };

            utterance.onend = () => stopUI();
            utterance.onerror = () => stopUI();

            synth.speak(utterance);
        }

        function stopUI() {
            isSpeaking = false;
            document.getElementById('ttsBtn').innerHTML = '<i class="fa-solid fa-volume-high"></i>';
            document.getElementById('ttsBtn').style.background = 'var(--primary)';
        }

        document.getElementById('ttsBtn').addEventListener('click', toggleTTS);

        // Stop speaking if user leaves page
        window.onbeforeunload = () => synth.cancel();
    </script>
    <script type="text/javascript">
        function googleTranslateElementInit() {
            new google.translate.TranslateElement({
                pageLanguage: 'en',
                includedLanguages: 'en,hi,gu',
                autoDisplay: false
            }, 'google_translate_element');
        }

        function translatePage(langCode) {
            const googleCombo = document.querySelector('.goog-te-combo');
            if (googleCombo) {
                googleCombo.value = langCode;
                googleCombo.dispatchEvent(new Event('change'));
            } else {
                // If Google Translate hasn't loaded yet, try again in a bit
                setTimeout(() => translatePage(langCode), 500);
            }
        }

        // Update dropdown listener to also trigger translation
        document.getElementById('voiceLang').addEventListener('change', function(e) {
            const fullCode = e.target.value;
            const langCode = fullCode.split('-')[0]; // Convert en-US to en, etc.
            translatePage(langCode);
        });
    </script>
    <script type="text/javascript" src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>
    <script src="assets/js/app.js"></script>
</body>

</html>