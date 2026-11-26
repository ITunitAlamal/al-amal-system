const loginForm = document.querySelector('.employee-login-form');

if (loginForm) {
    const button = loginForm.querySelector('button[type="submit"]');
    const label = button.querySelector('span');
    const originalLabel = label.textContent;
    let submitting = false;

    loginForm.addEventListener('submit', event => {
        if (event.defaultPrevented) return;
        if (submitting) {
            event.preventDefault();
            return;
        }
        submitting = true;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        label.textContent = 'جارٍ تسجيل الدخول…';
    });

    window.addEventListener('pageshow', () => {
        submitting = false;
        button.disabled = false;
        button.removeAttribute('aria-busy');
        label.textContent = originalLabel;
    });
}
