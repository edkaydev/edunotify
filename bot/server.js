const express = require('express');
const { Client, LocalAuth } = require('whatsapp-web.js');
const QRCode = require('qrcode');
const path = require('path');
const fs = require('fs');

const PORT = parseInt(process.env.EDUNOTIFY_BOT_PORT || '5001', 10);
const SESSION_PATH = process.env.EDUNOTIFY_SESSION_PATH || path.join(__dirname, 'session');
const API_KEY = process.env.EDUNOTIFY_API_KEY || process.env.WANOTIFY_API_KEY || '';
const COUNTRY_CODE = process.env.COUNTRY_CODE || '256';
const BATCH_SIZE = parseInt(process.env.EDUNOTIFY_BATCH_SIZE || '25', 10);
const BATCH_PAUSE_MS = parseInt(process.env.EDUNOTIFY_BATCH_PAUSE_MS || '60000', 10);
const TEMPLATE = process.env.EDUNOTIFY_TEMPLATE || '';
const OPTOUT_URL = process.env.EDUNOTIFY_OPTOUT_URL || '';

if (!API_KEY) {
    console.error('[FATAL] EDUNOTIFY_API_KEY is not set. The bot will not start without an API key.');
    process.exit(1);
}

const app = express();
app.use(express.json({ limit: '1mb' }));

let client = null;
let connectionStatus = 'disconnected';
let currentQR = null;
let currentStatusMessage = 'Bot initialized';

function log(msg) {
    const ts = new Date().toISOString().replace('T', ' ').slice(0, 19);
    console.log(`[${ts}] ${msg}`);
}

function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function requireAuth(req, res, next) {
    const key = req.headers['x-api-key'];
    if (!key || key !== API_KEY) {
        return res.status(401).json({ error: 'Unauthorized' });
    }
    next();
}

function formatNumber(raw) {
    let clean = raw.trim().replace(/[\s\-\+\(\)]/g, '');
    if (clean.startsWith('0')) {
        clean = COUNTRY_CODE + clean.substring(1);
    }
    if (!clean.endsWith('@c.us')) {
        clean = clean + '@c.us';
    }
    return clean;
}

function startClient() {
    if (client) {
        try { client.destroy(); } catch (e) {}
        client = null;
    }

    connectionStatus = 'initializing';
    currentQR = null;
    currentStatusMessage = 'Starting WhatsApp client...';
    log('Starting WhatsApp client');

    process.env.CHROME_CRASHPAD_HANDLER = '';

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
    const chromePath = process.env.PUPPETEER_EXECUTABLE_PATH;
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
        res.json({
            success: true,
            message: 'Test message sent',
            messageId: msg.id.id,
        });
    } catch (err) {
        log('Test send error to ' + clean + ': ' + err.message);
        res.status(500).json({ error: err.message });
    }
});

app.post('/api/send', requireAuth, async (req, res) => {
    const { courseName, activityTitle, activityUrl, actionType, instructor, targetNumbers, messageBody } = req.body;

    if (!activityTitle || !targetNumbers || !Array.isArray(targetNumbers)) {
        return res.status(400).json({ error: 'Invalid payload' });
    }
    if (connectionStatus !== 'connected' || !client) {
        return res.status(400).json({ error: 'WhatsApp not connected' });
    }

    let msg;
    if (messageBody) {
        msg = messageBody;
    } else if (TEMPLATE) {
        msg = TEMPLATE
            .replace(/{{courseName}}/g, courseName || 'Course')
            .replace(/{{activityTitle}}/g, activityTitle)
            .replace(/{{activityUrl}}/g, activityUrl || '')
            .replace(/{{actionType}}/g, actionType || 'Update')
            .replace(/{{instructor}}/g, instructor || 'Faculty')
            .replace(/{{optoutUrl}}/g, OPTOUT_URL || '');
    } else {
        const link = activityUrl ? '\n' + activityUrl : '';
        msg = '📢 *EduNotify* 📢\n\n' +
            '*Course:* ' + (courseName || 'Course') + '\n' +
            '*Instructor:* ' + (instructor || 'Faculty') + '\n' +
            '*Action:* ' + (actionType || 'Update') + '\n' +
            '*Item:* ' + activityTitle +
            link + '\n\n' +
            'Please log into the portal to view.\n' +
            (OPTOUT_URL ? '\nTo opt out: ' + OPTOUT_URL : '');
    }

    let sent = 0;
    let failed = [];
    let index = 0;

    for (let phone of targetNumbers) {
        const clean = formatNumber(phone);

        let success = false;
        let messageId = null;
        for (let attempt = 1; attempt <= 3; attempt++) {
            try {
                const result = await client.sendMessage(clean, msg);
                sent++;
                success = true;
                messageId = result.id ? result.id.id : null;
                log('Sent to ' + clean + ' (attempt ' + attempt + ', id: ' + messageId + ')');

                if (messageId) {
                    client.on('message_ack', (ackMsg) => {
                        if (ackMsg.id.id === messageId) {
                            log('Delivery update for ' + clean + ': ' + ackMsg.ack);
                        }
                    });
                }
                break;
            } catch (err) {
                log('Send error to ' + clean + ' (attempt ' + attempt + '/3): ' + err.message);
                if (attempt < 3) {
                    await delay(3000);
                }
            }
        }

        if (!success) {
            failed.push({ phone: clean, error: 'Failed after 3 attempts' });
        }

        index++;
        if (index % BATCH_SIZE === 0 && index < targetNumbers.length) {
            const remaining = targetNumbers.length - index;
            log('Batch of ' + BATCH_SIZE + ' done. Pausing ' + (BATCH_PAUSE_MS / 1000) + 's. ' + remaining + ' remaining.');
            await delay(BATCH_PAUSE_MS);
        } else if (index < targetNumbers.length) {
            await delay(2000 + Math.random() * 3000);
        }
    }

    log('Notification sent to ' + sent + '/' + targetNumbers.length + ' recipients');
    res.json({ success: true, sent, total: targetNumbers.length, failed: failed.length > 0 ? failed : undefined });
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
