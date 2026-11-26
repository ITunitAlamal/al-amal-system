// Evaluates the server's field rules (App\Services\Orphans\Registration\RegistrationFieldSpec::forClient) against the
// form, so the required markers and the missing-field list follow the same conditions the server enforces on
// submission. The server remains the control: this file only shows what it will ask for.
const present = v => !(v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0) || (typeof v === 'string' && v.trim() === ''));
const get = (obj, path) => path.split('.').reduce((o, k) => (o == null ? undefined : o[k]), obj);
const hasName = p => !!(p && ((present(p.first_name) && present(p.family_name)) || present(p.full_name)));

/** A condition over plain data without row binding (case study questions: show_if / required). */
export function holds(cond, data) {
    const [op, ...args] = cond;
    const v = () => get(data, args[0]);
    switch (op) {
        case 'all': return args.every(c => holds(c, data));
        case 'any': return args.some(c => holds(c, data));
        case 'not': return !holds(args[0], data);
        case 'filled': return present(v());
        case 'blank': return !present(v());
        case 'eq': return String(v() ?? '') === String(args[1]);
        case 'in': return args[1].map(String).includes(String(v() ?? ''));
        default: return false;
    }
}

export function createFieldRules(rules, form, options) {
    const values = (path, index) => {
        if (!path.includes('*')) return [get(form, path)];
        const [list, rest] = path.split('.*.');
        const rows = get(form, list) || [];
        if (index !== null && index !== undefined) return [get(rows[index] || {}, rest)];
        return rows.map(row => get(row, rest));
    };
    const codeOf = (list, id) => (options?.[list] || []).find(v => Number(v.id) === Number(id))?.code || null;
    const holds = (cond, index) => {
        const [op, ...args] = cond;
        switch (op) {
            case 'all': return args.every(c => holds(c, index));
            case 'any': return args.some(c => holds(c, index));
            case 'not': return !holds(args[0], index);
            case 'filled': return values(args[0], index).some(present);
            case 'blank': return !values(args[0], index).some(present);
            case 'true': return values(args[0], index).some(v => v === true || v === 1 || v === '1');
            case 'eq': return values(args[0], index).some(v => String(v ?? '') === String(args[1]));
            case 'in': return values(args[0], index).some(v => args[1].map(String).includes(String(v ?? '')));
            case 'code': return values(args[0], index).some(v => args[2].includes(codeOf(args[1], v)));
            default: return false;
        }
    };
    // A concrete key (children.2.kinship_note) → its rule (children.*.kinship_note) and row index.
    const lookup = key => {
        const match = /^([a-z_]+)\.(\d+)\.(.+)$/.exec(key);
        if (match && rules?.[`${match[1]}.*.${match[3]}`]) return [rules[`${match[1]}.*.${match[3]}`], Number(match[2])];
        return [rules?.[key], null];
    };
    const satisfied = (rule, key) => {
        const value = get(form, key);
        switch (rule.check) {
            case 'name': return hasName(get(form, key.slice(0, key.lastIndexOf('.'))));
            case 'true': return value === true;
            case 'rows': return Array.isArray(value) && value.length > 0;
            default: return present(value);
        }
    };
    /** Required on submission given the current answers (R always, C when its condition holds). */
    const required = key => {
        const [rule, index] = lookup(key);
        if (!rule || !['R', 'C'].includes(rule.req)) return false;
        return !rule.when || holds(rule.when, index);
    };
    /** Required fields still empty: [{ key, label }] in rule order, one per row for card fields. */
    const missing = () => {
        const out = [];
        Object.entries(rules || {}).forEach(([key, rule]) => {
            if (!['R', 'C'].includes(rule.req)) return;
            const rows = key.includes('*') ? (get(form, key.split('.*.')[0]) || []).map((_, i) => [key.replace('*', i), i]) : [[key, null]];
            rows.forEach(([concrete, i]) => {
                if ((!rule.when || holds(rule.when, i)) && !satisfied(rule, concrete)) out.push({ key: concrete, label: i === null ? rule.label : `${rule.label} (${i + 1})` });
            });
        });
        return out;
    };
    return { required, missing };
}
