# AIMS Field Distribution (Windows)

Desktop client for distributing relief-campaign aid of the Sponsorships & Orphans department. It works online for preparation and up to 168 hours offline.

## Build

Prerequisites: Windows 10/11 x64 and Node.js 20 or later.

```powershell
cd field_client
npm ci            # first time: npm install (downloads Electron 33.2.1 and electron-builder 25.1.8)
npm start         # run from source
npm run dist      # NSIS installer -> dist\aims-field-setup-1.0.0.exe
```

### Code signing

No certificate is included. To sign the installer:
1. Set `CSC_LINK` to the institution's `.pfx` path.
2. Set `CSC_KEY_PASSWORD` to its password.
3. Run `npm run dist`.

electron-builder then signs both the app and the installer. Distribute only signed builds, and keep the certificate off field devices.

## First use

1. **Pin the server key.** Enter the AIMS address (https only). The client shows the server key id. Compare it with the id shown on «أجهزة التوزيع الميداني» in AIMS, then pin it. Manifests signed by any other key are refused.
2. **Register the device.** Sign in with your employee number and password while online. The device creates its own Ed25519 key, proves possession, and is registered as *pending*.
3. **Get the device approved.** The head of Sponsorships & Orphans compares the key fingerprint on the device with the one on the devices page and approves it. Sign in again, then set a 6–12 digit lock PIN.
4. **Download a batch.** Download the batch the head assigned to you. Its signed manifest is valid offline for at most 168 hours from issue.

## Field rules enforced by the client (and again by the server)

- **Fixed beneficiary list.** Only the beneficiaries and quantities in the signed manifest can be served. No adding people, no raising quantities.
- **«تم التسليم» needs all of the following:**
  - the registered guardian in person (a proxy is recorded as a referral);
  - the last 4 digits of the presented document matching the manifest;
  - the distributor's attestation;
  - the recipient's handwritten signature on the pad.
- **Pending until confirmed.** Each record is first stored locally as *pending sync*. It counts as delivered only after the server confirms it.
- **Failure reasons.** Failure, refusal, damage or an identity problem is recorded with its reason, never as a delivery.
- **Offline window.** After 168 hours, or the shorter limit set in AIMS, no new offline action is possible: reconnect and download again.

## Security model

- **Encrypted vault.**
  - Data is stored in `%APPDATA%\AIMS Field Distribution\vault`, sealed with AES-256-GCM per record and bound to the record name.
  - The data key is wrapped by Windows DPAPI (Electron `safeStorage`). Copying the folder to another user or machine makes it unreadable.
  - No identity list, signature, token or PIN is ever written in clear, and nothing is logged.
- **Device key.**
  - Each device holds a private Ed25519 key inside the vault.
  - Every event is signed and hash-chained to the previous one. The server rejects events not signed by the registered device and holds chain gaps for review.
- **Offline clock.** Elapsed time is the larger of the server-corrected wall clock and a monotonic counter (OS uptime) persisted in the vault. Reopening the app or moving the clock back does not extend the window; a clock moved back is flagged and sent with each event.
  - **Residual risk:** on an unmanaged device that is switched off, has its clock set back, then rebooted, the time spent powered off can be under-counted. Revocation reaches the device only at its next connection. 168 hours remains a hard upper bound, not guaranteed access after compromise. Use managed devices (BitLocker, a standard user account, Windows time sync locked by policy).
- **Locking.**
  - The device locks after inactivity (the lock minutes set in AIMS) and at start.
  - Five wrong PINs end the session and delete downloaded manifests. Unsynced events are kept and uploaded after the next online sign-in.
- **Window protection.** The page has no Node access (context isolation and sandbox) and cannot navigate. Camera and location are disabled, and the window is excluded from screen capture.
- **Sync.**
  - Retries are idempotent (event UUID).
  - The server validates signatures, the manifest version and its offline expiry, quantities, the identity digits, the signature hash, and clock plausibility.
  - Doubtful events are held for the head's review, never dropped.
