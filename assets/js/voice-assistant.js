/**
 * Cash Book - Voice Assistant (Version 6.1 - Robust Architecture)
 * Unified Natural Language Understanding & Voice Control
 */
class VoiceAssistant {
    constructor() {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        this.isSupported = !!SpeechRecognition;

        this.overlay = document.getElementById('voiceOverlay');
        this.statusText = document.getElementById('voiceStatus');
        this.transcription = document.getElementById('voiceTranscription');

        this.isProcessing = false;
        this.isListening = false;
        this.retryCount = 0;
        this.lastTranscript = '';

        if (!this.isSupported) {
            console.warn("Speech Recognition is not supported in this browser environment.");
            return;
        }

        try {
            this.recognition = new SpeechRecognition();
            this.synthesis = window.speechSynthesis;
            
            this.recognition.continuous = false;
            this.recognition.interimResults = true;
            this.recognition.maxAlternatives = 1;

            this._initEvents();
        } catch (e) {
            console.error("Failed to initialize Speech Recognition:", e);
            this.isSupported = false;
        }
    }

    _initEvents() {
        if (!this.recognition) return;

        this.recognition.onstart = () => {
            this.isListening = true;
            this._setMicActive(true);
            if (this.overlay) this.overlay.style.display = 'flex';
            if (this.statusText) this.statusText.innerText = 'Listening... Speak now';
            if (this.transcription) this.transcription.innerText = '';
            this.lastTranscript = '';
        };

        this.recognition.onresult = (event) => {
            this.retryCount = 0;
            let currentText = '';

            for (let i = event.resultIndex; i < event.results.length; i++) {
                const transcript = event.results[i][0].transcript;
                currentText += transcript;
            }

            const cleanText = currentText.trim();
            if (cleanText) {
                this.lastTranscript = cleanText;
                if (this.transcription) this.transcription.innerText = `"${cleanText}"`;
            }

            const lastResult = event.results[event.results.length - 1];
            if (lastResult && lastResult.isFinal && cleanText && !this.isProcessing) {
                this.processCommand(cleanText);
            }
        };

        this.recognition.onerror = (event) => {
            const err = event ? event.error : 'unknown';
            console.warn("Voice Assistant Error:", err);
            this.isListening = false;
            this._setMicActive(false);

            if (this.overlay) this.overlay.style.display = 'flex';

            if (err === 'no-speech') {
                if (this.retryCount < 2 && !this.isProcessing && this._isOverlayVisible()) {
                    this.retryCount++;
                    if (this.statusText) this.statusText.innerText = "Didn't catch that. Retrying...";
                    setTimeout(() => {
                        if (!this.isProcessing && this._isOverlayVisible()) {
                            this.restart();
                        }
                    }, 400);
                    return;
                }
                if (this.statusText) this.statusText.innerText = "No speech heard. Tap mic to try again.";
            } else if (err === 'not-allowed' || err === 'permission-denied' || err === 'service-not-allowed') {
                if (window.location.protocol === 'http:' && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
                    if (this.statusText) this.statusText.innerText = "HTTPS Required for Voice Command.";
                    if (this.transcription) this.transcription.innerText = "Chrome & Edge restrict microphone access to HTTPS or localhost.";
                } else {
                    if (this.statusText) this.statusText.innerText = "Microphone access blocked.";
                    if (this.transcription) this.transcription.innerText = "Please allow microphone access in browser settings.";
                }
            } else if (err === 'network') {
                if (this.statusText) this.statusText.innerText = "Network error. Speech recognition requires internet connection.";
            } else if (err === 'audio-capture') {
                if (this.statusText) this.statusText.innerText = "No microphone detected on device.";
            } else if (err === 'aborted') {
                // Ignore silent abort
                return;
            } else {
                if (this.statusText) this.statusText.innerText = "Voice error: " + err;
            }

            setTimeout(() => {
                if (!this.isListening && !this.isProcessing && this._isOverlayVisible() && err !== 'not-allowed' && err !== 'permission-denied') {
                    this.stop();
                }
            }, 4000);
        };

        this.recognition.onend = () => {
            this.isListening = false;
            this._setMicActive(false);

            // Fallback: If transcript captured but not processed yet
            if (this.lastTranscript && !this.isProcessing && this.retryCount === 0) {
                this.processCommand(this.lastTranscript);
                return;
            }

            if (!this.isProcessing) {
                setTimeout(() => {
                    if (!this.isListening && !this.isProcessing && this._isOverlayVisible()) {
                        this.stop();
                    }
                }, 1500);
            }
        };
    }

    _isOverlayVisible() {
        return this.overlay && this.overlay.style.display !== 'none';
    }

    _setMicActive(active) {
        if (!this.overlay) return;
        const micBtn = this.overlay.querySelector('.mic-container');
        if (micBtn) {
            if (active) micBtn.classList.add('listening');
            else micBtn.classList.remove('listening');
        }
    }

    start() {
        // Show overlay immediately so user has visual feedback
        if (this.overlay) this.overlay.style.display = 'flex';

        if (!this.isSupported || !this.recognition) {
            if (this.statusText) this.statusText.innerText = "Speech Assistant Unsupported";
            if (this.transcription) {
                if (window.location.protocol === 'http:' && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
                    this.transcription.innerText = "Voice recognition requires HTTPS or localhost when using Chrome / Edge.";
                } else {
                    this.transcription.innerText = "Please use Google Chrome, Microsoft Edge, or Safari.";
                }
            }
            return;
        }

        this.isProcessing = false;
        this.retryCount = 0;
        this.lastTranscript = '';
        if (this.statusText) this.statusText.innerText = 'Listening... Speak now';
        if (this.transcription) this.transcription.innerText = '';
        this._setMicActive(true);

        // Select speech recognition language (Default to Indian English for optimal local currency/names parsing)
        const userLang = localStorage.getItem('voice_lang') || 'en-IN';
        this.recognition.lang = userLang;

        try {
            this.recognition.start();
        } catch (e) {
            if (e.name === 'InvalidStateError') {
                try { this.recognition.stop(); } catch (err) {}
                setTimeout(() => {
                    try { this.recognition.start(); } catch (err) {
                        console.error("SpeechRecognition start error:", err);
                    }
                }, 200);
            } else {
                console.error("SpeechRecognition start error:", e);
            }
        }
    }

    restart() {
        try {
            this.recognition.stop();
        } catch (e) {}
        setTimeout(() => {
            try { this.recognition.start(); } catch (e) {}
        }, 200);
    }

    stop() {
        this.isListening = false;
        this.isProcessing = false;
        this.retryCount = 0;
        this._setMicActive(false);
        try {
            if (this.recognition) this.recognition.stop();
        } catch (e) {}
        if (this.overlay) this.overlay.style.display = 'none';
    }

    speak(text) {
        if (!this.synthesis) return;
        try {
            this.synthesis.cancel(); // Stop any pending speech
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.rate = 1.0;
            utterance.pitch = 1.0;
            this.synthesis.speak(utterance);
        } catch (e) {}
    }

    async processCommand(commandText) {
        const text = (commandText || '').trim();
        if (!text) return;

        this.isProcessing = true;
        this._setMicActive(false);
        if (this.statusText) this.statusText.innerText = 'Analyzing command...';
        const lower = text.toLowerCase();

        // 1. Basic System Commands
        if (lower.includes('balance') || lower.includes('how much money') || lower.includes('total balance')) {
            try {
                const resp = await fetch('api.php?action=get_voice_summary');
                const data = await resp.json();
                const bal = data.balance !== undefined ? data.balance : 0;
                this.speak(`Your total balance is ${bal} rupees.`);
                if (this.statusText) this.statusText.innerText = `Balance: ₹${bal}`;
            } catch (err) {
                if (this.statusText) this.statusText.innerText = 'Failed to fetch balance.';
            }
            setTimeout(() => this.stop(), 3000);
            return;
        }

        if (lower.includes('settings')) {
            this.speak("Opening Settings");
            window.location.href = 'settings';
            return;
        }

        if (lower.includes('report') || lower.includes('analytics')) {
            this.speak("Opening Reports");
            window.location.href = 'report';
            return;
        }

        if (lower.includes('account') || lower.includes('bank')) {
            this.speak("Opening Accounts");
            window.location.href = 'accounts';
            return;
        }

        // 2. Process Transaction with NLU Engine
        try {
            let resp = await fetch('api.php?action=process_nlu&q=' + encodeURIComponent(text));
            if (!resp.ok) {
                resp = await fetch('api?action=process_nlu&q=' + encodeURIComponent(text));
            }
            const res = await resp.json();

            if (res.status === 'success' && res.data) {
                const d = res.data;
                const typeName = d.type === 'income' ? 'Income' : 'Expense';
                
                if (d.amount > 0) {
                    this.speak(`Recording ${typeName} of ${d.amount} for ${d.note}`);
                } else {
                    this.speak(`Opening entry for ${d.note}`);
                }

                if (this.statusText) this.statusText.innerText = "Redirecting to save...";

                const params = new URLSearchParams({
                    smart: text,
                    nlu: JSON.stringify(d)
                });

                setTimeout(() => {
                    window.location.href = 'add?' + params.toString();
                }, 700);
                return;
            }
        } catch (e) {
            console.error("NLU Processing failed:", e);
        }

        // Fallback for unhandled speech
        this.speak("I didn't catch that clearly. Try: Received 5000 rupees from father.");
        if (this.statusText) this.statusText.innerText = 'Try: Received 5000 from father';
        this.isProcessing = false;

        setTimeout(() => {
            if (!this.isListening && !this.isProcessing && this._isOverlayVisible()) {
                this.stop();
            }
        }, 3500);
    }
}

// Global Voice Assistant Instance
let assistant;
document.addEventListener('DOMContentLoaded', () => {
    assistant = new VoiceAssistant();

    // Attach click listener to overlay mic container for manual tap-to-speak
    const overlay = document.getElementById('voiceOverlay');
    if (overlay) {
        const micContainer = overlay.querySelector('.mic-container');
        if (micContainer) {
            micContainer.style.cursor = 'pointer';
            micContainer.title = 'Tap to record';
            micContainer.onclick = () => {
                if (assistant && !assistant.isProcessing) {
                    assistant.start();
                }
            };
        }
    }
});

function toggleAssistant() {
    if (!assistant) {
        assistant = new VoiceAssistant();
    }
    if (assistant.isListening) {
        assistant.stop();
    } else {
        assistant.start();
    }
}

