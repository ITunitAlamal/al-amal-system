'use strict';

// Handwritten signature pad: pointer events cover touch, stylus (with pressure) and mouse. Produces a PNG data URL.
// A drawn electronic signature is evidence of acknowledgement, not a qualified digital signature.
window.SignaturePad = function SignaturePad(canvas) {
    const ctx = canvas.getContext('2d');
    let drawing = false;
    let strokes = 0;
    function resize() {
        const ratio = window.devicePixelRatio || 1;
        canvas.width = canvas.clientWidth * ratio;
        canvas.height = canvas.clientHeight * ratio;
        ctx.scale(ratio, ratio);
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#0f172a';
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        strokes = 0;
    }
    function point(event) {
        const rect = canvas.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top, w: event.pressure > 0 ? 1 + event.pressure * 3 : 2.2 };
    }
    canvas.addEventListener('pointerdown', event => {
        drawing = true;
        canvas.setPointerCapture(event.pointerId);
        const p = point(event);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
    });
    canvas.addEventListener('pointermove', event => {
        if (!drawing) return;
        const p = point(event);
        ctx.lineWidth = p.w;
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
    });
    const end = () => { if (drawing) strokes += 1; drawing = false; };
    canvas.addEventListener('pointerup', end);
    canvas.addEventListener('pointercancel', end);
    resize();
    return {
        clear: resize,
        isEmpty: () => strokes === 0,
        toDataURL: () => canvas.toDataURL('image/png'),
    };
};
