(function () {
    const COUNTER_HINTS = {
        code: 20,
        sku_code: 20,
        name_th: 255,
        name_en: 255,
        tax_id: 20,
        tax_branch: 10,
        company_name_th: 255,
        company_name_en: 255,
        company_tax_id: 13,
        company_phone: 20,
    };

    function enhanceFloatingFields(root) {
        const scope = root || document;
        const candidates = scope.querySelectorAll(
            '.col-12, [class*="col-md-"], [class*="col-lg-"], [class*="col-sm-"], .product-filter-field:not(.search-field), .filter-field, .doc-field, .od-field, .erp-float-group'
        );

        candidates.forEach(function (box) {
            if (box.dataset.erpFloatEnhanced === '1') return;
            if (box.classList.contains('search-field')) return;

            const children = Array.from(box.children);
            if (children.length < 2) return;

            const label = children[0];
            const control = children[1];
            if (!label || label.tagName !== 'LABEL') return;
            if (!control || !['INPUT', 'SELECT', 'TEXTAREA'].includes(control.tagName)) return;
            if (['hidden', 'checkbox', 'radio', 'file', 'submit', 'button'].includes((control.type || '').toLowerCase())) return;

            box.dataset.erpFloatEnhanced = '1';
            box.classList.add('erp-has-float');

            // Preserve clean label text and extract required state
            const hasReqSpan = Boolean(label.querySelector('.text-danger, .required')) || label.textContent.includes('*');
            const isRequired = control.hasAttribute('required') || hasReqSpan;
            const rawText = label.textContent.replace(/\*/g, '').trim();

            const maxAttr = control.getAttribute('maxlength') || (control.name && COUNTER_HINTS[control.name]) || null;

            label.innerHTML = '';
            const textSpan = document.createElement('span');
            textSpan.className = 'erp-fl-text';
            textSpan.textContent = rawText;
            textSpan.title = rawText;
            label.appendChild(textSpan);

            const metaSpan = document.createElement('span');
            metaSpan.className = 'erp-fl-meta';

            if (maxAttr && control.tagName !== 'SELECT' && (control.type === 'text' || control.type === 'search' || control.tagName === 'TEXTAREA')) {
                const maxNum = parseInt(maxAttr, 10);
                if (!Number.isNaN(maxNum) && maxNum > 0) {
                    const counterSpan = document.createElement('span');
                    counterSpan.className = 'erp-fl-counter';
                    const updateCount = function () {
                        const len = (control.value || '').length;
                        counterSpan.textContent = String(Math.max(0, maxNum - len));
                    };
                    updateCount();
                    control.addEventListener('input', updateCount);
                    metaSpan.appendChild(counterSpan);
                }
            }

            if (isRequired) {
                const reqSpan = document.createElement('span');
                reqSpan.className = 'erp-fl-req';
                reqSpan.setAttribute('aria-hidden', 'true');
                reqSpan.textContent = '*';
                metaSpan.appendChild(reqSpan);
            }

            if (metaSpan.childNodes.length > 0) {
                label.appendChild(metaSpan);
            }
        });
    }

    function enhanceModals(root) {
        const scope = root || document;
        scope.querySelectorAll('.booking-modal, .po-modal').forEach(function (modal) {
            if (modal.dataset.erpModalEnhanced === '1') return;
            modal.dataset.erpModalEnhanced = '1';

            // Inject Basic / Advanced mode radio toggle under modal title if not present
            const header = modal.querySelector('.modal-header') || modal.firstElementChild;
            const titleEl = header ? header.querySelector('h1, h2, h3, h4, h5, .modal-title') : null;
            if (header && titleEl && !header.querySelector('.erp-modal-mode-toggle')) {
                let titleWrap = titleEl.parentElement;
                if (titleWrap === header) {
                    titleWrap = document.createElement('div');
                    header.insertBefore(titleWrap, titleEl);
                    titleWrap.appendChild(titleEl);
                }
                const toggle = document.createElement('div');
                toggle.className = 'erp-modal-mode-toggle';
                toggle.setAttribute('role', 'radiogroup');
                const radioName = 'erp_modal_mode_' + Math.random().toString(36).slice(2, 7);
                toggle.innerHTML =
                    '<label class="erp-mode-option is-active">' +
                        '<input type="radio" name="' + radioName + '" value="basic" checked class="form-check-input">' +
                        '<span>พื้นฐาน</span>' +
                    '</label>' +
                    '<label class="erp-mode-option">' +
                        '<input type="radio" name="' + radioName + '" value="advanced" class="form-check-input">' +
                        '<span>ขั้นสูง</span>' +
                    '</label>';
                titleWrap.appendChild(toggle);

                toggle.querySelectorAll('input[type="radio"]').forEach(function (radio) {
                    radio.addEventListener('change', function () {
                        const mode = radio.value;
                        modal.setAttribute('data-erp-form-mode', mode);
                        toggle.querySelectorAll('.erp-mode-option').forEach(function (opt) {
                            opt.classList.toggle('is-active', opt.contains(radio));
                        });
                    });
                });
            }

            // Inject clean bold section headings inside modal form grids
            const row = modal.querySelector('.modal-body .row');
            if (row && !row.querySelector('.erp-modal-section-heading')) {
                const firstHeading = document.createElement('div');
                firstHeading.className = 'col-12 erp-modal-section-heading';
                firstHeading.innerHTML = '<span>ข้อมูลหลัก</span>';
                row.insertBefore(firstHeading, row.firstElementChild);

                const taxInput = row.querySelector('[name="tax_id"]');
                if (taxInput && taxInput.closest('[class*="col-"]')) {
                    const secHeading = document.createElement('div');
                    secHeading.className = 'col-12 erp-modal-section-heading';
                    secHeading.innerHTML = '<span>ข้อมูลภาษีและเงื่อนไขการขาย</span>';
                    row.insertBefore(secHeading, taxInput.closest('[class*="col-"]'));
                }

                const stockInput = row.querySelector('[name="negative_stock_policy"]');
                if (stockInput && stockInput.closest('[class*="col-"]')) {
                    const secHeading = document.createElement('div');
                    secHeading.className = 'col-12 erp-modal-section-heading';
                    secHeading.innerHTML = '<span>การควบคุมสต๊อกและราคาขาย</span>';
                    row.insertBefore(secHeading, stockInput.closest('[class*="col-"]'));
                }

                // Mark advanced product policy fields so Basic vs Advanced toggle works cleanly
                [
                    'maximum_sale_price',
                    'minimum_margin_percent',
                    'margin_control_policy',
                    'expiry_warning_days',
                    'shelf_life_days',
                    'clearance_warning_days',
                    'clearance_discount_percent',
                    'expiry_sale_policy'
                ].forEach(function (fieldName) {
                    const el = row.querySelector('[name="' + fieldName + '"]');
                    const col = el ? el.closest('[class*="col-"]') : null;
                    if (col) col.classList.add('erp-advanced-field');
                });
                modal.setAttribute('data-erp-form-mode', 'basic');
            }
        });
    }

    function runPeakUiEnhancements() {
        enhanceFloatingFields(document);
        enhanceModals(document);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', runPeakUiEnhancements);
    } else {
        runPeakUiEnhancements();
    }
})();

(function () {
    const MAX_PREFETCHES = 6;
    const HOVER_DELAY_MS = 65;
    const prefetchedUrls = new Set();

    function shouldPrefetch(link) {
        if (!link || prefetchedUrls.size >= MAX_PREFETCHES) return null;
        if (link.hasAttribute('target') || link.hasAttribute('download')) return null;
        if (link.dataset && (link.dataset.method || link.dataset.turboMethod)) return null;

        const rawHref = (link.getAttribute('href') || '').trim();
        if (!rawHref || rawHref.startsWith('#') || rawHref.toLowerCase().startsWith('javascript:')) return null;

        let url;
        try {
            url = new URL(link.href, window.location.origin);
        } catch (e) {
            return null;
        }

        if (url.origin !== window.location.origin) return null;
        if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;
        if (/\/logout(\b|\/|$)/i.test(url.pathname)) return null;
        if (url.pathname === window.location.pathname && url.search === window.location.search) return null;

        url.hash = '';
        const normalized = url.href;
        if (prefetchedUrls.has(normalized)) return null;

        return normalized;
    }

    function triggerPrefetch(url) {
        if (!url || prefetchedUrls.size >= MAX_PREFETCHES || prefetchedUrls.has(url)) return;
        prefetchedUrls.add(url);
        const hint = document.createElement('link');
        hint.rel = 'prefetch';
        hint.href = url;
        hint.as = 'document';
        document.head.appendChild(hint);
    }

    function bindMenuHoverPrefetch() {
        const menuLinks = document.querySelectorAll('.odn-item, .fa-subnav-link');
        menuLinks.forEach(function (link) {
            if (link.dataset.erpPrefetchBound === '1') return;
            link.dataset.erpPrefetchBound = '1';

            let timerId = null;
            const clearTimer = function () {
                if (timerId !== null) {
                    clearTimeout(timerId);
                    timerId = null;
                }
            };

            const startTimer = function () {
                clearTimer();
                const targetUrl = shouldPrefetch(link);
                if (!targetUrl) return;
                timerId = setTimeout(function () {
                    timerId = null;
                    triggerPrefetch(targetUrl);
                }, HOVER_DELAY_MS);
            };

            link.addEventListener('pointerenter', startTimer, { passive: true });
            link.addEventListener('mouseenter', startTimer, { passive: true });
            link.addEventListener('pointerleave', clearTimer, { passive: true });
            link.addEventListener('mouseleave', clearTimer, { passive: true });
            link.addEventListener('pointercancel', clearTimer, { passive: true });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindMenuHoverPrefetch);
    } else {
        bindMenuHoverPrefetch();
    }
})();
