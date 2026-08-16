<?php
header('Content-Type: application/manifest+json');
?>
{
  "name": "Cash Book - Expense Tracker",
  "short_name": "CashBook",
  "description": "Professional expense manager with offline support, voice input, and multiple accounts. Tracks your daily cash flow with ease.",
  "start_url": "index",
  "display": "standalone",
  "display_override": ["standalone", "minimal-ui"],
  "background_color": "#020617",
  "theme_color": "#6366f1",
  "scope": "./",
  "categories": ["finance", "productivity"],
  "icons": [
    {
      "src": "assets/icons/icon.svg",
      "sizes": "any",
      "type": "image/svg+xml",
      "purpose": "any maskable"
    },
    {
      "src": "assets/icons/icon-192.png",
      "sizes": "192x192",
      "type": "image/png",
      "purpose": "any maskable"
    },
    {
      "src": "assets/icons/icon-512.png",
      "sizes": "512x512",
      "type": "image/png",
      "purpose": "any maskable"
    }
  ],
  "share_target": {
    "action": "add",
    "method": "GET",
    "enctype": "application/x-www-form-urlencoded",
    "params": {
      "title": "title",
      "text": "text"
    }
  },
  "screenshots": [
    {
      "src": "assets/screenshots/screenshot1.png",
      "sizes": "1024x1024",
      "type": "image/png",
      "form_factor": "narrow",
      "label": "Professional Dashboard"
    },
    {
      "src": "assets/screenshots/screenshot2.png",
      "sizes": "1024x1024",
      "type": "image/png",
      "form_factor": "narrow",
      "label": "Smart Voice Entry"
    }
  ]
}
