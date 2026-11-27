<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

const { document, letterhead, files, editionFiles, history, appendix, printAt, backUrl } = defineProps({
    document: Object,
    letterhead: String,
    files: Array,
    editionFiles: Array,
    history: Array,
    appendix: Array,
    printAt: Object,
    backUrl: String,
});

const print = () => window.print();
const actions = {
    draft: 'إنشاء مسودة',
    draft_edit: 'تعديل مسودة',
    issue: 'إصدار',
    read: 'قراءة',
    receive: 'استلام',
    acknowledge: 'إقرار بالاستلام',
    forward: 'إحالة',
    reply: 'رد',
    return: 'إعادة للاستكمال',
    refuse: 'رفض التكليف',
    correction: 'تصحيح موثق',
    complete: 'إنجاز',
    close: 'إغلاق',
    archive: 'أرشفة',
    withdraw: 'سحب',
    workflow: 'إجراء في المسار المؤسسي',
    refer: 'إحالة للمتابعة',
};
const statuses = {
    draft: 'مسودة',
    issued: 'صادرة',
    processing: 'قيد المتابعة',
    processed: 'منجزة',
    closed: 'منتهية',
    archived: 'مؤرشفة',
    withdrawn: 'مسحوبة',
};
const taskStatuses = { open: 'مفتوحة', completed: 'منجزة', cancelled: 'ملغاة' };
let printFooterStyle;

function cssString(value) {
    return `"${Array.from(value, character => {
        const codePoint = character.codePointAt(0);

        return codePoint === 0x22 || codePoint === 0x5c || codePoint < 0x20 || codePoint === 0x7f
            ? `\\${codePoint.toString(16)} `
            : character;
    }).join('')}"`;
}

onMounted(() => {
    const footer = `طُبعت بواسطة: ${printAt.name} | التاريخ: ${printAt.date} | الوقت: ${printAt.time}`;
    printFooterStyle = window.document.createElement('style');
    printFooterStyle.dataset.correspondencePrintFooter = '';
    printFooterStyle.textContent = `@page { @bottom-left { content: ${cssString(footer)}; color: #555; font-size: 7pt; line-height: 1.2; text-align: left; direction: ltr; unicode-bidi: plaintext; } }`;
    window.document.head.append(printFooterStyle);
});

onBeforeUnmount(() => printFooterStyle?.remove());
</script>

<template>
    <main class="official-letter" dir="rtl" lang="ar">
        <Head :title="'طباعة ' + document.reference" />
        <nav class="print-controls" aria-label="إجراءات الطباعة">
            <button type="button" class="btn btn-primary" aria-label="طباعة المراسلة" title="طباعة المراسلة" @click="print">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
                    <path d="M7 14h10v7H7zM17 11h.01" />
                </svg>
                <span>طباعة</span>
            </button>
            <Link :href="backUrl" class="btn btn-secondary">العودة إلى المراسلة</Link>
            <p class="print-settings-help">اختر ورق A4 والحجم الفعلي 100%، وألغِ «الرؤوس والتذييلات» في إعدادات الطباعة لإخفاء عنوان الصفحة والرابط. فعّل طباعة الخلفيات لإظهار تظليل الخانات.</p>
        </nav>

        <article class="document-content">
            <header class="letterhead-box">
                <div class="institution-identity">
                    <img class="letterhead-logo" src="/images/brand/logo.png" alt="شعار معهد الأمل للأيتام" width="827" height="768" />
                    <div class="institution-details">
                        <p class="institution-name">{{ letterhead || 'معهد الأمل للأيتام' }}</p>
                        <p class="institution-caption">المراسلات الرسمية</p>
                    </div>
                </div>
                <h1 id="information-title">بيانات المراسلة الرسمية</h1>
            </header>

            <table class="letter-table information-table" aria-labelledby="information-title">
                <colgroup><col class="label-column" /><col /><col class="label-column" /><col class="short-value-column" /></colgroup>
                <tbody>
                    <tr><th scope="row">رقم المراسلة</th><td colspan="3"><bdi dir="ltr">{{ document.reference || '—' }}</bdi></td></tr>
                    <tr><th scope="row">المرسل</th><td colspan="3">{{ document.sender || '—' }}<span v-if="document.sender_department"> — {{ document.sender_department }}</span></td></tr>
                    <tr>
                        <th scope="row">المرسل إليه</th>
                        <td colspan="3">
                            <ul v-if="document.recipients.length" class="recipient-list"><li v-for="(recipient, index) in document.recipients" :key="index">{{ recipient }}</li></ul>
                            <span v-else>—</span>
                        </td>
                    </tr>
                    <tr><th scope="row">تاريخ المراسلة</th><td colspan="3"><bdi dir="ltr">{{ document.date || '—' }}</bdi></td></tr>
                    <tr><th scope="row">الأهمية</th><td>{{ document.priority || '—' }}</td><th scope="row">الحالة</th><td>{{ statuses[document.status] || document.status || '—' }}</td></tr>
                    <tr><th scope="row">نوع المراسلة</th><td>{{ document.type || '—' }}</td><th scope="row">التصنيف</th><td>{{ document.classification || '—' }}</td></tr>
                    <tr class="subject-row"><th scope="row">الموضوع</th><td colspan="3" class="subject-value">{{ document.subject || '—' }}</td></tr>
                    <tr class="message-row">
                        <th scope="row" id="message-title">نص المراسلة</th>
                        <td colspan="3" class="message-cell">
                            <p v-if="document.body" class="letter-body">{{ document.body }}</p>
                            <p v-else class="empty-note">لم يُسجل نص للمراسلة.</p>
                        </td>
                    </tr>
                    <tr v-if="document.issuer || document.issued_at">
                        <th scope="row">أصدرها</th><td>{{ document.issuer || '—' }}</td>
                        <th scope="row">وقت الإصدار</th><td><bdi dir="ltr">{{ document.issued_at || '—' }}</bdi></td>
                    </tr>
                </tbody>
            </table>

            <p v-if="document.edition_version" class="edition-note">
                النسخة النافذة المعتمدة رقم {{ document.edition_version }}
                <span v-if="document.edition_approver"> — اعتمدها {{ document.edition_approver }}</span>
                <span v-if="document.edition_approved_at"> بتاريخ {{ document.edition_approved_at }}</span>.
            </p>
            <p v-else class="edition-note">النص الأصلي المحفوظ دون تعديل.</p>

            <section class="attachments-section" aria-labelledby="attachments-title">
                <h2 id="attachments-title" class="section-title">المرفقات</h2>
                <table class="letter-table attachments-table">
                    <colgroup><col class="label-column" /><col /></colgroup>
                    <tbody>
                        <tr>
                            <th scope="row">المرفقات الأصلية</th>
                            <td>
                                <ol v-if="files.length" class="attachment-list"><li v-for="(file, index) in files" :key="index"><bdi class="filename" dir="auto">{{ file.original_filename }}</bdi><span class="file-version"> — الإصدار {{ file.document_version }}</span></li></ol>
                                <p v-else class="empty-note">لا توجد مرفقات أصلية.</p>
                            </td>
                        </tr>
                        <tr v-if="document.edition_version">
                            <th scope="row">مرفقات النسخة النافذة</th>
                            <td>
                                <ol v-if="editionFiles.length" class="attachment-list"><li v-for="(file, index) in editionFiles" :key="index"><bdi class="filename" dir="auto">{{ file.original_filename }}</bdi><span class="file-version"> — الإصدار {{ file.document_version }}</span></li></ol>
                                <p v-else class="empty-note">لا توجد مرفقات لهذه النسخة.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="routing-section" aria-labelledby="routing-title">
                <h2 id="routing-title" class="section-title">التحويلات والإحالات</h2>
                <p v-if="document.task_status || document.current_stage || document.current_holder" class="current-status">
                    <span v-if="document.task_status">المهمة: {{ taskStatuses[document.task_status] || document.task_status }}</span>
                    <span v-if="document.current_stage"> — المرحلة: {{ document.current_stage }}</span>
                    <span v-if="document.current_holder"> — لدى: {{ document.current_holder }}</span>
                </p>
                <table class="letter-table routing-table" aria-labelledby="routing-title">
                    <colgroup><col class="date-column" /><col class="party-column" /><col class="party-column" /><col /><col class="routing-files-column" /></colgroup>
                    <thead><tr><th scope="col">التاريخ</th><th scope="col">من</th><th scope="col">إلى</th><th scope="col">الوصف / التوجيه</th><th scope="col">المرفقات المحالة</th></tr></thead>
                    <tbody>
                        <tr v-for="entry in history" :key="entry.sequence">
                            <td class="event-date"><time dir="ltr">{{ entry.created_at }}</time></td>
                            <td>{{ entry.from || '—' }}</td>
                            <td>{{ entry.to || '—' }}</td>
                            <td>
                                <p class="event-action">#{{ entry.sequence }} — {{ actions[entry.action] || 'إجراء إحالة موثق' }}</p>
                                <p v-if="entry.note" class="history-note">{{ entry.note }}</p>
                                <p v-else class="empty-note">لا يوجد نص مرفق بهذا الإجراء.</p>
                            </td>
                            <td class="routing-files-cell">
                                <div v-if="entry.attachments.length">
                                    <ol class="attachment-list"><li v-for="(file, index) in entry.attachments" :key="index"><bdi class="filename" dir="auto">{{ file.original_filename }}</bdi><span class="file-version"> — الإصدار {{ file.document_version }}</span></li></ol>
                                </div>
                                <p v-else class="empty-note">لا توجد مرفقات.</p>
                            </td>
                        </tr>
                        <tr v-if="!history.length"><td colspan="5" class="empty-note">لا توجد إحالات أو تحويلات ظاهرة لهذا السجل.</td></tr>
                    </tbody>
                </table>
            </section>

            <section v-if="appendix.length" class="internal-appendix" aria-labelledby="appendix-title">
                <h2 id="appendix-title" class="section-title">ملحق تدقيق داخلي — لا يرفق بالنسخة الخارجية</h2>
                <ol class="appendix-list">
                    <li v-for="(entry, index) in appendix" :key="entry.id || index">
                        <article class="audit-entry">
                            <h3><bdi dir="ltr">{{ entry.created_at }}</bdi> — {{ entry.actor }} — {{ actions[entry.action] || 'إجراء موثق' }}<span v-if="entry.recipient"> — إلى {{ entry.recipient }}</span></h3>
                            <p v-if="entry.note" class="letter-body">{{ entry.note }}</p>
                            <ol v-if="entry.attachments?.length" class="attachment-list"><li v-for="(file, fileIndex) in entry.attachments" :key="fileIndex"><bdi class="filename" dir="auto">{{ file.original_filename }}</bdi></li></ol>
                        </article>
                    </li>
                </ol>
            </section>
        </article>

        <footer class="print-preview-footer" dir="ltr" lang="ar">
            <span>طُبعت بواسطة: {{ printAt.name }} | التاريخ: {{ printAt.date }} | الوقت: {{ printAt.time }}</span>
        </footer>
    </main>
</template>

<style scoped>
.official-letter {
    box-sizing: border-box;
    max-width: 210mm;
    margin: 0 auto;
    padding: 12mm 16mm;
    background: #fff;
    color: #111;
    font-family: Tahoma, Arial, sans-serif;
    font-size: 10pt;
    line-height: 1.6;
    overflow-wrap: anywhere;
}
.print-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; margin-bottom: 7mm; }
.print-settings-help { flex-basis: 100%; margin: 0; color: #475569; font-size: 9pt; }
.document-content { min-width: 0; }
.letterhead-box { margin-bottom: 5mm; text-align: center; break-inside: avoid; }
.institution-identity { display: flex; align-items: center; gap: 6mm; padding-bottom: 4mm; border-bottom: 1.5pt solid #193b59; }
.letterhead-logo { display: block; flex: 0 0 auto; width: 24mm; height: auto; object-fit: contain; }
.institution-details { flex: 1; min-width: 0; text-align: start; }
.institution-name { margin: 0; font-size: 19pt; font-weight: 700; line-height: 1.5; color: #193b59; }
.institution-caption { margin: 1.5mm 0 0; font-size: 10pt; color: #475569; }
.letterhead-box h1 { margin: 1mm 0 0; padding-top: 4mm; border-top: .75pt solid #b39455; font-size: 14pt; font-weight: 700; }
.letter-table { width: 100%; border-collapse: collapse; table-layout: fixed; border: .75pt solid #222; }
.letter-table th, .letter-table td { border: .6pt solid #222; padding: 1.8mm 2.5mm; text-align: start; vertical-align: top; overflow-wrap: anywhere; }
.letter-table th { background: #e4f5ff; font-weight: 700; }
.label-column { width: 28mm; }
.short-value-column { width: 36mm; }
.subject-value { font-weight: 700; }
.message-cell { padding: 4mm !important; }
.letter-body, .history-note { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.8; text-align: start; }
.message-cell .letter-body { min-height: 65mm; font-size: 11pt; }
.recipient-list { margin: 0; padding: 0; list-style: none; }
.recipient-list li + li { margin-top: 1mm; }
.edition-note { margin: 2mm 0 0; color: #475569; font-size: 8pt; }
.section-title { margin: 4mm 0 2mm; text-align: center; font-size: 12pt; font-weight: 700; }
.empty-note { margin: 0; color: #475569; font-size: 9pt; }
.attachment-list { margin: 0; padding-inline-start: 5mm; }
.attachment-list li + li { margin-top: 1mm; }
.filename { overflow-wrap: anywhere; }
.file-version { font-size: 8pt; color: #475569; }
.current-status { margin: 0 0 2mm; font-size: 8pt; }
.date-column { width: 24mm; }
.party-column { width: 27mm; }
.routing-files-column { width: 32mm; }
.routing-files-cell { font-size: 8pt; }
.routing-table thead th { text-align: center; }
.event-date { font-size: 8pt; }
.event-date time { display: block; text-align: center; unicode-bidi: isolate; }
.event-action { margin: 0 0 1mm; font-size: 8pt; font-weight: 700; }
.appendix-list { margin: 0; padding-inline-start: 5mm; }
.audit-entry { margin-top: 3mm; padding: 3mm; border: .6pt solid #222; }
.audit-entry h3 { margin: 0 0 2mm; font-size: 9pt; }
.print-preview-footer { margin-top: 5mm; color: #555; font-size: 8pt; line-height: 1.2; text-align: left; direction: ltr; unicode-bidi: isolate; }

@page {
    size: A4 portrait;
    margin: 14mm 16mm 22mm;
    @bottom-right {
        content: counter(page) " من " counter(pages);
        font-size: 8pt;
        direction: rtl;
        text-align: center;
    }
}

@media print {
    .official-letter { max-width: none; margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .print-controls, .print-preview-footer { display: none !important; }
    .letter-table { -webkit-box-decoration-break: clone; box-decoration-break: clone; }
    .letter-table thead { display: table-header-group; }
    .letter-table tr { break-inside: auto; page-break-inside: auto; }
    .letterhead-box, .section-title, .subject-row, .event-action { break-after: avoid; page-break-after: avoid; }
    .letter-body, .history-note { orphans: 3; widows: 3; }
    .audit-entry { break-inside: auto; }
}

@media screen and (max-width: 640px) {
    .official-letter { padding: 1rem; font-size: 9pt; }
    .label-column { width: 22%; }
    .short-value-column { width: 22%; }
    .date-column { width: 18%; }
    .party-column { width: 17%; }
    .routing-files-column { width: 22%; }
    .letter-table th, .letter-table td { padding: 1.5mm; }
    .message-cell .letter-body { font-size: 10pt; }
}
</style>
