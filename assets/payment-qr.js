import qrcode from './vendor/qrcode.mjs';

const SVG_NS = 'http://www.w3.org/2000/svg';
const QUIET_ZONE = 4;
const encoder = new TextEncoder();

// SPAYD is assembled by PHP. Encode every character as UTF-8 QR bytes.
qrcode.stringToBytes = (value) => Array.from(encoder.encode(value));

function svgElement(name, attributes) {
    const element = document.createElementNS(SVG_NS, name);
    for (const [attribute, value] of Object.entries(attributes)) {
        element.setAttribute(attribute, String(value));
    }
    return element;
}

function renderPaymentQr(container) {
    const payload = container.dataset.qrPayload;
    if (!payload) {
        return;
    }

    try {
        const qr = qrcode(0, 'M');
        qr.addData(payload, 'Byte');
        qr.make();

        const dimension = qr.getModuleCount();
        const fullSize = dimension + 2 * QUIET_ZONE;
        const darkModules = [];
        for (let row = 0; row < dimension; row += 1) {
            for (let column = 0; column < dimension; column += 1) {
                if (qr.isDark(row, column)) {
                    darkModules.push(`M${column + QUIET_ZONE} ${row + QUIET_ZONE}h1v1h-1z`);
                }
            }
        }

        const svg = svgElement('svg', {
            xmlns: SVG_NS,
            viewBox: `0 0 ${fullSize} ${fullSize}`,
            width: 240,
            height: 240,
            role: 'img',
            'aria-label': 'QR kód pro platbu bankovním převodem',
            'shape-rendering': 'crispEdges',
            focusable: 'false',
        });
        svg.style.maxWidth = '100%';
        svg.style.height = 'auto';
        svg.append(
            svgElement('rect', {width: fullSize, height: fullSize, fill: '#fff'}),
            svgElement('path', {d: darkModules.join(''), fill: '#000'}),
        );
        container.replaceChildren(svg);
    } catch (error) {
        container.textContent = 'QR kód se nepodařilo zobrazit. Použijte platební údaje uvedené na stránce.';
    }
}

function renderPaymentQrs() {
    document.querySelectorAll('[data-qr-payload]').forEach(renderPaymentQr);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', renderPaymentQrs, {once: true});
} else {
    renderPaymentQrs();
}
