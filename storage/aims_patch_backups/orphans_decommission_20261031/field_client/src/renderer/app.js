'use strict';

// Screens of the field client (Arabic RTL). Data comes only from the main process through window.field.
const app = document.getElementById('app');
const toastBox = document.getElementById('toast');
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const STATE_LABELS = { LocallyRecordedPendingSync: ['مسجل محليًا — بانتظار الرفع', 'warn'], UploadedPendingValidation: ['مرفوع — بانتظار التحقق', 'warn'],
    ConfirmedAndReconciled: ['مؤكد من الخادم', 'ok'], ConflictHeld: ['معلق لتعارض', 'bad'], RejectedWithReason: ['مرفوض', 'bad'] };
let current = { batch: null, entry: null, pad: null };

function toast(message, ms = 5000) {
    toastBox.textContent = message;
    toastBox.hidden = false;
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => { toastBox.hidden = true; }, ms);
}
async function run(promise) {
    const result = await promise;
    if (!result.ok) {
        toast(result.error);
        throw new Error(result.error);
    }
    return result.data;
}
function duration(ms) {
    const hours = Math.floor(ms / 3600000);
    const minutes = Math.floor((ms % 3600000) / 60000);
    return `${hours} ساعة و${minutes} دقيقة`;
}
['click', 'keydown', 'pointerdown'].forEach(type => document.addEventListener(type, () => window.field.activity(), { passive: true }));
window.field.onLocked(() => render());
document.getElementById('btn-sync').addEventListener('click', sync);
document.getElementById('btn-lock').addEventListener('click', async () => { await window.field.lock(); render(); });
document.getElementById('btn-signout').addEventListener('click', signOut);

async function render() {
    const status = await run(window.field.status());
    document.getElementById('status-line').textContent = status.session
        ? `${status.session.user} — ${status.session.online ? 'جلسة متصلة صالحة' : 'الجلسة المتصلة منتهية (العمل دون اتصال مستمر حتى انتهاء نافذة كل دفعة)'} — غير مرفوع: ${status.pending}`
        : `بصمة الجهاز ${status.fingerprint}`;
    ['btn-sync', 'btn-lock', 'btn-signout'].forEach(id => { document.getElementById(id).hidden = !status.session || status.locked; });
    if (!status.configured) return setupScreen();
    if (!status.session) return loginScreen(status);
    if (!status.hasPin) return pinScreen(true);
    if (status.locked) return pinScreen(false);
    if (current.batch) return batchScreen(current.batch);
    return homeScreen(status);
}

function setupScreen() {
    app.innerHTML = `<section class="card"><h1>إعداد الاتصال بالنظام</h1>
        <label>عنوان النظام (https)<input id="url" class="ltr" placeholder="https://aims.example.org" autocomplete="url"></label>
        <button class="primary" id="probe">التحقق من الخادم</button><div id="probe-result"></div></section>`;
    document.getElementById('probe').onclick = async () => {
        const url = document.getElementById('url').value.trim();
        const server = await run(window.field.probe(url));
        document.getElementById('probe-result').innerHTML = `<p>بصمة مفتاح الخادم: <strong class="ltr">${esc(server.keyId)}</strong></p>
            <p class="muted">قارنها بالبصمة الظاهرة في صفحة «أجهزة التوزيع الميداني» في النظام قبل التثبيت.</p><button class="primary" id="pin-server">البصمة مطابقة — تثبيت</button>`;
        document.getElementById('pin-server').onclick = async () => { await run(window.field.pinServer(url, server.publicKey, server.keyId)); render(); };
    };
}

function loginScreen(status) {
    app.innerHTML = `<section class="card"><h1>تسجيل الدخول (يتطلب اتصالًا)</h1>
        <label>الرقم الوظيفي<input id="emp" autocomplete="username"></label><label>كلمة المرور<input id="pwd" type="password" autocomplete="current-password"></label>
        <button class="primary" id="login">دخول</button><p class="muted">بصمة هذا الجهاز: <span class="ltr">${esc(status.fingerprint)}</span>. يعتمد رئيس القسم الجهاز عند أول تسجيل.</p></section>`;
    document.getElementById('login').onclick = async () => {
        const result = await run(window.field.signIn(document.getElementById('emp').value, document.getElementById('pwd').value));
        document.getElementById('pwd').value = '';
        if (result.status !== 'approved') {
            toast(result.status === 'pending' ? `الجهاز بانتظار اعتماد رئيس القسم (البصمة ${result.fingerprint}).` : 'الجهاز ملغى أو مفقود؛ راجع رئيس القسم.', 9000);
            return;
        }
        render();
    };
}

function pinScreen(create) {
    app.innerHTML = `<section class="card"><h1>${create ? 'إنشاء رمز قفل الجهاز' : 'الجهاز مقفل'}</h1>
        <label>${create ? 'رمز من 6 إلى 12 رقمًا' : 'رمز القفل'}<input id="pin" type="password" inputmode="numeric" autocomplete="off"></label>
        <button class="primary" id="go">${create ? 'حفظ الرمز' : 'فتح'}</button></section>`;
    const go = async () => {
        const pin = document.getElementById('pin').value;
        await run(create ? window.field.setPin(pin) : window.field.unlock(pin));
        render();
    };
    document.getElementById('go').onclick = go;
    document.getElementById('pin').addEventListener('keydown', e => { if (e.key === 'Enter') go(); });
    document.getElementById('pin').focus();
}

async function homeScreen(status) {
    const downloaded = status.manifests.map(m => `<tr class="pick" tabindex="0" data-batch="${esc(m.id)}"><td>${esc(m.reference)}</td><td>${esc(m.campaign.reference)} — ${esc(m.campaign.title)}</td>
        <td>${m.entries.length}</td><td class="${m.clock.expired ? 'bad' : 'ok'} countdown">${m.clock.expired ? 'انتهت صلاحية العمل دون اتصال' : 'متبقٍ ' + duration(m.clock.remainingMs)}</td></tr>`).join('');
    const counts = Object.entries(status.outbox).map(([state, n]) => `<span class="pill ${STATE_LABELS[state]?.[1] || ''}">${esc(STATE_LABELS[state]?.[0] || state)}: ${n}</span>`).join(' ');
    app.innerHTML = `<section class="card"><h1>الدفعات المنزلة على الجهاز</h1>
        <table><thead><tr><th>الدفعة</th><th>الحملة</th><th>المستفيدون</th><th>العمل دون اتصال</th></tr></thead><tbody>${downloaded || '<tr><td colspan="4" class="muted">لا توجد دفعات منزلة.</td></tr>'}</tbody></table></section>
        <section class="card"><h2>الأحداث في صندوق الإرسال</h2><div class="row">${counts || '<span class="muted">لا توجد أحداث.</span>'}</div>
        <p class="muted">لا يُحذف أي حدث قبل تأكيد الخادم استلامه. الإجماليات الرسمية تحسب الأحداث المؤكدة فقط.</p></section>
        <section class="card"><h2>الدفعات المسندة إليك (يتطلب اتصالًا)</h2><button id="load">عرض الدفعات</button><div id="assigned"></div></section>`;
    app.querySelectorAll('[data-batch]').forEach(row => {
        const open = () => { current.batch = row.dataset.batch; render(); };
        row.onclick = open;
        row.onkeydown = e => { if (e.key === 'Enter') open(); };
    });
    document.getElementById('load').onclick = async () => {
        const batches = await run(window.field.batches());
        document.getElementById('assigned').innerHTML = `<table><thead><tr><th>الدفعة</th><th>الحملة</th><th>المستفيدون</th><th>الحالة</th><th></th></tr></thead><tbody>${batches.map(b => `<tr><td>${esc(b.reference_no)}</td>
            <td>${esc(b.campaign)} — ${esc(b.title)}</td><td>${esc(b.allocations)}</td><td>${esc(b.status)}</td>
            <td>${['assigned', 'downloaded'].includes(b.status) ? `<button data-download="${esc(b.public_id)}">تنزيل</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="5" class="muted">لا توجد دفعات.</td></tr>'}</tbody></table>`;
        document.querySelectorAll('[data-download]').forEach(button => {
            button.onclick = async () => { await run(window.field.download(button.dataset.download)); toast('نُزّلت الدفعة وتحقق توقيعها؛ تبدأ نافذة العمل دون اتصال من وقت الإصدار.'); render(); };
        });
    };
}

async function batchScreen(batchId) {
    const batch = await run(window.field.batch(batchId));
    app.innerHTML = `<section class="card"><div class="row"><button id="back">→ الدفعات</button><h1>${esc(batch.reference)} — ${esc(batch.campaign.title)}</h1></div>
        <p class="${batch.clock.expired ? 'bad' : 'ok'} countdown">${batch.clock.expired ? 'انتهت صلاحية العمل دون اتصال: لا تُسجل تسليمات جديدة. اتصل وأعد التنزيل.' : 'صلاحية العمل دون اتصال: متبقٍ ' + duration(batch.clock.remainingMs)}</p>
        ${batch.clock.tamper?.length ? '<p class="bad">رُصد تغيير في ساعة الجهاز؛ ستُراجع الأحداث على الخادم.</p>' : ''}
        <label>بحث بالمرجع المقنّع أو الاسم<input id="search" autocomplete="off"></label>
        <table><thead><tr><th>المرجع</th><th>المستلم المعتمد</th><th>المنطقة</th><th>الحالة على الجهاز</th></tr></thead><tbody id="rows"></tbody></table></section><section id="entry"></section>`;
    document.getElementById('back').onclick = () => { current.batch = null; render(); };
    const draw = term => {
        const t = term.trim();
        document.getElementById('rows').innerHTML = batch.entries.filter(e => !t || [e.reference, e.pseudonym, e.receiver.name, e.child_first_name].some(v => String(v || '').includes(t)))
            .map(e => `<tr class="pick" tabindex="0" data-entry="${esc(e.allocation)}"><td><span class="ltr">${esc(e.reference)}</span><br><span class="muted ltr">${esc(e.pseudonym)}</span></td>
                <td>${esc(e.receiver.name)}${e.child_first_name ? `<br><span class="muted">الطفل: ${esc(e.child_first_name)}</span>` : ''}</td><td>${esc(e.area.join(' — '))}</td>
                <td>${e.local.complete ? '<span class="pill ok">مُسلَّم محليًا</span>' : e.local.lastState ? `<span class="pill ${STATE_LABELS[e.local.lastState]?.[1]}">${esc(STATE_LABELS[e.local.lastState]?.[0])}</span>` : '<span class="muted">لم يُسلَّم</span>'}</td></tr>`).join('');
        document.querySelectorAll('[data-entry]').forEach(row => {
            const open = () => entryPanel(batch, batch.entries.find(e => e.allocation === row.dataset.entry));
            row.onclick = open;
            row.onkeydown = e => { if (e.key === 'Enter') open(); };
        });
    };
    document.getElementById('search').oninput = e => draw(e.target.value);
    draw('');
}

function entryPanel(batch, entry) {
    const box = document.getElementById('entry');
    box.innerHTML = `<div class="card"><h2>${esc(entry.receiver.name)} <span class="muted ltr">${esc(entry.reference)}</span></h2>
        <p class="muted">${esc(entry.area.join(' — '))}${entry.landmark ? ' — ' + esc(entry.landmark) : ''}</p>
        <div class="grid">${entry.items.map(i => `<label>${esc(i.label)} (${esc(i.unit)}) — المتبقي ${entry.local.remaining[i.id]}<input type="number" min="0" step="0.01" max="${entry.local.remaining[i.id]}" data-item="${esc(i.id)}" value="${entry.local.remaining[i.id]}"></label>`).join('')}</div>
        <h2>التحقق من هوية المستلم</h2>
        <div class="grid"><label>صفة المستلم<select id="relation"><option value="guardian">الوصي المسجل نفسه</option><option value="proxy">شخص آخر (مفوض)</option></select></label>
            <label>آخر 4 أرقام من الوثيقة المقدمة<input id="last4" inputmode="numeric" maxlength="4" autocomplete="off"></label>
            <label>نوع الوثيقة<select id="method"><option value="id_card">بطاقة الهوية</option><option value="other_document">وثيقة رسمية أخرى</option></select></label></div>
        <label><input type="checkbox" id="attested"> أقر بأنني طابقت الوثيقة الأصلية مع المستلم المعتمد شخصيًا</label>
        <h2>توقيع المستلم بخط اليد</h2><p class="muted">بإقرار المستلم باستلام البنود والكميات أعلاه. توقيع مرسوم إلكترونيًا، لا توقيعًا رقميًا معتمدًا.</p>
        <canvas class="signature" id="pad" aria-label="مساحة التوقيع"></canvas><div class="row"><button id="clear">مسح التوقيع</button></div>
        <label>ملاحظة قصيرة (اختياري)<input id="note" maxlength="500"></label>
        <div class="row"><button class="primary" id="deliver"${batch.clock.expired || entry.local.complete ? ' disabled' : ''}>تم التسليم</button></div>
        <h2>عدم التسليم أو الإحالة</h2>
        <div class="row"><select id="reason">${batch.failureReasons.map(r => `<option value="${esc(r.code)}">${esc(r.label)}</option>`).join('')}</select>
            <button id="not-delivered"${batch.clock.expired ? ' disabled' : ''}>لم يُسلَّم</button><button id="referral" class="danger"${batch.clock.expired ? ' disabled' : ''}>إحالة للمراجعة</button></div></div>`;
    const pad = window.SignaturePad(document.getElementById('pad'));
    document.getElementById('clear').onclick = () => pad.clear();
    const record = async (kind) => {
        const items = Object.fromEntries([...box.querySelectorAll('[data-item]')].map(input => [input.dataset.item, Number(input.value || 0)]));
        const input = { kind, items, relation: document.getElementById('relation').value, idLast4: document.getElementById('last4').value.trim(),
            method: document.getElementById('method').value, attested: document.getElementById('attested').checked, note: document.getElementById('note').value,
            reason: document.getElementById('reason').value, signaturePng: kind === 'delivered' && !pad.isEmpty() ? pad.toDataURL() : null };
        const result = await run(window.field.record(batch.id, entry.allocation, input));
        pad.clear();
        toast(result.kind === 'partial' ? 'سُجّل تسليم جزئي محليًا؛ بانتظار المزامنة.' : result.kind === 'delivered' ? 'سُجّل «تم التسليم» محليًا؛ لا يُحتسب قبل تأكيد الخادم.' : 'سُجّلت النتيجة محليًا؛ بانتظار المزامنة.');
        batchScreen(batch.id);
    };
    document.getElementById('deliver').onclick = () => record('delivered');
    document.getElementById('not-delivered').onclick = () => record('not_delivered');
    document.getElementById('referral').onclick = () => record('referral');
    box.scrollIntoView({ behavior: 'smooth' });
}

async function sync() {
    try {
        const result = await run(window.field.sync());
        const counts = result.results.reduce((c, r) => ({ ...c, [r.status]: (c[r.status] || 0) + 1 }), {});
        toast(result.sent ? `رُفع ${result.sent}: مؤكد ${counts.confirmed || 0}، معلق ${counts.conflict_held || 0}، مرفوض ${counts.rejected || 0}.` : 'لا توجد أحداث للرفع.', 8000);
    } finally {
        render();
    }
}

async function signOut() {
    if (!window.confirm('تسجيل الخروج يحذف بيانات الدفعات من الجهاز ويبقي الأحداث غير المرفوعة محفوظة مشفرة. متابعة؟')) return;
    const result = await run(window.field.signOut());
    current = { batch: null, entry: null, pad: null };
    if (result.pending) toast(`بقي ${result.pending} حدث غير مرفوع محفوظًا؛ سجّل الدخول متصلًا لرفعه.`, 9000);
    render();
}

render();
