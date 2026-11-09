/* Tab-local recovery only: never creates a business document or stores credentials. */
window.erpDocumentDraft = function (userId) {
    let form, baseline, key, onChange, onLeave, onLink;
    const stateFields = ['items', 'partyId', 'partyQuery', 'branchId', 'salesAreaId', 'salesUserId', 'salesUserName'];
    return {
        draftDirty: false,
        draftSnapshot() {
            const state = Object.fromEntries(stateFields.map(name => [name, this[name]]));
            const fields = [...form.elements].filter(el => el.name && !el.name.startsWith('items[')
                && !['_token', '_method'].includes(el.name) && !['password', 'file', 'hidden', 'submit', 'button'].includes(el.type))
                .map(el => [el.name, el.value, el.checked]);
            return JSON.stringify({ state, fields }, (name, value) => ['on_hand_qty', 'reserved_qty', 'available_qty', 'lots'].includes(name) ? undefined : value);
        },
        startDraft() {
            if (!this.config?.draftEnabled) return;
            form = this.$root.querySelector('.doc-modal form, .po-modal form');
            if (!form) return;
            key = `erp:draft:v1:${userId}:${location.pathname}:${form.getAttribute('action')}`;
            baseline = this.draftSnapshot();
            try {
                const stored = JSON.parse(sessionStorage.getItem(key) || 'null');
                if (stored && Date.now() - stored.time < 8 * 60 * 60 * 1000) {
                    if (window.confirm('พบร่างที่ยังไม่ได้บันทึกในแท็บนี้ ต้องการกู้คืนหรือไม่?')) {
                        const draft = JSON.parse(stored.snapshot);
                        stateFields.forEach(name => { if (draft.state[name] !== undefined) this[name] = draft.state[name]; });
                        this.modalOpen = true;
                        this.$nextTick(() => {
                            for (const [name, value, checked] of draft.fields) {
                                for (const el of form.elements) {
                                    if (el.name !== name) continue;
                                    el.value = value; el.checked = checked;
                                    el.dispatchEvent(new Event('input', { bubbles: true }));
                                    el.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            }
                            this.saveDraft();
                        });
                    } else sessionStorage.removeItem(key);
                } else if (stored && Date.now() - stored.time >= 8 * 60 * 60 * 1000) sessionStorage.removeItem(key);
            } catch (_) { /* Storage unavailable: keep the in-memory form usable. */ }
            onChange = () => this.saveDraft();
            form.addEventListener('input', onChange);
            form.addEventListener('change', onChange);
            stateFields.filter(name => name in this).forEach(name => this.$watch(name, onChange));
            onLeave = event => {
                if (!this.draftDirty || this.submitting) return;
                this.saveDraft(); event.preventDefault(); event.returnValue = '';
            };
            onLink = event => {
                const link = event.target.closest('a[href]');
                if (!link || link.target === '_blank' || link.getAttribute('href').startsWith('#') || !this.draftDirty || this.submitting) return;
                if (!window.confirm('ยังไม่ได้บันทึกเอกสาร ต้องการออกจากหน้านี้หรือไม่?')) event.preventDefault();
            };
            window.addEventListener('beforeunload', onLeave);
            document.addEventListener('click', onLink);
        },
        saveDraft() {
            if (!form || this.submitting) return;
            const snapshot = this.draftSnapshot();
            this.draftDirty = snapshot !== baseline;
            try {
                if (this.draftDirty) sessionStorage.setItem(key, JSON.stringify({ time: Date.now(), snapshot }));
                else sessionStorage.removeItem(key);
            } catch (_) {
                window.erpToast?.('warning', 'เก็บร่างในเบราว์เซอร์ไม่ได้ กรุณาบันทึกเอกสารก่อนออกจากหน้า');
            }
        },
        markDraftSubmitted() {
            try { if (key) sessionStorage.setItem(`erp:draft:pending:${userId}`, JSON.stringify({ key, action: form.getAttribute('action') })); } catch (_) {}
        },
        destroy() {
            this.saveDraft();
            form?.removeEventListener('input', onChange);
            form?.removeEventListener('change', onChange);
            window.removeEventListener('beforeunload', onLeave);
            document.removeEventListener('click', onLink);
            this.cancelStockRequests?.();
            this.partySearchController?.abort();
            this.pickerSearchController?.abort();
        },
    };
};
