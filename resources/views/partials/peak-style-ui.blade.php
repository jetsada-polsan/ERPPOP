{{-- ══════════════════════════════════════════════════════════════════════════
   MPM ERP — PEAK-Inspired Shared UI System (MPM Deep Blue & Emerald Theme)
   - Floating-label inputs & dropdowns with required (*) & maxlength counter
   - Capsule buttons (~40px height, primary / outline / cancel / disabled)
   - Circular primary radio & checkbox controls in horizontal rows
   - Clean form modals with Basic/Advanced mode toggle & bold section headers
   - Top card-style navigation bar (icon + label, light-gray active tab)
   - Soft light-gray table headers & compact capsule row action buttons
   ══════════════════════════════════════════════════════════════════════════ --}}
<style id="erp-peak-style-system">
    :root {
        --peak-field-border: #d1d5db;
        --peak-field-border-hover: #94a3b8;
        --peak-field-bg: #ffffff;
        --peak-field-radius: 8px;
        --peak-field-h: 48px;
        --peak-label-color: #475569;
        --peak-active-tab-bg: #eaedf1;
        --peak-table-head-bg: #f1f5f9;
        --peak-table-head-text: #334155;
        --peak-table-border: #e2e8f0;
    }

    /* ── 1. แถบเมนูบนแบบการ์ด (Top Card-Style Menu Bar & Page Tabs) ─────────── */
    .erp-peak-topcard-wrap {
        padding: 14px 24px 0 24px;
    }
    .erp-peak-topcard {
        display: flex;
        align-items: stretch;
        background: #ffffff;
        border: 1px solid var(--erp-border, #dbe7ef);
        border-radius: 10px;
        box-shadow: 0 4px 14px rgba(15, 35, 52, .06);
        overflow-x: auto;
        scrollbar-width: none;
    }
    .erp-peak-topcard::-webkit-scrollbar { display: none; }
    .erp-peak-topcard-tab {
        flex: 1 1 0;
        min-width: 112px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 13px 14px;
        color: #334155;
        font-size: 14px;
        font-weight: 600;
        text-decoration: none !important;
        white-space: nowrap;
        border: 0;
        background: transparent;
        transition: background .15s ease, color .15s ease;
        cursor: pointer;
    }
    .erp-peak-topcard-tab i {
        font-size: 16px;
        color: var(--erp-primary, #1585c0);
        transition: transform .15s ease;
    }
    .erp-peak-topcard-tab:hover {
        background: #f8fafc;
        color: var(--erp-primary-dark, #0f4c75);
    }
    .erp-peak-topcard-tab.is-active {
        background: var(--peak-active-tab-bg, #eaedf1);
        color: var(--erp-primary-dark, #0f4c75);
        font-weight: 700;
    }
    .erp-peak-topcard-tab.is-active i {
        color: var(--erp-primary-dark, #0f4c75);
    }

    /* แท็บย่อยในหน้าเอกสาร (.doc-tabs) และ .nav-pills ให้เป็นสไตล์การ์ดแท็บพื้นเทาอ่อน */
    .doc-tabs {
        display: inline-flex !important;
        flex-wrap: wrap;
        gap: 0 !important;
        background: #ffffff;
        border: 1px solid var(--erp-border, #dbe7ef);
        border-radius: 10px;
        padding: 0;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(15, 35, 52, .05);
    }
    .doc-tab {
        border: 0 !important;
        border-radius: 0 !important;
        padding: 10px 20px !important;
        background: transparent !important;
        color: #475569 !important;
        font-weight: 600 !important;
        font-size: 14px !important;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        transition: background .14s ease, color .14s ease;
    }
    .doc-tab::before {
        content: "\F389";
        font-family: "bootstrap-icons";
        font-size: 14px;
        color: var(--erp-primary, #1585c0);
    }
    .doc-tab:hover {
        background: #f8fafc !important;
        color: var(--erp-primary-dark, #0f4c75) !important;
    }
    .doc-tab.active {
        background: var(--peak-active-tab-bg, #eaedf1) !important;
        color: var(--erp-primary-dark, #0f4c75) !important;
        font-weight: 700 !important;
    }

    /* ── 2. ปุ่มทรงแคปซูล (Capsule Buttons ~40px) ───────────────────────────── */
    body:not(.erp-popup-page) .btn:not(.rounded-circle),
    .erp-capsule-btn,
    .flow-action-btn,
    .rpt-btn-filter,
    .doc-btn {
        border-radius: 9999px !important;
        min-height: 40px !important;
        padding: 6px 20px !important;
        font-size: 14px !important;
        font-weight: 600 !important;
        line-height: 1.25 !important;
        display: inline-flex;
        align-items: center !important;
        justify-content: center !important;
        gap: 6px !important;
        transition: all .15s ease !important;
        text-decoration: none !important;
    }
    .btn[style*="display: none"],
    .btn[style*="display:none"],
    .btn[hidden] {
        display: none !important;
    }

    /* ปุ่มขนาดเล็ก (.btn-sm) ในแถบกรองหรือในตาราง */
    body:not(.erp-popup-page) .btn-sm:not(.rounded-circle),
    .table tbody .btn:not(.rounded-circle),
    .doc-add {
        border-radius: 9999px !important;
        min-height: 32px !important;
        padding: 4px 14px !important;
        font-size: 13px !important;
        font-weight: 600 !important;
    }

    /* ปุ่มหลัก (Solid Primary MPM Blue / Emerald) */
    .btn-primary,
    .rpt-btn-filter {
        background: var(--erp-primary, #1585c0) !important;
        border: 1.5px solid var(--erp-primary, #1585c0) !important;
        color: #ffffff !important;
        box-shadow: 0 3px 8px rgba(21, 133, 192, .22) !important;
    }
    .btn-primary:hover,
    .btn-primary:focus,
    .rpt-btn-filter:hover {
        background: var(--erp-primary-dark, #0f4c75) !important;
        border-color: var(--erp-primary-dark, #0f4c75) !important;
        color: #ffffff !important;
        box-shadow: 0 4px 12px rgba(15, 76, 117, .28) !important;
    }

    .btn-success,
    .doc-btn-primary {
        background: var(--erp-success-ink, #158662) !important;
        border: 1.5px solid var(--erp-success-ink, #158662) !important;
        color: #ffffff !important;
        box-shadow: 0 3px 8px rgba(21, 134, 98, .22) !important;
    }
    .btn-success:hover,
    .btn-success:focus,
    .doc-btn-primary:hover {
        background: #0f6e4f !important;
        border-color: #0f6e4f !important;
        color: #ffffff !important;
    }

    /* ปุ่มรอง (ขอบสีหลัก พื้นขาว) */
    .btn-outline-primary,
    .btn-outline-secondary,
    .btn-outline-success,
    .flow-action-btn,
    .doc-add {
        background: #ffffff !important;
        border: 1.5px solid var(--erp-primary, #1585c0) !important;
        color: var(--erp-primary, #1585c0) !important;
        box-shadow: none !important;
    }
    .btn-outline-primary:hover,
    .btn-outline-secondary:hover,
    .btn-outline-success:hover,
    .flow-action-btn:hover,
    .doc-add:hover {
        background: var(--erp-primary-soft, #eef4f9) !important;
        border-color: var(--erp-primary-dark, #0f4c75) !important;
        color: var(--erp-primary-dark, #0f4c75) !important;
    }

    /* ปุ่มเทา/ขาวขอบเทาสำหรับปุ่มเครื่องมือและปุ่มยกเลิก */
    .btn-light,
    .btn-secondary,
    .erp-btn-cancel,
    .doc-btn-secondary {
        background: #ffffff !important;
        border: 1px solid #d1d5db !important;
        color: #334155 !important;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .04) !important;
    }
    .btn-light:hover,
    .btn-secondary:hover,
    .erp-btn-cancel:hover,
    .doc-btn-secondary:hover {
        background: #f1f5f9 !important;
        border-color: #94a3b8 !important;
        color: #0f172a !important;
    }

    /* ปุ่มยกเลิกใน Modal Footer: พื้นขาว/เทาอ่อน ขอบโค้งแคปซูลชัดเจน */
    .modal-footer .btn-light,
    .po-modal .btn-light.border,
    .doc-actions .doc-btn-secondary {
        background: #f8fafc !important;
        border: 1px solid #cbd5e1 !important;
        color: #475569 !important;
        min-width: 128px !important;
    }
    .modal-footer .btn-primary,
    .modal-footer .btn-success,
    .po-modal button[type="submit"],
    .doc-actions .doc-btn-primary {
        min-width: 144px !important;
    }

    /* ปุ่ม Disabled จางชัดเจน */
    .btn:disabled,
    .btn.disabled,
    button:disabled,
    a.btn.disabled {
        background: #f1f5f9 !important;
        border: 1px solid #e2e8f0 !important;
        color: #94a3b8 !important;
        box-shadow: none !important;
        opacity: 0.68 !important;
        cursor: not-allowed !important;
        pointer-events: none;
    }

    /* ── 3. ช่องกรอกแบบ Floating Label & Dropdown (Select) ─────────────────── */
    .form-control,
    .form-select,
    .doc-input,
    .doc-select,
    .rpt-date-input,
    .rpt-select {
        border-radius: var(--peak-field-radius, 8px) !important;
        border: 1px solid var(--peak-field-border, #d1d5db) !important;
        background-color: var(--peak-field-bg, #ffffff) !important;
        color: #1e293b !important;
        font-size: 14px !important;
        transition: border-color .15s ease, box-shadow .15s ease !important;
        box-shadow: none !important;
    }
    .form-control:hover,
    .form-select:hover,
    .doc-input:hover,
    .doc-select:hover {
        border-color: var(--peak-field-border-hover, #94a3b8) !important;
    }
    .form-control:focus,
    .form-select:focus,
    .doc-input:focus,
    .doc-select:focus,
    .rpt-date-input:focus,
    .rpt-select:focus {
        border-color: var(--erp-primary, #1585c0) !important;
        box-shadow: 0 0 0 3px rgba(21, 133, 192, .14) !important;
        background-color: #ffffff !important;
        outline: none !important;
    }

    /* โครง Floating Label อัตโนมัติเมื่อ <label> อยู่คู่กับ <input>/<select>/<textarea> */
    .erp-float-group,
    .erp-has-float {
        position: relative;
        display: block;
    }
    .erp-float-label,
    .erp-has-float > label:first-child {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 6px !important;
        width: 100% !important;
        position: relative !important;
        z-index: 3 !important;
        margin: 0 0 -23px 0 !important;
        padding: 6px 11px 0 12px !important;
        font-size: 11.5px !important;
        line-height: 1.25 !important;
        font-weight: 600 !important;
        color: var(--peak-label-color, #475569) !important;
        pointer-events: none !important;
        background: transparent !important;
    }
    .erp-fl-text {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        min-width: 0;
        flex: 1 1 auto;
        color: #334155;
    }
    .erp-fl-meta {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        flex: 0 0 auto;
        font-size: 11px;
        line-height: 1;
    }
    .erp-fl-counter {
        color: #94a3b8;
        font-weight: 500;
        font-variant-numeric: tabular-nums;
    }
    .erp-fl-req,
    .erp-has-float > label:first-child .text-danger,
    .erp-has-float > label:first-child .required {
        color: #dc2626 !important;
        font-weight: 700 !important;
        font-size: 13px !important;
        line-height: 1 !important;
    }

    .erp-float-control,
    .erp-has-float > .form-control,
    .erp-has-float > .form-select,
    .erp-has-float > .doc-input,
    .erp-has-float > .doc-select {
        min-height: var(--peak-field-h, 48px) !important;
        height: auto !important;
        padding: 21px 12px 5px 12px !important;
        border-radius: var(--peak-field-radius, 8px) !important;
        border: 1px solid var(--peak-field-border, #d1d5db) !important;
        background-color: #ffffff !important;
        font-size: 14px !important;
        font-weight: 500 !important;
        color: #0f172a !important;
        line-height: 1.35 !important;
    }
    .erp-has-float > textarea.form-control {
        min-height: 76px !important;
        padding-top: 24px !important;
    }

    /* Dropdown (Select) ในกรอบ Floating Label: ค่าที่เลือกเป็นสีหลักอ่านชัด + ลูกศร ˅ ขวา */
    .erp-has-float > select.form-select,
    .erp-has-float > select.doc-select,
    select.erp-float-control {
        padding-right: 34px !important;
        color: var(--erp-primary, #1585c0) !important;
        font-weight: 600 !important;
        background-position: right 11px bottom 10px !important;
        background-size: 13px 11px !important;
        cursor: pointer;
    }

    /* โฟกัสแล้วทั้งกรอบและป้ายชื่อเปลี่ยนสีรับกัน */
    .erp-has-float:focus-within > label:first-child .erp-fl-text,
    .erp-float-group:focus-within > .erp-float-label .erp-fl-text {
        color: var(--erp-primary, #1585c0) !important;
    }

    /* ช่องค้นหาที่มี input-group (เช่น หน้าสินค้า) ให้สูงเท่ากับช่อง Floating Label ข้างเคียง */
    .product-toolbar {
        align-items: flex-start !important;
        flex-wrap: wrap !important;
    }
    .product-filter-bar {
        grid-template-columns: minmax(220px, 1.45fr) repeat(3, minmax(132px, 1fr)) auto !important;
        align-items: start !important;
        min-width: 0 !important;
    }
    .product-filter-field:not(.search-field) {
        min-width: 0 !important;
    }
    .product-filter-field.search-field > label {
        display: flex !important;
        align-items: center !important;
        position: relative !important;
        z-index: 3 !important;
        margin: 0 0 -23px 0 !important;
        padding: 6px 12px 0 38px !important;
        font-size: 11.5px !important;
        font-weight: 600 !important;
        color: #475569 !important;
        pointer-events: none !important;
    }
    .product-filter-field.search-field .input-group {
        gap: 8px;
        align-items: stretch;
    }
    .product-filter-field.search-field .input-group > .input-group-text {
        border-radius: 8px 0 0 8px !important;
        border: 1px solid #d1d5db !important;
        border-right: 0 !important;
        background: #ffffff !important;
        color: #64748b !important;
        margin-right: -8px;
        padding: 0 10px 0 12px !important;
        min-height: 48px !important;
        z-index: 2;
    }
    .product-filter-field.search-field .input-group > .form-control {
        min-height: 48px !important;
        padding: 21px 12px 5px 6px !important;
        border-left: 0 !important;
        border-radius: 0 8px 8px 0 !important;
    }
    .product-filter-field.search-field .input-group > .btn {
        border-radius: 9999px !important;
        min-height: 40px !important;
        align-self: center !important;
        padding: 0 20px !important;
    }

    /* ── 4. Radio / Checkbox วงกลมสีหลัก จัดเป็นแถวแนวนอน ───────────────────── */
    .form-check-input {
        -webkit-appearance: none !important;
        appearance: none !important;
        width: 18px !important;
        height: 18px !important;
        border: 2px solid #94a3b8 !important;
        border-radius: 50% !important;
        background-color: #ffffff !important;
        cursor: pointer;
        position: relative;
        vertical-align: middle;
        margin-top: 0 !important;
        flex-shrink: 0;
        transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
    }
    .form-check-input:hover {
        border-color: var(--erp-primary, #1585c0) !important;
    }
    .form-check-input:checked {
        border-color: var(--erp-primary, #1585c0) !important;
        background-color: var(--erp-primary, #1585c0) !important;
        background-image: radial-gradient(circle, #ffffff 0%, #ffffff 36%, transparent 40%) !important;
        background-size: 100% 100% !important;
        background-position: center !important;
        background-repeat: no-repeat !important;
    }
    .form-check-input:focus {
        box-shadow: 0 0 0 3px rgba(21, 133, 192, .18) !important;
        outline: none !important;
    }
    .form-check {
        display: inline-flex !important;
        align-items: center !important;
        gap: 8px !important;
        padding-left: 0 !important;
        margin-right: 18px !important;
        margin-bottom: 4px !important;
        min-height: 28px;
    }
    .form-check .form-check-input {
        float: none !important;
        margin-left: 0 !important;
    }
    .form-check-label {
        cursor: pointer;
        font-size: 14px !important;
        font-weight: 500;
        color: #1e293b;
        user-select: none;
    }

    /* ── 5. Modal ฟอร์ม: หัวมีชื่อ + พื้นฐาน/ขั้นสูง + หัวข้อ Section ตัวหนา + ปุ่มแคปซูลขวาล่าง ── */
    html body .booking-modal,
    html body .po-modal {
        background: #ffffff !important;
        border: 1px solid #dbe3ef !important;
        border-radius: 14px !important;
        box-shadow: 0 24px 68px rgba(15, 23, 42, .22) !important;
        font-family: var(--erp-font-family) !important;
    }
    html body .booking-modal .modal-header,
    html body .po-modal > div:first-child {
        background: #ffffff !important;
        border-bottom: 1px solid #eef2f6 !important;
        padding: 18px 24px 12px 24px !important;
        border-radius: 14px 14px 0 0 !important;
    }
    html body .booking-modal .modal-header :is(h1, h2, h3, h4, h5, .modal-title),
    html body .po-modal h3 {
        font-size: 20px !important;
        font-weight: 800 !important;
        color: #0f172a !important;
    }
    html body .booking-modal .modal-body {
        background: #ffffff !important;
        padding: 18px 24px !important;
    }
    html body .booking-modal .modal-footer {
        background: #ffffff !important;
        border-top: 1px solid #eef2f6 !important;
        padding: 14px 24px 18px 24px !important;
        border-radius: 0 0 14px 14px !important;
        justify-content: flex-end !important;
        gap: 12px !important;
    }

    /* แถบสลับโหมด พื้นฐาน / ขั้นสูง ใต้ชื่อหัวเรื่อง Modal */
    .erp-modal-mode-toggle {
        display: inline-flex;
        align-items: center;
        gap: 18px;
        margin-top: 6px;
    }
    .erp-mode-option {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        font-size: 13.5px;
        font-weight: 600;
        color: #475569;
        cursor: pointer;
        margin: 0;
    }
    .erp-mode-option.is-active {
        color: var(--erp-primary-dark, #0f4c75);
        font-weight: 700;
    }

    /* หัวข้อแบ่ง Section ตัวหนาภายใน Modal ฟอร์ม */
    .erp-modal-section-heading {
        flex: 0 0 100%;
        width: 100%;
        font-size: 14px;
        font-weight: 800;
        color: #0f172a;
        padding-top: 8px;
        padding-bottom: 2px;
        margin-top: 4px;
        border-top: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .erp-modal-section-heading:first-child {
        border-top: 0;
        padding-top: 0;
        margin-top: 0;
    }
    [data-erp-form-mode="basic"] .erp-advanced-field {
        display: none !important;
    }

    /* ── 6. ตารางหัวคอลัมน์เทาอ่อน & ปุ่มคำสั่งในแถวเป็นแคปซูลเล็ก ─────────────── */
    .table thead th,
    .table > thead > tr > th,
    html[data-layout="odoo"] .table thead th,
    .report-data-table thead th {
        background: var(--peak-table-head-bg, #f1f5f9) !important;
        color: var(--peak-table-head-text, #334155) !important;
        border-bottom: 1px solid var(--peak-table-border, #e2e8f0) !important;
        border-top: 0 !important;
        font-weight: 700 !important;
        font-size: 13.5px !important;
        padding: 11px 12px !important;
    }
    .table > tbody > tr > td {
        border-bottom: 1px solid #f1f5f9;
        padding: 11px 12px;
    }
    .table > tbody > tr:nth-child(even) > td {
        background: #ffffff;
    }
    .table > tbody > tr:hover > td {
        background: #f8fafc !important;
    }

    @media (max-width: 991.98px) {
        .erp-peak-topcard-wrap {
            padding: 10px 14px 0 14px;
        }
        .erp-peak-topcard-tab {
            min-width: 96px;
            padding: 10px 12px;
            font-size: 13px;
        }
    }
</style>

<script>
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
</script>
