import assert from 'node:assert/strict';

class Element {
    constructor() {
        this.attributes = {};
        this.children = [];
        this.dataset = {};
        this.style = {};
        this.textContent = '';
    }

    setAttribute(name, value) {
        this.attributes[name] = value;
    }

    append(...children) {
        this.children.push(...children);
    }

    replaceChildren(...children) {
        this.children = children;
    }
}

const valid = new Element();
valid.dataset.qrPayload = 'SPD*1.0*ACC:CZ6508000000192000145399*AM:250.00*CC:CZK*X-VS:12345*MSG:Objednávka 12345';
const tooLong = new Element();
tooLong.dataset.qrPayload = 'x'.repeat(5000);

globalThis.document = {
    readyState: 'complete',
    createElementNS: () => new Element(),
    querySelectorAll: (selector) => selector === '[data-qr-payload]' ? [valid, tooLong] : [],
};

await import('../assets/payment-qr.js');

assert.equal(valid.children.length, 1);
const svg = valid.children[0];
assert.equal(svg.attributes.role, 'img');
assert.equal(svg.attributes['shape-rendering'], 'crispEdges');
assert.match(svg.attributes.viewBox, /^0 0 \d+ \d+$/);
assert.equal(svg.children[0].attributes.fill, '#fff');
assert.match(svg.children[1].attributes.d, /^M\d+ \d+h1v1h-1z/);
assert.equal(tooLong.children.length, 0);
assert.match(tooLong.textContent, /QR kód se nepodařilo zobrazit/);

console.log('Payment QR rendering: OK');
