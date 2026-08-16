<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<nav class="bottom-nav notranslate">
    <a href="index" class="nav-item <?= $currentPage === 'index.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-house"></i>
        <span>Home</span>
    </a>
    <a href="add" class="nav-item <?= $currentPage === 'add.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-plus"></i>
        <span>Add</span>
    </a>
    <a href="accounts" class="nav-item <?= $currentPage === 'accounts.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-layer-group"></i>
        <span>Books</span>
    </a>
    <a href="report" class="nav-item <?= $currentPage === 'report.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-chart-pie"></i>
        <span>Reports</span>
    </a>
    <a href="recurring" class="nav-item <?= $currentPage === 'recurring.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-repeat"></i>
        <span>Auto</span>
    </a>
    <a href="help" class="nav-item <?= $currentPage === 'help.php' ? 'active' : '' ?>">
        <i class="fa-solid fa-circle-info"></i>
        <span>Help</span>
    </a>
</nav>

<!-- Install Prompt Modal -->
<div id="installModal" class="install-modal notranslate" style="display: none;">
    <div class="install-content">
        <div class="install-icon"><img src="<?= ASSETS_URL ?>icons/icon.svg" alt="App Icon"></div>
        <div class="install-text">
            <h4>Install Cash Book</h4>
            <p>Add to your home screen for quick offline access.</p>
        </div>
        <button id="installBtn" class="btn btn-sm" style="width: auto;">Install</button>
        <button id="closeInstallBtn" class="btn-icon" style="position:absolute; top:5px; right:5px;"><i class="fa-solid fa-times"></i></button>
    </div>
</div>

<!-- Global Voice Assistant Overlay -->
<div id="voiceOverlay" style="display: none;">
    <div class="mic-container" style="position: relative; margin-bottom: 2rem;">
        <div style="width: 100px; height: 100px; background: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem;">
            <i class="fa-solid fa-microphone"></i>
        </div>
    </div>
    <h2 id="voiceStatus" style="font-weight: 300;">Listening...</h2>
    <p id="voiceTranscription" style="margin-top: 1rem; font-style: italic; opacity: 0.8; max-width: 80%; text-align: center; height: 1.5em;"></p>
    <button onclick="if(assistant) assistant.stop()" style="margin-top: 3rem; background: transparent; border: 1px solid white; color: white; padding: 0.5rem 2rem; border-radius: 20px; cursor: pointer;">Cancel</button>
</div>

<script src="assets/js/voice-assistant.js?v=6.1" defer></script>

