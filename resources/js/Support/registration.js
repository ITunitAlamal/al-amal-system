// Family registration form: empty shapes, labels and small calculations. The server owns validation and permissions
// (App\Services\Orphans\Registration\RegistrationForm).
import { emptyAddress, emptyPerson } from './orphans.js';

export const ynu = [['yes', 'نعم'], ['no', 'لا'], ['unknown', 'غير معروف']];
export const vitalOptions = [['alive', 'على قيد الحياة'], ['deceased', 'متوفى/ة'], ['missing', 'مفقود/ة'], ['unknown', 'غير محدد']];
export const stability = [['stable', 'مستقر'], ['irregular', 'غير منتظم'], ['seasonal', 'موسمي'], ['unknown', 'غير معروف']];
export const regularity = [['regular', 'منتظم'], ['irregular', 'غير منتظم'], ['one_off', 'لمرة واحدة'], ['unknown', 'غير معروف']];
export const modalities = [['in_person', 'حضوري'], ['remote', 'عن بُعد'], ['blended', 'مدمج'], ['other', 'أخرى']];
export const severities = [['mild', 'بسيطة'], ['moderate', 'متوسطة'], ['severe', 'شديدة'], ['unknown', 'غير معروفة']];
export const sourceOptions = [['self_reported', 'إفادة ذاتية'], ['caregiver', 'إفادة مقدم الرعاية'], ['document', 'مستند'], ['field_visit', 'زيارة ميدانية']];
export const steps = ['بيانات الطلب', 'الأب', 'الأم', 'الأسرة', 'الأوصياء', 'السكن والنزوح', 'الأيتام', 'المرفقات', 'المراجعة والإقرار'];

const uid = () => (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`);
const person = (email = false) => { const p = emptyPerson(); delete p.notes; delete p.dob_status; if (!email) delete p.email; else p.email ??= ''; return p; };
export const confidenceOptions = [['high', 'عالية'], ['medium', 'متوسطة'], ['low', 'منخفضة']];
export const address = () => ({ ...emptyAddress(), street: '', landmark: '' });
const economic = () => ({ occupation: '', employment_status_value_id: null, employed: '', employer: '', stability: '', income_amount: '', currency: 'ILS', income_regularity: '',
    other_income: '', dependents_count: '', notes: '', source: '', confidence: '', sector: '', income_band_value_id: null, benefits: '', benefits_note: '' });

export function blankParent(relation) {
    return { ...person(), sex: relation === 'father' ? 'male' : 'female', vital_status: '', status_source: '', status_confidence: '',
        death: { date: '', date_unknown: false, place: '', cause_value_id: null, cause_note: '', certificate_no: '', evidence_note: '', official_cause: '', documents: '' },
        missing: { last_seen_on: '', date_unknown: false, last_seen_place: '', reported_on: '', reported_to: '', report_ref: '', details: '' },
        education: { level_value_id: null, specialization: '', institution: '', vocational: '' },
        economic: { ...economic(), ...(relation === 'father' ? { supported_household: '', breadwinner_loss: '' } : {}) },
        ...(relation === 'father' ? { residence_before: '' } : { marital_status_value_id: null, marital_status_since: '', contact_channel: '', resides_with_household: '', address: address(), care: { support_needs: '', accommodation: '' },
            caregiving: { capacity: '', note: '' } }) };
}
export const blankGuardian = (role = 'other') => ({ key: uid(), role, ...person(true), id_missing_reason: '', contact_channel: '', resides_with_household: '', displaced: '', address: address(),
    education: { level_value_id: null, specialization: '' },
    economic: { employed: '', occupation: '', stability: '', income_amount: '', currency: 'ILS', other_income: '', dependents_count: '', housing_note: '', notes: '' } });
export const blankChild = () => ({ key: uid(), ...person(), id_missing_reason: '', civil_registry_ref: '', guardian_key: '', kinship_value_id: null, kinship_note: '', resides_with_household: true,
    guardianship: { court_status_value_id: null, authority_kind_value_id: null, issuing_authority: '', decision_no: '', starts_on: '', ends_on: '', evidence_note: '' },
    education: { status_value_id: null, stage_value_id: null, grade_value_id: null, achievement_value_id: null, grade: '', school_name: '', institution_type_value_id: null, modality: '', academic_year: '', attendance: '', performance: '',
        nonattendance_reason: '', barriers: '', transport_need: null, assistive_need: '', specialization: '' },
    health: { status_value_id: null, details: '', disability_type_value_id: null, severity: '', regular_follow_up: '', follow_up_frequency: '', medications: '', assistive_devices: '',
        access_needs: '', accommodations: '', source: '', assessed_on: '' },
    social: { loss_circumstances: '', researcher_notes: '' },
    needs: { food: '', clothing: '', education: '', health: '', displacement_impact: '', notes: '' },
    sponsorship: { status: '', organization: '', support_type_value_id: null, regularity: '', frequency_value_id: null, starts_on: '', ends_on: '', amount: '', currency: 'ILS', evidence_note: '' } });
export const blankMember = () => ({ first_name: '', father_name: '', grandfather_name: '', family_name: '', full_name: '', id_type_value_id: null, id_number: '', sex: '', date_of_birth: '',
    relation_value_id: null, is_studying: '', is_employed: '', is_dependent: '', since: '' });
export const blankAid = () => ({ child_key: '', organization: '', support_type_value_id: null, support_type_note: '', frequency_value_id: null, regularity: '', received_on: '', amount: '', currency: 'ILS', quantity: '', note: '' });
export const blankMove = () => ({ ...address(), moved_on: '', left_on: '', reason: '' });

/** Fills a saved snapshot into complete shapes so every conditional field is reactive. */
export function hydrate(saved = {}) {
    const deep = (base, value) => {
        if (Array.isArray(base) || value === undefined || value === null) return value ?? base;
        if (typeof base !== 'object' || base === null) return value;
        const out = { ...base };
        Object.keys(value).forEach(key => { out[key] = key in base ? deep(base[key], value[key]) : value[key]; });
        return out;
    };
    const household = deep({ display_name: '', declared_members: '', male_count: '', female_count: '', unknown_sex_count: '', studying_count: '', employed_count: '', dependents_count: '',
        earners_count: '', assessed_on: '', count_exception_reason: '', income_source_ids: [], total_income: '', currency: 'ILS', income_period: 'monthly', income_note: '',
        economic_status_value_id: null, expenses_amount: '', unmet_needs: '', external_aid: '', aid: [], tenure_value_id: null, utilities_access: '', living_conditions: '', members: [],
        special_needs_count: '', head_relation_value_id: null, contact_phone: '', rent_amount: '', employment_stability: '', assessed_economic_status_value_id: null,
        legacy_file_no: '', contact_unreachable_reason: '', income_items: [] }, saved.household);
    return {
        request: deep({ applicant: { ...person(true), contact_channel: '' }, relationship_value_id: null, notes: '' }, saved.request),
        father: deep(blankParent('father'), saved.father),
        mother: deep(blankParent('mother'), saved.mother),
        guardians: (saved.guardians || []).map(g => deep(blankGuardian(g.role), g)),
        household: { ...household, aid: (household.aid || []).map(a => deep(blankAid(), a)), members: (household.members || []).map(m => deep(blankMember(), m)) },
        residence: (() => { const r = deep({ original: { ...address(), housing_type_value_id: null, building_condition_value_id: null }, current: { ...address(), housing_condition: '', dwelling_condition_value_id: null, stability: '', rooms_count: '', building_area_sqm: '', building_type_value_id: null, building_condition_value_id: null, furnishing_condition_value_id: null }, displaced: '', displaced_since: '',
            displacement_count: '', last_moved_on: '', shelter_type_value_id: null, contact_accessibility: '', hosted: '', host_note: '', accessibility_needs: '', source: '', location_missing_reason: '', history: [] }, saved.residence); return { ...r, history: (r.history || []).map(m => deep(blankMove(), m)) }; })(),
        children: (saved.children?.length ? saved.children : [{}]).map(c => deep(blankChild(), c)),
        declaration: { truth: !!saved.declaration?.truth, consent: !!saved.declaration?.consent, name: saved.declaration?.name || '' },
    };
}

/** Step that owns an error key, used to jump to the first error. */
export function stepForError(field) {
    const order = [[/^request/, 0], [/^father/, 1], [/^mother/, 2], [/^household/, 3], [/^guardians/, 4], [/^residence/, 5], [/^children/, 6], [/^(file|target|document)/, 7], [/^declaration/, 8]];
    return order.find(([pattern]) => pattern.test(field))?.[1] ?? 8;
}

export const nameOf = p => (p?.full_name || [p?.first_name, p?.father_name, p?.grandfather_name, p?.family_name].filter(Boolean).join(' ')).trim();

/** Age shown from the date of birth; never stored. */
export function ageOf(dob) {
    if (!dob) return '';
    const birth = new Date(dob); const now = new Date();
    if (Number.isNaN(birth.getTime()) || birth > now) return '';
    let years = now.getFullYear() - birth.getFullYear();
    let months = now.getMonth() - birth.getMonth();
    if (now.getDate() < birth.getDate()) months -= 1;
    if (months < 0) { years -= 1; months += 12; }
    return years ? `${years} سنة${months ? ` و${months} شهر` : ''}` : `${months} شهر`;
}

export const codeOf = (list, id) => (list || []).find(v => Number(v.id) === Number(id))?.code || null;
export const labelOf = (list, id) => (list || []).find(v => Number(v.id) === Number(id))?.name || '';
export const pairLabel = (pairs, value) => pairs.find(([v]) => v === value)?.[1] || 'غير محدد';
