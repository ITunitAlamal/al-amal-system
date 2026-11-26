const registryKeys = ['q', 'first_name', 'father_name', 'grandfather_name', 'family_name', 'governorate', 'locality', 'area', 'sex', 'age_band', 'class', 'status', 'completeness', 'guardianship', 'displaced', 'education', 'issue', 'linked', 'portal', 'sponsorship', 'benefit', 'assigned', 'sort'];
const keys = [...registryKeys, 'from', 'to', 'campaign', 'sponsor'];
export function appliedRegistryFilters(filters = {}) {
    return Object.fromEntries(registryKeys.filter(key => filters[key] !== '' && filters[key] != null).map(key => [key, String(filters[key])]));
}
export function appliedReportFilters(filters = {}) {
    return Object.fromEntries(keys.filter(key => filters[key] !== '' && filters[key] != null).map(key => [key, String(filters[key])]));
}
export function reportFiltersChanged(draft, applied) {
    return JSON.stringify(appliedReportFilters(draft)) !== JSON.stringify(appliedReportFilters(applied));
}
export function reportExportUrl(filters, report, format, purpose = '') {
    const query = new URLSearchParams({ ...appliedReportFilters(filters), report, format });
    if (report === 'registry') query.set('purpose', purpose.trim());
    return `/orphans/reports/export?${query}`;
}
