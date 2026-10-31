const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const vm = require('node:vm');
const source = readFileSync(require('node:path').join(__dirname, '../../public/report-search.js'), 'utf8');

function browser() {
    const listeners = {}, timers = new Map(), requests = [], rendered = [], urls = [];
    const label = {textContent: ''};
    let timerId = 0;
    class Input {
        name = 'q';
        value = '';
        selectionStart = 0;
        selectionEnd = 0;
        focus() { document.activeElement = this; }
        setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; }
    }
    const form = {
        action: 'https://erp.example/reports?page=4', elements: {q: new Input()},
        addEventListener(type, listener) { listeners[type] = listener; },
        querySelector(selector) {
            if (selector === '[data-report-search-status]') return label;
            return {replaceWith(replacement) {
                rendered.push(replacement.query);
                form.elements.q = Object.assign(new Input(), {value: replacement.query});
            }};
        },
    };
    const document = {activeElement: form.elements.q, querySelector: () => form};
    vm.runInNewContext(source, {
        document, HTMLInputElement: Input, URL, URLSearchParams, AbortController,
        location: {href: form.action}, history: {replaceState: (_, __, url) => urls.push(String(url))},
        FormData: class {constructor(form) { return [['category', 'inventory'], ['report', 'stock_balance'], ['branch_id', '34'], ['q', form.elements.q.value]]; }},
        DOMParser: class {parseFromString(query) { return {querySelector: selector => selector === 'form[data-live-report]' ? form : {query}}; }},
        setTimeout(fn, delay) { const id = ++timerId; timers.set(id, {fn, delay}); return id; },
        clearTimeout(id) { timers.delete(id); },
        fetch(url, options) { return new Promise(resolve => requests.push({url: new URL(url), signal: options.signal, resolve})); },
    });
    const fire = (type, event = {}) => listeners[type]({target: form.elements.q, preventDefault() {}, ...event});
    const type = value => { form.elements.q.value = value; fire('input'); };
    const tick = () => { const jobs = [...timers.values()]; timers.clear(); jobs.forEach(job => { assert.equal(job.delay, 500); job.fn(); }); };
    const complete = async (index, query, ok = true) => {
        requests[index].resolve({ok, text: async () => query});
        for (let i = 0; i < 8; i++) await Promise.resolve();
    };
    return {form, document, timers, requests, rendered, urls, label, fire, type, tick, complete};
}

test('typing searches the whole selected branch after a pause and preserves focus and caret', async () => {
    const b = browser();
    b.type('1022'); b.type('102204');
    b.form.elements.q.setSelectionRange(6, 6);
    assert.equal(b.requests.length, 0);
    assert.equal(b.timers.size, 1);
    b.tick();
    assert.equal(b.requests[0].url.searchParams.get('q'), '102204');
    assert.equal(b.requests[0].url.searchParams.get('branch_id'), '34');
    assert.equal(b.requests[0].url.searchParams.has('page'), false);
    await b.complete(0, '102204');
    assert.deepEqual(b.rendered, ['102204']);
    assert.equal(b.document.activeElement, b.form.elements.q);
    assert.equal(b.form.elements.q.selectionStart, 6);
});

test('an older response cannot overwrite a newer search even if it ignores cancellation', async () => {
    const b = browser();
    b.type('1022'); b.tick();
    b.type('หมูเด้ง');
    assert.equal(b.requests[0].signal.aborted, true);
    b.tick();
    await b.complete(1, 'หมูเด้ง');
    await b.complete(0, '1022');
    assert.deepEqual(b.rendered, ['หมูเด้ง']);
    assert.equal(b.urls.length, 1);
});

test('IME composition waits until the composed word is complete', async () => {
    const b = browser();
    b.fire('compositionstart'); b.type('ห'); b.type('หมู');
    assert.equal(b.timers.size, 0);
    b.fire('compositionend'); b.tick();
    assert.equal(b.requests.length, 1);
    await b.complete(0, 'หมู');
    assert.deepEqual(b.rendered, ['หมู']);
});

test('submit searches immediately and a failed request leaves a visible retry message', async () => {
    const b = browser();
    b.type('102204'); b.fire('submit');
    assert.equal(b.timers.size, 0);
    assert.equal(b.requests.length, 1);
    await b.complete(0, '', false);
    assert.deepEqual(b.rendered, []);
    assert.match(b.label.textContent, /ค้นหาไม่สำเร็จ/);
    b.fire('submit');
    await b.complete(1, '102204');
    assert.deepEqual(b.rendered, ['102204']);
});

test('pagination preserves the search and branch while modifier clicks use normal navigation', async () => {
    const b = browser();
    const target = {closest: () => ({href: 'https://erp.example/reports?branch_id=34&q=หมู&page=2'})};
    b.fire('click', {target, button: 0, ctrlKey: true});
    assert.equal(b.requests.length, 0);
    b.fire('click', {target, button: 0});
    assert.equal(b.requests[0].url.searchParams.get('page'), '2');
    assert.equal(b.requests[0].url.searchParams.get('q'), 'หมู');
    await b.complete(0, 'หมู');
    assert.deepEqual(b.rendered, ['หมู']);
});
