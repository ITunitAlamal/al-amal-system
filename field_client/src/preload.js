'use strict';

// The only bridge the page can use: named calls, plain data in and out.
const { contextBridge, ipcRenderer } = require('electron');

const call = (channel, ...args) => ipcRenderer.invoke(channel, ...args);

contextBridge.exposeInMainWorld('field', {
    status: () => call('field:status'),
    probe: url => call('field:probe', url),
    pinServer: (url, key, keyId) => call('field:pin-server', url, key, keyId),
    signIn: (employeeNumber, password) => call('field:sign-in', employeeNumber, password),
    setPin: pin => call('field:set-pin', pin),
    unlock: pin => call('field:unlock', pin),
    lock: () => call('field:lock'),
    batches: () => call('field:batches'),
    download: id => call('field:download', id),
    batch: id => call('field:batch', id),
    record: (batchId, allocationId, input) => call('field:record', batchId, allocationId, input),
    sync: () => call('field:sync'),
    signOut: () => call('field:sign-out'),
    activity: () => call('field:activity'),
    onLocked: handler => ipcRenderer.on('field:locked', () => handler()),
});
