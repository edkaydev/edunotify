const express = require('express');
const { Client, LocalAuth } = require('whatsapp-web.js');
const QRCode = require('qrcode');
const path = require('path');
const fs = require('fs');
const { execSync } = require('child_process');

const PORT = parseInt(process.env.EDUNOTIFY_BOT_PORT || '5001', 10);
const SESSION_PATH = process.env.EDUNOTIFY_SESSION_PATH || path.join(__dirname, 'session');
const API_KEY = process.env.EDUNOTIFY_API_KEY || process.env.WANOTIFY_API_KEY || '';
const COUNTRY_CODE = process.env.COUNTRY_CODE || '256';
const BATCH_SIZE = parseInt(process.env.EDUNOTIFY_BATCH_SIZE || '20', 10);
const BATCH_PAUSE_MS = parseInt(process.env.EDUNOTIFY_BATCH_PAUSE_MS || '90000', 10);
const DAILY_LIMIT = parseInt(process.env.EDUNOTIFY_DAILY_LIMIT || '500', 10);
const HOURLY_LIMIT = parseInt(process.env.EDUNOTIFY_HOURLY_LIMIT || '80', 10);
const OPTOUT_URL = process.env.EDUNOTIFY_OPTOUT_URL || '';

if (!API_KEY) {
    console.error('[FATAL] EDUNOTIFY_API_KEY is not set.');
    process.exit(1);
}

const app = express();
app.use(express.json({ limit: '1mb' }));

let client = null;
let connectionStatus = 'disconnected';
let currentQR = null;
let currentStatusMessage = 'Bot initialized';

const todayKey = () => {
    const d = new Date();
    return `${d.getFullYear()}-${d.getMonth()+1}-${d.getDate()}`;
};
let dailyCount = 0;
let dailyReset = todayKey();
let hourlyCount = 0;
let hourlyReset = Date.now();

function resetCounters() {
    const now = todayKey();
    if (now !== dailyReset) {
        dailyCount = 0;
        dailyReset = now;
    }
    if (Date.now() - hourlyReset > 3600000) {
        hourlyCount = 0;
        hourlyReset = Date.now();
    }
}

function log(msg) {
    const ts = new Date().toISOString().replace('T', ' ').slice(0, 19);
    console.log(`[${ts}] ${msg}`);
}

function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function jitter(base) {
    return Math.round(base * (0.8 + Math.random() * 0.4));
}

function requireAuth(req, res, next) {
    const key = req.headers['x-api-key'];
    if (!key || key !== API_KEY) {
        return res.status(401).json({ error: 'Unauthorized' });
    }
    next();
}

function formatNumber(raw) {
    let clean = raw.toString().trim().replace(/[\s\-\+\(\)]/g, '');
    if (clean.startsWith('0')) {
        clean = COUNTRY_CODE + clean.substring(1);
    }
    if (!clean.endsWith('@c.us')) {
        clean = clean + '@c.us';
    }
    return clean;
}

async function startClient() {
    if (client) {
        try { client.destroy(); } catch (e) {}
        client = null;
    }

    connectionStatus = 'initializing';
    currentQR = null;
    currentStatusMessage = 'Starting WhatsApp client...';
    log('Starting WhatsApp client');

    process.env.CHROME_CRASHPAD_HANDLER = '';

    let chromePath = process.env.PUPPETEER_EXECUTABLE_PATH;
    if (!chromePath) {
        const candidates = [
            '/usr/bin/google-chrome-stable', '/usr/bin/google-chrome',
            '/usr/bin/chromium-browser', '/usr/bin/chromium',
        ];
        for (const p of candidates) {
            if (fs.existsSync(p)) { chromePath = p; break; }
        }
        // Skip snap chromium — it can't run as www-data due to snap confinement.
        if (!chromePath) {
            // Download Chromium via @puppeteer/browsers
            const { install } = require('@puppeteer/browsers');
            const chromeDir = '/var/www/.cache/puppeteer';
            try {
                log('No system Chrome found. Downloading Chromium...');
                const browser = await install({
                    browser: 'chrome',
                    buildId: 'latest',
                    cacheDir: chromeDir,
                    downloadProgressCallback: (downloadedBytes, totalBytes) => {
                        if (totalBytes) {
                            const pct = Math.round(downloadedBytes / totalBytes * 100);
                            if (pct % 25 === 0 || downloadedBytes === totalBytes) {
                                log(`Downloading Chromium: ${pct}%`);
                            }
                        }
                    },
                });
                chromePath = browser.executablePath;
                log('Downloaded Chromium: ' + chromePath);
            } catch (e) {
                log('Failed to download Chromium: ' + e.message);
                // Last resort — try /snap/bin/chromium anyway
                if (fs.existsSync('/snap/bin/chromium')) {
                    chromePath = '/snap/bin/chromium';
                    log('Falling back to snap chromium');
                }
            }
            if (!fs.existsSync(chromeDir)) {
                fs.mkdirSync(chromeDir, { recursive: true, mode: 0o755 });
            }
        }
    }
    if (chromePath) {
        process.env.PUPPETEER_EXECUTABLE_PATH = chromePath;
    }

    const puppeteerOpts = {
        headless: true,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--disable-crashpad-forwarding',
            '--disable-features=Crashpad',
        ],
    };
    if (chromePath) {
        puppeteerOpts.executablePath = chromePath;
        log('Using Chrome: ' + chromePath);
    }

    client = new Client({
        authStrategy: new LocalAuth({ dataPath: SESSION_PATH }),
        puppeteer: puppeteerOpts,
    });

    client.on('qr', async (qr) => {
        try {
            currentQR = await QRCode.toDataURL(qr, { width: 300, margin: 2 });
            connectionStatus = 'awaiting_scan';
            currentStatusMessage = 'Scan QR code with WhatsApp';
            log('QR code generated');
        } catch (err) {
            log('QR generation failed: ' + err.message);
        }
    });

    client.on('ready', () => {
        connectionStatus = 'connected';
        currentQR = null;
        currentStatusMessage = 'WhatsApp connected and online';
        dailyCount = 0;
        hourlyCount = 0;
        log('WhatsApp connected');
    });

    client.on('authenticated', () => {
        currentStatusMessage = 'Authenticated';
        log('Authenticated');
    });

    client.on('auth_failure', (msg) => {
        connectionStatus = 'failed';
        currentStatusMessage = 'Auth failed: ' + (msg || 'unknown');
        log('Auth failure: ' + msg);
    });

    client.on('disconnected', (reason) => {
        connectionStatus = 'disconnected';
        currentQR = null;
        currentStatusMessage = 'Disconnected: ' + (reason || 'unknown');
        client = null;
        log('Disconnected: ' + reason);
    });

    client.initialize().catch((err) => {
        connectionStatus = 'failed';
        currentStatusMessage = 'Failed: ' + err.message;
        log('Init error: ' + err.message);
    });
}

app.get('/api/status', (req, res) => {
    res.json({
        running: true,
        status: connectionStatus,
        qr: currentQR,
        message: currentStatusMessage,
    });
});

app.post('/api/start', requireAuth, (req, res) => {
    if (connectionStatus === 'connected') {
        return res.json({ success: true, message: 'Already connected' });
    }
    startClient();
    res.json({ success: true, message: 'Starting' });
});

app.post('/api/reconnect', requireAuth, async (req, res) => {
    log('Reconnect requested');
    if (client) {
        try { client.destroy(); } catch (e) {}
        client = null;
    }
    const authDir = path.join(SESSION_PATH, 'LocalAuth');
    if (fs.existsSync(authDir)) {
        try {
            fs.rmSync(authDir, { recursive: true, force: true });
            log('Session cleared');
        } catch (e) {
            log('Failed to clear session: ' + e.message);
        }
    }
    connectionStatus = 'disconnected';
    currentQR = null;
    currentStatusMessage = 'Session cleared, reconnecting...';
    startClient();
    res.json({ success: true, message: 'Session cleared, reconnecting' });
});

app.post('/api/logout', requireAuth, async (req, res) => {
    log('Logout requested');
    if (client) {
        try { client.destroy(); } catch (e) {}
        client = null;
    }
    const authDir = path.join(SESSION_PATH, 'LocalAuth');
    if (fs.existsSync(authDir)) {
        try {
            fs.rmSync(authDir, { recursive: true, force: true });
            log('Session cleared');
        } catch (e) {
            log('Failed to clear session: ' + e.message);
        }
    }
    connectionStatus = 'disconnected';
    currentQR = null;
    currentStatusMessage = 'Logged out';
    res.json({ success: true, message: 'Logged out' });
});

app.post('/api/send-test', requireAuth, async (req, res) => {
    const { number, message } = req.body;
    if (!number || !message) {
        return res.status(400).json({ error: 'Number and message required' });
    }
    if (connectionStatus !== 'connected' || !client) {
        return res.status(400).json({ error: 'WhatsApp not connected' });
    }
    const clean = formatNumber(number);
    try {
        const msg = await client.sendMessage(clean, message);
        log('Test sent to ' + clean + ' (id: ' + msg.id.id + ')');
        res.json({ success: true, message: 'Test message sent', messageId: msg.id.id });
    } catch (err) {
        log('Test send error to ' + clean + ': ' + err.message);
        res.status(500).json({ error: err.message });
    }
});

function sanitize(str) {
    if (typeof str !== 'string') return '';
    return str.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F]/g, '').trim();
}

function validatePhone(raw) {
    const cleaned = raw.toString().replace(/[^0-9]/g, '');
    return cleaned.length >= 7 && cleaned.length <= 15;
}

app.post('/api/send', requireAuth, async (req, res) => {
    const { activityTitle, targetNumbers, messageBody, courseName, activityUrl } = req.body;

    if (!activityTitle || !targetNumbers || !Array.isArray(targetNumbers) || targetNumbers.length === 0) {
        return res.status(400).json({ error: 'Invalid payload' });
    }
    if (connectionStatus !== 'connected' || !client) {
        return res.status(400).json({ error: 'WhatsApp not connected' });
    }

    const validPhones = targetNumbers.filter(p => validatePhone(p));
    if (validPhones.length === 0) {
        return res.status(400).json({ error: 'No valid phone numbers' });
    }

    resetCounters();

    let msg;
    if (messageBody) {
        msg = sanitize(messageBody);
    } else {
        const link = activityUrl ? '\n' + activityUrl : '';
        msg = '*EduNotify*\n\n*Course:* ' + sanitize(courseName || 'Course') + '\n*Item:* ' + sanitize(activityTitle) + link + '\n\nPlease log into the portal to view.';
    }

    if (!msg || msg.length > 4096) {
        return res.status(400).json({ error: 'Message empty or too long' });
    }

    let sent = 0;
    let failed = [];
    let rateLimited = false;
    let index = 0;

    for (let phone of validPhones) {
        resetCounters();

        if (rateLimited) {
            failed.push({ phone, error: 'Rate limited' });
            continue;
        }

        if (dailyCount >= DAILY_LIMIT) {
            log('Daily limit reached (' + DAILY_LIMIT + '). Stopping.');
            failed.push({ phone, error: 'Daily limit reached' });
            continue;
        }
        if (hourlyCount >= HOURLY_LIMIT) {
            log('Hourly limit reached (' + HOURLY_LIMIT + '). Pausing 1 hour.');
            await delay(3600000);
            resetCounters();
        }

        const clean = formatNumber(phone);

        let success = false;
        for (let attempt = 1; attempt <= 3; attempt++) {
            try {
                const result = await client.sendMessage(clean, msg);
                sent++;
                dailyCount++;
                hourlyCount++;
                success = true;
                log('Sent to ' + clean + ' (#' + sent + '/day, attempt ' + attempt + ')');
                break;
            } catch (err) {
                const errMsg = (err.message || '').toLowerCase();
                if (errMsg.includes('rate') || errMsg.includes('too many') || errMsg.includes('429') || errMsg.includes('wait')) {
                    log('Rate limited on ' + clean + '. Pausing.');
                    rateLimited = true;
                    await delay(BATCH_PAUSE_MS);
                    break;
                }
                log('Send error to ' + clean + ' (attempt ' + attempt + '/3): ' + err.message);
                if (attempt < 3) {
                    await delay(jitter(5000));
                }
            }
        }

        if (!success) {
            failed.push({ phone: clean, error: 'Failed after 3 attempts' });
        }

        index++;
        if (index < validPhones.length) {
            await delay(8000 + Math.random() * 12000);
        }
    }

    log('Sent ' + sent + '/' + validPhones.length + ' recipients (daily: ' + dailyCount + ')');
    res.json({ success: true, sent, total: validPhones.length, failed: failed.length > 0 ? failed : undefined });
});

const server = app.listen(PORT, '127.0.0.1', () => {
    log('API running on port ' + PORT);
    startClient();
});

function shutdown() {
    log('Shutting down');
    if (client) {
        try { client.destroy(); } catch (e) {}
    }
    server.close();
    process.exit(0);
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
