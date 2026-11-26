// Shared helpers of «شؤون الأيتام والكفالات». Labels mirror the server; the server decides every permission.

export async function jsonRequest(url, data = null, method = null, options = {}) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (data !== null) {
        headers['Content-Type'] = 'application/json';
        const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
        if (cookie) headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
    }
    const response = await fetch(url, { signal: options.signal, method: method || (data === null ? 'GET' : 'POST'), credentials: 'same-origin', headers, ...(data === null ? {} : { body: JSON.stringify(data) }) });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(Object.values(payload.errors ?? {}).flat().join(' • ') || payload.message || 'تعذر إتمام الطلب. أعد المحاولة.');
    return payload;
}

export const linkTones = { approved: 'success', pending: 'info', claimed: 'warning', rejected: 'danger', ended: 'muted' };
export const fileTones = { active: 'success', provisional: 'info', draft: 'warning', suspended: 'danger', archived: 'muted', ended: 'muted' };
export const sectionLabels = { basic: 'البيانات الأساسية', family: 'الأب والأم', guardian: 'الوصاية', residence: 'السكن', education: 'التعليم', assessment: 'التقييم الاجتماعي' };
export const sectionStates = { complete: ['مكتمل', 'success'], partial: ['ناقص', 'warning'], missing: ['غير متوفر', 'muted'] };
export const idFormatTones = { valid_format: 'info', missing: 'muted', invalid_format: 'danger', placeholder: 'danger', other_type: 'info' };
export const careCategories = { health: 'الحالة الصحية', disability: 'إعاقة / احتياج دعم', chronic: 'رعاية مزمنة', medication: 'مساعدة دوائية', assistive_device: 'أداة مساعدة', education_support: 'دعم تعليمي', other: 'أخرى' };
export const sources = { self_reported: 'إفادة ذاتية', self: 'إفادة ذاتية', caregiver: 'إفادة مقدم الرعاية', clinician: 'تقرير مختص', document: 'مستند', field_visit: 'زيارة ميدانية', import: 'استيراد' };
export const verificationLabels = { reported: 'مُفاد (غير متحقق)', verified: 'متحقق منه' };

export function emptyPerson() {
    return { first_name: '', father_name: '', grandfather_name: '', family_name: '', extra_names: '', full_name: '', name_en: '', id_type_value_id: null, id_number: '',
        sex: '', date_of_birth: '', dob_status: 'declared', nationality_value_id: null, place_of_birth: '', phone: '', alt_phone: '', email: '', notes: '' };
}

export function emptyAddress() {
    return { governorate_value_id: null, locality_value_id: null, area_value_id: null, subarea_value_id: null, governorate: '', city: '', area: '', neighborhood: '', address_other: false, address_details: '' };
}

export function displayDate(value) {
    if (!value) return 'غير متوفر';
    return String(value).slice(0, 10);
}
