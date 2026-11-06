window.erpStockPreview = function (branches, url) {
    return {
        branchId: '', locationId: '',
        init() {
            this.branchId = String(this.$el.querySelector('[name="branch_id"]')?.value || branches[0]?.id || '');
            this.locationId = String(this.$el.querySelector('[name="warehouse_location_id"]')?.value || '');
            this.$watch('branchId', () => { this.locationId = ''; this.refreshStockRows(); });
            this.$watch('locationId', () => this.refreshStockRows());
        },
        stockRows() { return this.rawItems || this.items || []; },
        stockPreviewReady() { return this.stockRows().every(item => item.product_id && item.previewReady); },
        refreshStockRows() { this.stockRows().filter(item => item.product_id).forEach(item => this.previewStock(item)); },
        async previewStock(item) {
            if (!item) return;
            const key = `${this.branchId}:${this.locationId}:${item.product_id}`;
            const revision = (item.previewRevision || 0) + 1;
            item.previewRevision = revision;
            item.previewKey = key;
            item.previewReady = false;
            item.systemQty = null;
            item.onHandQty = '—';
            item.average_cost = null;
            if (!item.product_id || !this.branchId) return;
            const params = new URLSearchParams({ product_id: item.product_id, branch_id: this.branchId });
            if (this.locationId) params.set('warehouse_location_id', this.locationId);
            try {
                const response = await fetch(`${url}?${params}`, { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('Stock preview unavailable');
                const data = await response.json();
                if (item.previewRevision !== revision) return;
                if (!Number.isFinite(Number(data.on_hand_qty)) || (data.average_cost !== null && !Number.isFinite(Number(data.average_cost)))) throw new Error('Invalid stock preview');
                item.systemQty = Number(data.on_hand_qty);
                if ('counted_qty' in item) item.counted_qty = item.systemQty;
                item.onHandQty = Number(data.on_hand_qty).toLocaleString('th-TH', { maximumFractionDigits: 8 });
                item.average_cost = Number(data.average_cost);
                item.previewReady = true;
            } catch (_) {
                if (item.previewRevision === revision) item.previewReady = false;
            }
        },
    };
};
