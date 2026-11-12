@once
@push('head')
<style>
    [x-cloak] { display: none !important; }
    .doc-shell { display: grid; gap: 16px; }
    .doc-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
    .doc-toolbar-title { display: flex; align-items: center; gap: 10px; }
    .doc-mark { width: 40px; height: 40px; border-radius: 8px; display: grid; place-items: center; background: #e0f7f4; color: #079083; font-size: 20px; }
    .doc-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
    .doc-tab { border-radius: 999px; padding: 7px 14px; border: 1px solid #dbe3ef; background: #fff; color: #526174; font-weight: 700; font-size: 13px; text-decoration: none; }
    .doc-tab.active { background: #0f172a; border-color: #0f172a; color: #fff; }
    .doc-modal-backdrop { position: fixed; inset: 0; z-index: 2050; background: rgba(15, 23, 42, .48); display: flex; align-items: center; justify-content: center; padding: 18px; }
    .doc-modal { width: min(1180px, 100%); max-height: calc(100vh - 36px); background: #fff; border-radius: 10px; box-shadow: 0 24px 70px rgba(15, 23, 42, .28); overflow: hidden; display: flex; flex-direction: column; }
    .doc-titlebar { height: 58px; display: flex; align-items: center; justify-content: space-between; padding: 0 16px 0 18px; border-bottom: 1px solid #e7edf5; background: #f8fafc; }
    .doc-title { font-size: 20px; font-weight: 900; color: #0f172a; line-height: 1.05; }
    .doc-subtitle { color: #64748b; font-size: 12px; margin-top: 3px; }
    .doc-close { width: 36px; height: 36px; border: 0; border-radius: 8px; background: #e9eef5; color: #475569; display: grid; place-items: center; }
    .doc-close:hover { background: #dbe3ef; }
    .doc-body { overflow: auto; padding: 14px 16px 10px; }
    .doc-head-grid { display: grid; grid-template-columns: 1.15fr .85fr; gap: 16px; margin-bottom: 12px; }
    .doc-card { border: 1px solid #e0e7f0; border-radius: 8px; background: #fff; }
    .doc-card-pad { padding: 12px; }
    .doc-fields { display: grid; grid-template-columns: repeat(12, 1fr); gap: 10px; align-items: end; }
    .doc-field { display: grid; gap: 4px; position: relative; }
    .doc-field label { font-size: 11px; color: #64748b; font-weight: 800; }
    .doc-field .required { color: #dc2626; }
    .doc-input, .doc-select { min-height: 34px; border: 1px solid #cfd8e6; border-radius: 7px; background: #fff; padding: 6px 9px; font-size: 13px; color: #0f172a; outline: none; width: 100%; }
    .doc-input:focus, .doc-select:focus { border-color: #0891b2; box-shadow: 0 0 0 2px rgba(8, 145, 178, .12); }
    .doc-typeahead { position: absolute; z-index: 3000; left: 0; right: 0; top: calc(100% + 4px); border: 1px solid #dbe3ef; border-radius: 8px; background: #fff; box-shadow: 0 14px 36px rgba(15, 23, 42, .16); padding: 4px; max-height: 245px; overflow: auto; }
    .doc-option { width: 100%; border: 0; background: transparent; display: grid; grid-template-columns: 84px 1fr auto; gap: 8px; align-items: center; text-align: left; padding: 7px 8px; border-radius: 6px; font-size: 12.5px; color: #1e293b; }
    .doc-option:hover { background: #eefafa; }
    .doc-code { color: #0284c7; font-weight: 900; font-size: 11.5px; }
    .stock-hint { color: #2563eb; font-size: 10.5px; font-weight: 700; }
    .doc-meta-line { display: grid; grid-template-columns: 110px 1fr; gap: 8px; align-items: center; margin-bottom: 8px; }
    .doc-meta-line span { color: #64748b; font-size: 12px; font-weight: 800; text-align: right; }
    .doc-meta-total { border-top: 1px dashed #dbe3ef; margin-top: 10px; padding-top: 10px; display: flex; justify-content: space-between; align-items: baseline; }
    .doc-total-label { font-size: 13px; color: #475569; font-weight: 800; }
    .doc-total-value { color: #059669; font-size: 28px; font-weight: 950; letter-spacing: 0; }
    .doc-items-head { display: flex; align-items: center; justify-content: space-between; padding: 9px 10px; border-bottom: 1px solid #e7edf5; background: #f8fafc; }
    .doc-items-head strong { font-size: 13px; color: #0f172a; }
    .doc-add { border: 1px solid #cfe4e8; color: #087f8c; background: #ecfeff; border-radius: 7px; padding: 5px 10px; font-weight: 800; font-size: 12px; }
    .doc-table-wrap { overflow: visible; }
    .doc-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .doc-table th { background: #f8fafc; border-bottom: 1px solid #dbe3ef; color: #64748b; font-size: 11px; font-weight: 900; padding: 6px; }
    .doc-table td { border-bottom: 1px solid #edf2f7; padding: 5px 6px; vertical-align: top; font-size: 13px; }
    .doc-table .row-no { width: 38px; text-align: center; color: #64748b; font-weight: 900; }
    .doc-table .qty { width: 110px; }
    .doc-table .price { width: 130px; }
    .doc-table .sum { width: 135px; }
    .doc-table .del { width: 42px; text-align: center; }
    .qty-control { display: flex; align-items: stretch; }
    .qty-control .doc-input { min-width: 0; border-radius: 0; }
    .qty-step { width: 30px; border: 1px solid #cfd8e6; background: #f8fafc; color: #0f766e; font-weight: 900; }
    .qty-step:first-child { border-radius: 7px 0 0 7px; }
    .qty-step:last-child { border-radius: 0 7px 7px 0; }
    .qty-step:hover { background: #dff7f3; }
    .doc-line-total { color: #059669; font-weight: 950; text-align: right; padding-top: 7px; }
    .doc-delete { border: 0; background: #fff1f2; color: #e11d48; width: 30px; height: 30px; border-radius: 7px; }
    .doc-details { margin-top: 10px; }
    .doc-details summary { cursor: pointer; color: #64748b; font-size: 12px; font-weight: 800; }
    .doc-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; border-top: 1px solid #e7edf5; background: #f8fafc; }
    .doc-footer-total { display: flex; align-items: baseline; gap: 10px; }
    .doc-actions { display: flex; gap: 8px; }
    .doc-btn { border: 0; border-radius: 8px; padding: 9px 18px; font-weight: 900; }
    .doc-btn-secondary { background: #e9eef5; color: #334155; }
    .doc-btn-primary { background: #079083; color: #fff; min-width: 155px; }
    .doc-btn-primary:hover { background: #047a70; }
    .doc-empty { text-align: center; padding: 42px 12px; color: #64748b; }

    /* รูปแบบเอกสารคลาสสิก: ใช้ร่วมกันกับใบขาย ซื้อ จอง และคืน */
    .doc-modal-backdrop { padding:20px; background:rgba(15,23,42,.48)!important; backdrop-filter:blur(2px)!important; }
    .doc-modal { width:min(1120px,100%); height:min(700px,calc(100vh - 40px)); max-height:none; border:1px solid #707780!important; border-radius:2px!important; background:#e9e9e9; box-shadow:0 22px 60px rgba(15,23,42,.36)!important; font-family:Tahoma,"Noto Sans Thai",sans-serif; font-size:11.5px; }
    .doc-titlebar { flex:0 0 40px; height:40px; padding:0 8px 0 11px; border-bottom:1px solid #9ba0a5; background:#fafafa; }
    .doc-title { font-size:14px; font-weight:700; color:#111; }
    .doc-subtitle { display:none; }
    .doc-close { width:30px; height:29px; border-radius:0; background:transparent; color:#333; }
    .doc-close:hover { background:#ddd; }
    .doc-commandbar { flex:0 0 56px; display:flex; align-items:stretch; padding:3px 9px; border-bottom:1px solid #999; background:#ddd; }
    .doc-commandbar button { width:88px; display:grid; place-items:center; align-content:center; gap:0; border:0; border-right:1px solid #aaa; background:transparent; color:#111; font-size:10px; }
    .doc-commandbar button:hover { background:#f8f8f8; }
    .doc-commandbar i { color:#0787c2; font-size:18px; line-height:20px; }
    .doc-modal form { min-height:0; flex:1; display:flex; flex-direction:column; }
    .doc-body { min-height:0; flex:1; padding:7px 9px; background:#ededed; }
    .doc-head-grid { grid-template-columns:1.3fr .7fr; gap:6px; margin-bottom:6px; }
    .doc-card { border:1px solid #9ca3a9; border-radius:0; background:#f5f5f5; }
    .doc-card-pad { padding:8px; }
    .doc-fields { gap:6px 8px; }
    .doc-field { gap:2px; }
    .doc-field label { color:#222; font-size:10.5px; font-weight:700; }
    .doc-input,.doc-select { min-height:28px; height:28px; border:1px solid #8f969c; border-radius:0; padding:3px 6px; font-size:11.5px; color:#111; }
    .doc-input:focus,.doc-select:focus { border-color:#168dcc; box-shadow:inset 0 0 0 1px #168dcc; }
    .doc-details { margin-top:6px; }
    .doc-details summary { color:#333; font-size:10.5px; }
    .doc-meta-line { grid-template-columns:100px 1fr; gap:7px; margin-bottom:5px; }
    .doc-meta-line span { color:#444; font-size:10.5px; }
    .doc-meta-line strong { font-size:11px; }
    .doc-meta-total { margin-top:6px; padding-top:6px; border-top:1px solid #aaa; }
    .doc-total-label { color:#222; font-size:11px; }
    .doc-total-value { color:#111; font-size:18px; font-weight:800; }
    .doc-items-head { padding:5px 7px; border-bottom:1px solid #999; background:linear-gradient(#fafafa,#ddd); }
    .doc-items-head strong { color:#111; font-size:11px; }
    .doc-add { border:1px solid #888; border-radius:0; padding:3px 9px; background:linear-gradient(#fff,#ddd); color:#111; font-size:10.5px; }
    .doc-table th { padding:5px 6px; border-right:1px solid #aaa; border-bottom:1px solid #888; background:linear-gradient(#fafafa,#d9d9d9); color:#111; font-size:10.5px; }
    .doc-table td { padding:3px 5px; border-right:1px solid #d0d0d0; border-bottom:1px solid #c7c7c7; background:#fff; font-size:11px; }
    .doc-table tbody tr:nth-child(even) td { background:#f2f5f7; }
    .doc-table .row-no { width:32px; color:#333; }
    .doc-table .qty { width:95px; }
    .doc-table .price { width:112px; }
    .doc-table .sum { width:120px; }
    .doc-table .del { width:34px; }
    .qty-step { border-color:#8f969c; background:linear-gradient(#fff,#ddd); color:#111; }
    .doc-line-total { padding-top:6px; color:#111; font-weight:700; }
    .doc-delete { width:25px; height:25px; border:1px solid #aaa; border-radius:0; background:#eee; color:#b91c1c; }
    .doc-typeahead { top:calc(100% + 1px); border:1px solid #777; border-radius:0; padding:0; box-shadow:4px 6px 18px rgba(0,0,0,.22); }
    .doc-option { border-radius:0; padding:5px 7px; font-size:11px; }
    .doc-footer { flex:0 0 43px; padding:5px 9px; border-top:1px solid #999; background:#e5e5e5; }
    .doc-footer .doc-total-value { font-size:17px; }
    .doc-btn { min-width:84px; height:29px; border:1px solid #888; border-radius:0; padding:3px 12px; background:linear-gradient(#fff,#ddd); color:#111; font-size:11px; font-weight:700; }
    .doc-btn-primary { min-width:125px; border-color:#777; background:linear-gradient(#fff,#d7d7d7); color:#111; }
    .doc-btn-primary:hover,.doc-btn-secondary:hover { background:#dceeff; color:#111; }
    .doc-modal { width:min(1500px,calc(100vw - 32px)); height:calc(100dvh - 32px); }
    @media (max-width: 768px) {
        .doc-modal { width:100vw; height:100dvh; border-radius:0; }
        .doc-head-grid { grid-template-columns:1fr; }
        .doc-fields > * { grid-column:1 / -1 !important; }
        .doc-table { min-width:640px; }
    }
    @media (max-width: 980px) {
        .doc-head-grid { grid-template-columns: 1fr; }
        .doc-fields { grid-template-columns: repeat(6, 1fr); }
        .doc-table { min-width: 780px; }
        .doc-table-wrap { overflow-x: auto; }
        .doc-footer { flex-direction: column; align-items: stretch; }
        .doc-actions { justify-content: flex-end; }
    }
</style>
@endpush

@push('scripts')
<script src="{{ asset('document-workflow.js') }}"></script>
<script>
function docEntryPage(config) {
    const stockRequests = new Map();
    return {
        ...erpProductPicker(),
        ...erpDocumentDraft(@js((string) auth()->id())),
        modalOpen: Boolean(config.openInitially),
        config,
        partyQuery: config.initialPartyQuery || '',
        partyId: config.initialPartyId ? String(config.initialPartyId) : '',
        partyResults: [],
        sourceDocNumber: '',
        sourceInfo: '',
        branchId: (config.initialBranchId || config.defaultBranchId) ? String(config.initialBranchId || config.defaultBranchId) : '',
        salesAreaId: (config.initialSalesAreaId || config.defaultSalesAreaId) ? String(config.initialSalesAreaId || config.defaultSalesAreaId) : '',
        salesUserId: (config.initialSalesUserId || config.defaultSalesUserId) ? String(config.initialSalesUserId || config.defaultSalesUserId) : '',
        salesUserName: config.defaultSalesUserName || '',
        items: config.initialItems?.length ? config.initialItems.map(item => ({
            product_id: item.product_id,
            sku_code: item.sku_code || '',
            name_th: item.name_th || '',
            barcode: item.barcode || '',
            productQuery: item.product_query || '',
            qty: Number(item.qty) || 1,
            unit_qty: Number(item.unit_qty !== undefined ? item.unit_qty : (item.qty || 1)),
            unit_id: item.unit_id || '',
            unit_factor: Number(item.unit_factor) || 1,
            unit_price: Number(item.unit_price) || 0,
            unit_name: item.unit_name || 'หน่วยฐาน',
            base_unit_id: item.base_unit_id || '',
            base_unit_name: item.base_unit_name || item.unit_name || 'หน่วยฐาน',
            units: item.units || [],
            priceWarning: '',
            is_scale: Boolean(item.is_scale),
            scale_plu: item.scale_plu || '',
            lot_number: '', manufacture_date: '', expiry_date: '', tracks_expiry: false,
            shelf_life_days: null, on_hand_qty: null, reserved_qty: null, available_qty: null,
            source_stock_lot_id: '', return_disposition: 'quarantine', lots: [],
        })) : [{ product_id: '', sku_code: '', name_th: '', barcode: '', productQuery: '', qty: 1, unit_qty: 1, unit_id: '', unit_factor: 1, unit_price: 0, unit_name: '', base_unit_id: '', base_unit_name: '', units: [], priceWarning: '', is_scale: false, scale_plu: '', lot_number: '', manufacture_date: '', expiry_date: '', tracks_expiry: false, shelf_life_days: null, on_hand_qty: null, reserved_qty: null, available_qty: null, source_stock_lot_id: '', return_disposition: 'quarantine', lots: [] }],
        init() {
            this.$nextTick(() => {
                this.startDraft();
                if (this.config.openInitially) this.onBranchChanged();
            });
        },
        openModal() {
            this.modalOpen = true;
            this.$nextTick(() => {
                if (!this.branchId) {
                    const branch = this.$root.querySelector('.doc-modal [name="branch_id"]');
                    this.branchId = branch?.value || '';
                }
                if (!this.salesAreaId && this.config.defaultSalesAreaId) this.salesAreaId = String(this.config.defaultSalesAreaId);
                if (!this.salesUserId && this.config.defaultSalesUserId) this.salesUserId = String(this.config.defaultSalesUserId);
                if (!this.salesUserName) this.salesUserName = this.config.defaultSalesUserName || '';
                this.onBranchChanged();
            });
        },
        async closeModal() {
            if (this.pickerOpen) return;
            if (this.submitting) return;
            if (this.draftDirty && !(await window.erpConfirm('ปิดฟอร์มเอกสาร?', 'ยังไม่ได้บันทึกเอกสาร ต้องการปิดฟอร์มหรือไม่? ร่างจะเก็บในแท็บนี้', { tone: 'warning' })).isConfirmed) return;
            this.saveDraft();
            this.modalOpen = false;
        },
        // ใบรับคืน: ดึงลูกค้าและรายการจากใบขายต้นทาง จำนวนตั้งต้น = จำนวนที่ยังคืนได้
        async loadReturnSource() {
            const docNumber = this.sourceDocNumber.trim();
            if (!docNumber || !this.config.returnSourceUrl) return;
            const response = await fetch(`${this.config.returnSourceUrl}?doc_number=${encodeURIComponent(docNumber)}`, { headers: { Accept: 'application/json' } });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                this.sourceInfo = '';
                window.erpToast('warning', 'ไม่พบใบขาย', { text: data.message || `ไม่พบใบขายเลขที่ ${docNumber}`, timer: 4000 });
                return;
            }
            if (data.branch_id) { this.branchId = String(data.branch_id); }
            this.partyId = data.customer_id ? String(data.customer_id) : '';
            this.partyQuery = data.customer_name || 'ลูกค้าทั่วไป (ไม่ระบุ)';
            const lines = (data.lines || []).filter(line => line.returnable > 0);
            this.items = lines.map(line => ({
                product_id: line.product_id, productQuery: `${line.sku_code} - ${line.name_th}`,
                sku_code: line.sku_code, name_th: line.name_th,
                qty: line.returnable, unit_price: line.unit_price, unit_name: '', is_scale: false, scale_plu: '',
                lot_number: '', manufacture_date: '', expiry_date: '', tracks_expiry: false, shelf_life_days: null,
                on_hand_qty: null, reserved_qty: null, available_qty: null, source_stock_lot_id: '',
                return_disposition: 'quarantine', lots: [],
            }));
            this.sourceInfo = lines.length
                ? `ใบขาย ${data.doc_number}: คืนได้อีก ${lines.length} รายการ · วงเงินคืน ${Number(data.refundable_amount).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 4 })} บาท (ห้ามเกินวงเงิน)`
                : `ใบขาย ${data.doc_number} คืนครบทุกรายการแล้ว`;
            if (!this.items.length) this.addItem();
        },
        async searchParty() {
            this.partyId = '';
            this.partySearchController?.abort();
            const sequence = this.partySearchSequence = (this.partySearchSequence || 0) + 1;
            this.partyResults = [];
            if (!this.partyQuery.trim()) return;
            const controller = new AbortController();
            this.partySearchController = controller;
            const query = this.partyQuery;
            const url = this.config.partyType === 'supplier' ? '{{ route('search.suppliers') }}' : '{{ route('search.customers') }}';
            try {
                const response = await fetch(`${url}?q=${encodeURIComponent(query)}`, { signal: controller.signal });
                if (!response.ok) throw new Error('search');
                const rows = await response.json();
                if (sequence === this.partySearchSequence && query === this.partyQuery) this.partyResults = rows;
            } catch (error) {
                if (error.name !== 'AbortError' && sequence === this.partySearchSequence) {
                    window.erpToast?.('warning', 'ค้นหาไม่สำเร็จ กรุณาลองใหม่');
                }
            }
        },
        selectParty(party) {
            this.partySearchController?.abort();
            this.partySearchSequence = (this.partySearchSequence || 0) + 1;
            this.partyId = party.id;
            this.partyQuery = `${party.code} - ${party.name_th}`;
            if (this.config.salesUserFromParty !== false) {
                this.salesUserId = party.sales_user_id
                    ? String(party.sales_user_id)
                    : String(this.config.defaultSalesUserId || '');
                this.salesUserName = party.sales_user_name || this.config.defaultSalesUserName || '';
                this.salesAreaId = party.sales_area_id
                    ? String(party.sales_area_id)
                    : String(this.config.defaultSalesAreaId || '');
            }
            if (this.config.salesAreaFromParty && party.sales_area_id) {
                this.salesAreaId = String(party.sales_area_id);
                this.onSalesAreaChanged();
            }
            this.partyResults = [];
        },
        onBranchChanged() {
            const areas = this.config.salesAreas || [];
            if (this.salesAreaId) {
                const selected = areas.find(item => String(item.id) === String(this.salesAreaId));
                if (selected?.branch_id && String(selected.branch_id) !== String(this.branchId)) this.salesAreaId = '';
            }
            this.items.forEach((item, index) => {
                if (item.product_id) this.refreshProductStock(index);
            });
        },
        onSalesAreaChanged() {
            const area = (this.config.salesAreas || []).find(item => String(item.id) === String(this.salesAreaId));
            if (!area) return;
            if (area.branch_id) {
                this.branchId = String(area.branch_id);
                this.onBranchChanged();
            }
        },
        addItem() { this.items.push({ product_id: '', sku_code: '', name_th: '', barcode: '', productQuery: '', qty: 1, unit_qty: 1, unit_id: '', unit_factor: 1, unit_price: 0, unit_name: '', base_unit_id: '', base_unit_name: '', units: [], priceWarning: '', is_scale: false, scale_plu: '', lot_number: '', manufacture_date: '', expiry_date: '', tracks_expiry: false, shelf_life_days: null, on_hand_qty: null, reserved_qty: null, available_qty: null, source_stock_lot_id: '', return_disposition: 'quarantine', lots: [] }); },
        removeItem(index) { this.items.splice(index, 1); },
        quantityStep(item) { return item.is_scale ? 0.01 : 1; },
        adjustQty(index, direction) {
            const item = this.items[index];
            const step = this.quantityStep(item);
            const current = Number(item.unit_qty !== undefined ? item.unit_qty : item.qty) || 0;
            const next = Math.max(step, current + (step * direction));
            item.unit_qty = Number(next.toFixed(item.is_scale ? 2 : 0));
            item.qty = item.unit_qty * (item.unit_factor || 1);
        },
        onUnitChanged(index) {
            const item = this.items[index];
            const selectedUnit = (item.units || []).find(u => String(u.unit_id) === String(item.unit_id));
            if (selectedUnit) {
                const oldFactor = item.unit_factor || 1;
                const basePrice = (Number(item.unit_price) || 0) / oldFactor;
                item.unit_name = selectedUnit.name;
                item.unit_factor = Number(selectedUnit.factor) || 1;
                item.unit_price = Number((basePrice * item.unit_factor).toFixed(2));
                item.qty = (Number(item.unit_qty) || 0) * item.unit_factor;
            }
            this.checkPriceDivisibility(index);
        },
        updateLineFromUnitQty(index) {
            const item = this.items[index];
            item.qty = (Number(item.unit_qty) || 0) * (item.unit_factor || 1);
        },
        checkPriceDivisibility(index) {
            const item = this.items[index];
            const factor = item.unit_factor || 1;
            if (factor > 1) {
                const typed = Number(item.unit_price) || 0;
                const perBase = Math.round((typed / factor) * 10000) / 10000;
                if (Math.abs((perBase * factor) - typed) > 0.0001) {
                    item.priceWarning = `ราคาหาร ${factor} ไม่ลงตัวที่ 4 ทศนิยม`;
                } else {
                    item.priceWarning = '';
                }
            } else {
                item.priceWarning = '';
            }
        },
        formatQuantity(item, value) {
            const quantity = Number(value || 0);
            return item.is_scale
                ? quantity.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                : quantity.toLocaleString('th-TH', { maximumFractionDigits: 2 });
        },
        pickerSearchParams(params) {
            params.set('include_lots', this.config.returnLots ? '1' : '0');
            params.set('branch_id', this.branchId || '');
        },
        cancelStockRequests() {
            stockRequests.forEach(controller => controller.abort());
            stockRequests.clear();
        },
        async refreshProductStock(index) {
            const item = this.items[index];
            if (!item?.product_id || !this.branchId || !this.config.showStock) return;
            stockRequests.get(item)?.abort();
            const controller = new AbortController();
            stockRequests.set(item, controller);
            const branch = this.branchId;
            const productId = item.product_id;
            try {
                const response = await fetch(`{{ route('search.products') }}?product_id=${encodeURIComponent(productId)}&branch_id=${encodeURIComponent(branch)}`, { signal: controller.signal });
                if (!response.ok) return;
                const products = await response.json();
                const product = products[0];
                if (branch !== this.branchId || productId !== item.product_id || !this.items.includes(item)) return;
                if (!product || String(product.id) !== String(item.product_id)) return;
                item.on_hand_qty = product.on_hand_qty;
                item.reserved_qty = product.reserved_qty;
                item.available_qty = product.available_qty;
            } catch (error) {
                if (error.name !== 'AbortError') window.erpToast?.('warning', 'โหลดสต๊อกไม่สำเร็จ กรุณาลองใหม่');
            } finally {
                if (stockRequests.get(item) === controller) stockRequests.delete(item);
            }
        },
        onProductPicked(index, product) {
            const item = this.items[index];
            item.product_id = product.id;
            item.productQuery = `${product.sku_code} - ${product.name_th}`;
            item.sku_code = product.sku_code || '';
            item.name_th = product.name_th || '';
            item.barcode = product.barcode || '';
            item.base_unit_id = product.base_unit_id;
            item.base_unit_name = product.base_unit_name || product.unit_name || 'หน่วยฐาน';
            item.units = product.units || [];
            item.is_scale = Boolean(product.is_scale);
            item.scale_plu = product.scale_plu || '';
            item.tracks_expiry = Boolean(product.tracks_expiry);
            item.shelf_life_days = product.shelf_life_days;
            item.on_hand_qty = product.on_hand_qty;
            item.reserved_qty = product.reserved_qty;
            item.available_qty = product.available_qty;
            item.lots = product.lots || [];

            let selectedUnit = null;
            if (product.matched_unit_id && item.units.length) {
                selectedUnit = item.units.find(u => Number(u.unit_id) === Number(product.matched_unit_id));
            }
            if (!selectedUnit && item.units.length) {
                selectedUnit = item.units.find(u => Number(u.unit_id) === Number(product.base_unit_id)) || item.units[0];
            }

            if (selectedUnit) {
                item.unit_id = selectedUnit.unit_id;
                item.unit_name = selectedUnit.name;
                item.unit_factor = Number(selectedUnit.factor) || 1;
                item.unit_price = Number((Number(product.default_price || 0) * item.unit_factor).toFixed(2));
            } else {
                item.unit_id = product.base_unit_id || '';
                item.unit_name = product.unit_name || 'หน่วยฐาน';
                item.unit_factor = 1;
                item.unit_price = Number(product.default_price || 0);
            }

            item.unit_qty = 1;
            item.qty = item.unit_qty * item.unit_factor;
            item.priceWarning = '';
        },
        calculateExpiry(index) {
            const item = this.items[index];
            if (!item.manufacture_date || !item.shelf_life_days) return;
            const expiry = new Date(item.manufacture_date + 'T00:00:00');
            expiry.setDate(expiry.getDate() + Number(item.shelf_life_days));
            item.expiry_date = expiry.toISOString().slice(0, 10);
        },
        get totalQty() { return this.items.reduce((sum, item) => sum + (Number(item.qty) || 0), 0); },
        get totalAmount() { return this.items.reduce((sum, item) => sum + (Number(item.unit_qty !== undefined ? item.unit_qty : item.qty) || 0) * (Number(item.unit_price) || 0), 0); },
        money(value) { return Number(value || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        submitting: false,
        onSubmit(event) {
            if (this.submitting) { event.preventDefault(); return; }
            if (this.items.some(item => item.priceWarning)) {
                event.preventDefault();
                if (window.erpToast) {
                    window.erpToast('error', 'ราคาต่อหน่วยหารไม่ลงตัว', { text: 'กรุณาแก้ไขราคาต่อหน่วยให้หารลงตัวที่ 4 ทศนิยมก่อนบันทึก' });
                } else {
                    Swal.fire({ icon: 'error', title: 'ราคาต่อหน่วยหารไม่ลงตัว', text: 'กรุณาแก้ไขราคาต่อหน่วยให้หารลงตัวที่ 4 ทศนิยมก่อนบันทึก' });
                }
                return;
            }
            if (this.config.partyRequired && !this.partyId) {
                event.preventDefault();
                const title = `เลือก${this.config.partyLabel}ก่อน`;
                window.erpToast('warning', title);
                return;
            }
            if (this.items.some(item => !item.product_id)) {
                event.preventDefault();
                const title = 'เลือกสินค้าให้ครบทุกแถว';
                window.erpToast('warning', title);
                return;
            }
            // จำนวนเกินสต๊อกให้ ERP ตัดสินตามนโยบายสาขา หน้าจอแค่แจ้งเตือน ไม่บล็อกปุ่มบันทึก
            if (!event.target.reportValidity()) { event.preventDefault(); return; }
            this.saveDraft();
            this.markDraftSubmitted();
            this.submitting = true;
        },
    };
}
</script>
@endpush
@endonce
