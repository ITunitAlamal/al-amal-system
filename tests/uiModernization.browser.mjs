// Isolated real-component smoke test. No Laravel server, database or production records.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwind from '@tailwindcss/vite';

const root = process.cwd();
const artifacts = path.join(root, 'storage/app/ui-modernization-browser');
await mkdir(artifacts, { recursive: true });
const server = await createServer({ configFile: false, root: path.join(root, 'tests/Fixtures/ui-modernization'), publicDir: path.join(root, 'public'), plugins: [vue(), tailwind()], server: { host: '127.0.0.1', port: 5198, strictPort: true, fs: { allow: [root] } } });
await server.listen();
const chrome = spawn(process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=9238', `--user-data-dir=${path.join(artifacts, 'chrome-profile')}`, 'about:blank'], { windowsHide: true, stdio: 'ignore' });
let socket;
try {
    let targets;
    for (let attempt = 0; attempt < 60; attempt++) {
        try { targets = await (await fetch('http://127.0.0.1:9238/json')).json(); if (targets.some(t => t.type === 'page')) break; } catch {}
        await new Promise(resolve => setTimeout(resolve, 250));
    }
    const target = targets?.find(t => t.type === 'page');
    assert.ok(target, 'Chrome debugging endpoint available');
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    let id = 0;
    const waiting = new Map();
    const errors = [];
    socket.onmessage = ({ data }) => {
        const message = JSON.parse(data);
        if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
        if (message.id) { const request = waiting.get(message.id); waiting.delete(message.id); message.error ? request.reject(message.error) : request.resolve(message.result); }
    };
    const send = (method, params = {}) => new Promise((resolve, reject) => { const key = ++id; waiting.set(key, { resolve, reject }); socket.send(JSON.stringify({ id: key, method, params })); });
    const evaluate = async expression => {
        const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        assert.ok(!result.exceptionDetails, JSON.stringify(result.exceptionDetails));
        return result.result.value;
    };
    await send('Runtime.enable');
    await send('Page.enable');
    await send('Page.navigate', { url: 'http://127.0.0.1:5198/' });
    for (let attempt = 0; attempt < 120; attempt++) {
        if (await evaluate("!!document.querySelector('#preview')")) break;
        await new Promise(resolve => setTimeout(resolve, 250));
    }
    assert.ok(await evaluate("!!document.querySelector('#preview')"), 'Vue app mounted');
    await evaluate('document.fonts.ready.then(() => true)');
    assert.equal(await evaluate("getComputedStyle(document.querySelector('.aims-form-steps ol')).display"), 'flex', 'Production Tailwind utilities loaded');
    const results = [];
    for (const width of [390, 768, 1440]) {
        await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 600 });
        await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
        const dimensions = await evaluate('({ viewport: innerWidth, page: document.documentElement.scrollWidth, rtl: getComputedStyle(document.documentElement).direction })');
        assert.ok(dimensions.page <= dimensions.viewport + 1, `No horizontal page overflow at ${width}: ${JSON.stringify(dimensions)}`);
        assert.equal(dimensions.rtl, 'rtl');
        await evaluate("document.querySelector('#preview').focus(); document.querySelector('#preview').click()");
        await evaluate('new Promise(resolve => requestAnimationFrame(resolve))');
        assert.ok(await evaluate("document.querySelector('dialog').matches(':modal') && document.querySelector('dialog').contains(document.activeElement)"), 'Native modal opens and contains focus');
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
        assert.ok(await evaluate("document.querySelector('dialog').contains(document.activeElement)"), 'Tab stays in modal');
        const screenshot = await send('Page.captureScreenshot', { format: 'png' });
        await writeFile(path.join(artifacts, `dialog-${width}.png`), Buffer.from(screenshot.data, 'base64'));
        await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
        await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
        assert.ok(await evaluate("!document.querySelector('dialog').open && document.activeElement.id === 'preview' && document.body.style.overflow !== 'hidden'"), 'Escape restores focus and scrolling');
        const page = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
        await writeFile(path.join(artifacts, `components-${width}.png`), Buffer.from(page.data, 'base64'));
        results.push({ width, ...dimensions, modal: 'passed', keyboard: 'passed' });
    }
    assert.deepEqual(errors, [], 'No uncaught browser exceptions');
    await writeFile(path.join(artifacts, 'results.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify(results, null, 2));
} finally {
    socket?.close();
    chrome.kill();
    await server.close();
}
