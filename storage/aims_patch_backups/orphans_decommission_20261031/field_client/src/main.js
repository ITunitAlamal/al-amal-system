'use strict';

// Electron main process: one window, no Node in the page (context isolation + sandbox), no navigation, no extra
// windows, no device permissions (camera/location stay off), content protected from screen capture, and an
// inactivity lock. All data handling runs here through the core modules; the page only calls the narrow bridge.
const { app, BrowserWindow, ipcMain, Menu, safeStorage, session } = require('electron');
const os = require('os');
const path = require('path');
const { Vault } = require('./core/vault');
const { FieldService } = require('./core/fieldService');
const { TICK_MS } = require('./core/offlineClock');

if (!app.requestSingleInstanceLock()) {
    app.quit();
}

let service = null;
let window = null;
let lockTimer = null;

function protector() {
    if (!safeStorage.isEncryptionAvailable()) {
        throw new Error('حماية التخزين في نظام التشغيل غير متاحة؛ لا يعمل التطبيق دونها.');
    }
    return {
        encrypt: buffer => safeStorage.encryptString(buffer.toString('base64')),
        decrypt: buffer => Buffer.from(safeStorage.decryptString(buffer), 'base64'),
    };
}

function lockMinutes() {
    try {
        const minutes = service.status().manifests.map(m => m.policy?.lock_minutes).filter(Boolean);
        return Math.max(1, Math.min(60, minutes.length ? Math.min(...minutes) : 5));
    } catch {
        return 5;
    }
}

function touch() {
    clearTimeout(lockTimer);
    lockTimer = setTimeout(() => {
        service.lock();
        window?.webContents.send('field:locked');
    }, lockMinutes() * 60_000);
}

function handle(channel, fn) {
    ipcMain.handle(channel, async (_event, ...args) => {
        try {
            touch();
            return { ok: true, data: await fn(...args) };
        } catch (error) {
            // Only the message reaches the page; no payload, identity or stack is logged.
            return { ok: false, error: error.message || 'خطأ غير متوقع', status: error.status ?? null };
        }
    });
}

app.whenReady().then(() => {
    Menu.setApplicationMenu(null);
    session.defaultSession.setPermissionRequestHandler((_contents, _permission, callback) => callback(false));
    session.defaultSession.webRequest.onHeadersReceived((details, callback) => callback({
        responseHeaders: { ...details.responseHeaders, 'Content-Security-Policy': ["default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'none'"] },
    }));
    const vault = new Vault(path.join(app.getPath('userData'), 'vault'), protector());
    service = new FieldService(vault, { name: os.hostname().slice(0, 100), platform: `${process.platform} ${os.release()}`, version: app.getVersion() });

    handle('field:status', () => service.status());
    handle('field:probe', url => service.probeServer(url));
    handle('field:pin-server', (url, key, keyId) => service.pinServer(url, key, keyId));
    handle('field:sign-in', (employeeNumber, password) => service.signIn(String(employeeNumber), String(password)));
    handle('field:set-pin', pin => service.setPin(String(pin)));
    handle('field:unlock', pin => service.unlock(String(pin)));
    handle('field:lock', () => service.lock());
    handle('field:batches', () => service.listBatches());
    handle('field:download', id => service.download(String(id)));
    handle('field:batch', id => service.batchSummary(String(id)));
    handle('field:record', (batchId, allocationId, input) => service.record(String(batchId), String(allocationId), input || {}));
    handle('field:sync', () => service.sync());
    handle('field:sign-out', () => service.signOut());
    handle('field:activity', () => true);

    window = new BrowserWindow({
        width: 1180, height: 800, minWidth: 900, minHeight: 600, title: 'AIMS — التوزيع الميداني', autoHideMenuBar: true,
        webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, nodeIntegration: false, sandbox: true, devTools: !app.isPackaged, spellcheck: false },
    });
    window.setContentProtection(true);
    window.webContents.on('will-navigate', event => event.preventDefault());
    window.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
    window.loadFile(path.join(__dirname, 'renderer', 'index.html'));
    setInterval(() => {
        // Keeps the offline clocks advancing while the app is open (they also catch up at the next start).
        try {
            service.status();
        } catch {
            // ignored: the next user action reports any problem
        }
    }, TICK_MS);
    touch();
});

app.on('second-instance', () => window?.focus());
app.on('window-all-closed', () => app.quit());
