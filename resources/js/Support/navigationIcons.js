// Presentation variants for existing generic icons; administrator-selected distinct icons take precedence.
export const navigationIconPaths = {
    attendance: 'M12 3a9 9 0 1 0 9 9 9 9 0 0 0-9-9 M12 7v5l3 2',
    shifts: 'M3 5h18v16H3z M7 2v6 M17 2v6 M3 10h18 M7 14h4 M7 17h7',
    permission: 'M6 3h12v18H6z M9 7h6 M9 11h6 M9 16l2 2 4-4',
    correction: 'M4 7h10 M4 12h7 M4 17h5 M14 20l2-6 4-4 3 3-4 4z',
    employee: 'M3 4h18v16H3z M8 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4 M5 17v-1a3 3 0 0 1 6 0v1 M14 9h4 M14 13h4',
};

export function navigationIcon(item, configuredPaths) {
    const variants = { attendance_workspace: 'attendance', attendance: 'attendance', attendance_compensatory: 'shifts', employee_profiles: 'employee', leave_request: 'permission' };
    const generic = { attendance_workspace: 'calendar', attendance: 'calendar', attendance_compensatory: 'calendar', employee_profiles: 'users', leave_request: 'calendar' };
    if (variants[item?.code] && (!item.icon || item.icon === generic[item.code])) return variants[item.code];
    return Object.hasOwn(configuredPaths, item?.icon) ? item.icon : 'folder';
}
