export function normalizeHiddenColumns(value, columns) {
    if (!Array.isArray(value)) return [];
    const optional = new Set(columns.filter(column => !column.required).map(column => column.key));
    return [...new Set(value.filter(key => optional.has(key)))];
}

export function columnStorageKey(userId, table) {
    return userId ? `aims.columns.v1.${userId}.${table}` : null;
}
