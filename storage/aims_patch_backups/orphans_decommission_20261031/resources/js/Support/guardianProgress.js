// Guardian application status labels and badge tones, shared by the guardian portal and the staff review screen.
// The progress steps themselves are built on the server (GuardianApplicationProgress).
export const guardianStates = {
    awaiting_api: ['بانتظار توفر التحقق', 'warning'],
    verifying: ['جارٍ التحقق', 'info'],
    pending_review: ['بانتظار مراجعة المشرف', 'info'],
    verification_failed: ['الأهلية غير مثبتة', 'danger'],
    needs_changes: ['مطلوب استكمال', 'warning'],
    approved: ['معتمد', 'success'],
    rejected: ['مرفوض', 'danger'],
};
