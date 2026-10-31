(() => {
    const form = document.querySelector('form[data-live-report]');
    if (!form) return;

    let timer;
    let controller;
    let revision = 0;
    let composing = false;
    const searchInput = target => target instanceof HTMLInputElement && target.name === 'q';
    const invalidate = () => {
        clearTimeout(timer);
        controller?.abort();
        revision += 1;
    };
    const status = text => {
        const label = form.querySelector('[data-report-search-status]');
        if (label) label.textContent = text;
    };

    async function load(pageUrl) {
        invalidate();
        const current = revision;
        const url = pageUrl || new URL(form.action || location.href);
        if (!pageUrl) url.search = new URLSearchParams(new FormData(form)).toString();
        controller = new AbortController();
        status('กำลังค้นหา…');
        try {
            const response = await fetch(url, {signal: controller.signal});
            if (!response.ok) throw new Error('Search failed');
            const html = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (current !== revision) return;
            const replacement = html.querySelector('[data-report-result]');
            if (!replacement || !html.querySelector('form[data-live-report]')) throw new Error('Invalid report response');
            const input = form.elements.q;
            const focused = document.activeElement === input;
            const selection = [input.selectionStart, input.selectionEnd];
            form.querySelector('[data-report-result]').replaceWith(replacement);
            history.replaceState(null, '', url);
            if (focused) {
                form.elements.q.focus({preventScroll: true});
                form.elements.q.setSelectionRange(...selection);
            }
        } catch (error) {
            if (error.name !== 'AbortError' && current === revision) {
                status('ค้นหาไม่สำเร็จ กรุณากดค้นหาเพื่อลองอีกครั้ง');
            }
        }
    }

    form.addEventListener('input', event => {
        if (!searchInput(event.target)) return;
        invalidate();
        if (!composing) timer = setTimeout(() => load(), 500);
    });
    form.addEventListener('compositionstart', event => {
        if (!searchInput(event.target)) return;
        composing = true;
        invalidate();
    });
    form.addEventListener('compositionend', event => {
        if (!searchInput(event.target)) return;
        composing = false;
        invalidate();
        timer = setTimeout(() => load(), 500);
    });
    form.addEventListener('submit', event => {
        event.preventDefault();
        if (!composing) load();
    });
    form.addEventListener('click', event => {
        const link = event.target.closest('[data-report-pagination] a');
        if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        load(new URL(link.href));
    });
})();
