<script>
(() => {
    // One confirmation path for forms, submit buttons and links; never native confirm().
    let approved = null;
    const pending = new WeakSet();
    document.addEventListener('submit', async event => {
        const form = event.target;
        const submitter = event.submitter;
        const trigger = submitter?.matches('[data-confirm]') ? submitter : form;
        if (!trigger.dataset.confirm || approved === form) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (pending.has(form)) return;
        pending.add(form);
        let result;
        try {
            result = await window.erpConfirm(trigger.dataset.confirm, trigger.dataset.confirmText || '', {
                confirmButtonText: trigger.dataset.confirmOk || 'ยืนยัน',
                icon: trigger.dataset.confirmIcon || 'warning',
            });
        } finally { pending.delete(form); }
        if (!result.isConfirmed || !form.isConnected) return;
        approved = form;
        try { form.requestSubmit(submitter || undefined); } finally { approved = null; }
    }, true);
    document.addEventListener('click', async event => {
        const link = event.target.closest('a[data-confirm]');
        if (!link) return;
        event.preventDefault();
        const result = await window.erpConfirm(link.dataset.confirm);
        if (result.isConfirmed) window.location.assign(link.href);
    });
})();
</script>
