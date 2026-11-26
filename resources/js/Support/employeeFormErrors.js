export function employeeErrorStep(field) {
    const root = field.split('.')[0];
    if (['qualifications', 'has_qualifications'].includes(root)) return 1;
    if (['department_id', 'job_category_id', 'job_title_id', 'job_grade_id', 'hire_date', 'work_start_date', 'employment_status', 'employment_term_value_id', 'contract_type_value_id', 'work_schedule_value_id', 'administrative_level_value_id', 'notes'].includes(root)) return 2;
    if (['family_members', 'has_family_members'].includes(root)) return 3;
    if (['leave_opening_balances', 'confirm_no_opening_balances'].includes(root)) return 4;
    return 0;
}
