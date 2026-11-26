// Scoped to the open mobile drawer. Returns cleanup for close, resize and unmount.
export function activateNavigationDrawer(panel, background, trigger, onClose, documentRoot = document) {
    const previousOverflow = documentRoot.body.style.overflow;
    const previousInert = background.inert;
    background.inert = true;
    documentRoot.body.style.overflow = 'hidden';
    const focusable = () => [...panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), [tabindex="0"]')]
        .filter(element => element.getClientRects().length && !element.closest('[inert]'));
    const focusFirst = () => (focusable()[0] || panel).focus();
    function onKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            onClose();
        } else if (event.key === 'Tab') {
            const elements = focusable();
            const first = elements[0];
            const last = elements.at(-1);
            if (!first) { event.preventDefault(); panel.focus(); }
            else if (event.shiftKey && (documentRoot.activeElement === first || !panel.contains(documentRoot.activeElement))) {
                event.preventDefault(); last.focus();
            } else if (!event.shiftKey && (documentRoot.activeElement === last || !panel.contains(documentRoot.activeElement))) {
                event.preventDefault(); first.focus();
            }
        }
    }
    function onFocus(event) { if (!panel.contains(event.target)) focusFirst(); }
    documentRoot.addEventListener('keydown', onKeydown);
    documentRoot.addEventListener('focusin', onFocus);
    focusFirst();
    return () => {
        documentRoot.removeEventListener('keydown', onKeydown);
        documentRoot.removeEventListener('focusin', onFocus);
        documentRoot.body.style.overflow = previousOverflow;
        background.inert = previousInert;
        if (trigger?.isConnected && trigger.getClientRects().length) trigger.focus();
    };
}
