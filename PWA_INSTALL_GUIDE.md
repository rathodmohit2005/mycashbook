# PWA Installation Guide for MyCashBook

## Requirements for Mobile Installation

To install MyCashBook on your phone and use it offline, you need:

1. **HTTPS Connection** - Required for PWA installation on mobile devices
2. **Valid SSL Certificate** - Self-signed certificates won't work for mobile installation

## Setup Instructions

### Option 1: Local Network with HTTPS (Recommended for Testing)

1. **Install a local SSL certificate on your WAMP server:**
   - Download OpenSSL for Windows
   - Generate a self-signed certificate
   - Configure WAMP to use HTTPS (port 443)

2. **Configure WAMP for HTTPS:**
   - Edit `httpd-ssl.conf` in your Apache configuration
   - Point to your certificate files
   - Restart Apache

3. **Access from your phone:**
   - Find your computer's local IP address (run `ipconfig` in Command Prompt)
   - On your phone, connect to the same WiFi network
   - Access: `https://YOUR_IP/my_cash_book`

### Option 2: Free Hosting with HTTPS (Recommended for Production)

1. **Use a free hosting service with HTTPS:**
   - GitHub Pages (static only - won't work with PHP)
   - Netlify (static only - won't work with PHP)
   - InfinityFree (supports PHP with free SSL)
   - 000webhost (supports PHP with free SSL)

2. **Upload your files:**
   - Upload all files from `c:\wamp64\www\my_cash_book` to the hosting service
   - Ensure the service provides HTTPS

### Option 3: Use ngrok for Testing (Quick Setup)

1. **Download ngrok** from https://ngrok.com/

2. **Run ngrok:**
   ```bash
   ngrok http 80
   ```

3. **Use the HTTPS URL provided by ngrok** to access your app from your phone

## Installation on Mobile

### Android (Chrome)

1. Open the app in Chrome browser (must be HTTPS)
2. Tap the menu (three dots) → "Add to Home Screen" or "Install App"
3. Confirm installation
4. The app will appear on your home screen and work offline

### iOS (Safari)

1. Open the app in Safari browser (must be HTTPS)
2. Tap the Share button
3. Scroll down and tap "Add to Home Screen"
4. Confirm installation
5. The app will appear on your home screen

## Offline Functionality

The app now includes:

- **Offline Data Storage**: Transactions are saved to localStorage when offline
- **Auto-sync**: When you come back online, offline transactions sync to the server
- **Cached Assets**: CSS, JS, and icons are cached for offline use
- **Network Detection**: The app detects when you're offline and adjusts accordingly

## Important Notes

- **First-time setup requires internet**: You must be online to install the app initially
- **Data sync**: Offline transactions sync when you reconnect to the internet
- **PHP limitations**: Some features like reports and exports require an active server connection
- **Login required**: You'll need to log in online first; the session will persist offline

## Troubleshooting

### "Add to Home Screen" not appearing
- Ensure you're using HTTPS
- Clear browser cache and try again
- Make sure the service worker is registered (check browser console)

### App not working offline
- Check that the service worker is active (DevTools → Application → Service Workers)
- Ensure you've visited the app at least once while online
- Clear cache and reload the app while online

### Data not syncing
- Check your internet connection
- Ensure the server is accessible
- Check browser console for errors
