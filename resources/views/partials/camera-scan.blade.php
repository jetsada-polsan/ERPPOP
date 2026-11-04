{{--
    ตัวสแกนกลางของ ERP: ใส่ data-scan ที่ช่อง input ไหนก็ได้ จะมีปุ่มกล้องต่อท้ายให้เอง
    สแกนได้แล้วใส่ค่าลงช่อง แล้วกด Enter ให้ (ค้นหา/เพิ่มรายการเหมือนเครื่องยิงบาร์โค้ด)
    ใช้ BarcodeDetector ของเบราว์เซอร์ก่อน ไม่มีค่อยโหลด html5-qrcode (ZXing) ตอนกดเท่านั้น
    เครื่องยิงแบบ HID พิมพ์ลงช่องแล้วตามด้วย Enter อยู่แล้ว ไม่ต้องเปิดกล้อง
--}}
<style>
    .erp-scan-btn { display:inline-flex; align-items:center; justify-content:center; min-width:42px; border:1px solid var(--erp-border,#dbe7ef); background:#fff; color:var(--erp-primary-dark,#0f4c75); border-radius:7px; margin-left:6px; cursor:pointer; }
    .erp-scan-btn:hover { background:var(--erp-primary-soft,#eef4f9); }
    .input-group > .erp-scan-btn { margin-left:0; border-radius:0 7px 7px 0; }
    .erp-scan-sheet { position:fixed; inset:0; z-index:5000; background:rgba(15,23,42,.72); display:flex; align-items:center; justify-content:center; padding:16px; }
    .erp-scan-box { width:min(520px,100%); background:#fff; border-radius:14px; overflow:hidden; }
    .erp-scan-box header { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; font-weight:800; font-size:17px; border-bottom:1px solid #dbe7ef; }
    .erp-scan-box header button { border:0; background:none; font-size:24px; line-height:1; cursor:pointer; }
    .erp-scan-view { position:relative; background:#000; aspect-ratio:4/3; }
    .erp-scan-view video, .erp-scan-view #erp-scan-fallback { width:100%; height:100%; object-fit:cover; }
    .erp-scan-view::after { content:""; position:absolute; left:12%; right:12%; top:35%; bottom:35%; border:3px solid #22c55e; border-radius:10px; pointer-events:none; }
    .erp-scan-box footer { padding:10px 16px; font-size:14px; color:#5b6b78; }
</style>
<script>
(function () {
    const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'qr_code', 'itf'];
    let stream = null, timer = null, fallback = null, sheet = null;

    function loadFallback() {
        if (window.Html5Qrcode) return Promise.resolve();
        return new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
            s.onload = resolve; s.onerror = () => reject(new Error('โหลดตัวสแกนไม่ได้ ตรวจอินเทอร์เน็ต'));
            document.head.appendChild(s);
        });
    }

    async function stop() {
        clearInterval(timer); timer = null;
        stream?.getTracks().forEach((t) => t.stop()); stream = null;
        if (fallback) { try { await fallback.stop(); } catch (e) {} fallback = null; }
        sheet?.remove(); sheet = null;
    }

    function deliver(input, code) {
        stop();
        input.value = code;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', keyCode: 13, which: 13, bubbles: true }));
        input.dispatchEvent(new KeyboardEvent('keyup', { key: 'Enter', code: 'Enter', keyCode: 13, which: 13, bubbles: true }));
        input.focus();
    }

    async function open(input) {
        await stop();
        sheet = document.createElement('div');
        sheet.className = 'erp-scan-sheet';
        sheet.innerHTML = '<div class="erp-scan-box" role="dialog" aria-label="สแกนบาร์โค้ด"><header><span>สแกนบาร์โค้ด / QR</span><button type="button" aria-label="ปิด">×</button></header><div class="erp-scan-view"><video playsinline muted></video></div><footer>เล็งบาร์โค้ดให้อยู่ในกรอบสีเขียว</footer></div>';
        document.body.appendChild(sheet);
        sheet.querySelector('header button').onclick = stop;
        sheet.addEventListener('click', (e) => { if (e.target === sheet) stop(); });
        const note = sheet.querySelector('footer');
        try {
            if ('BarcodeDetector' in window) {
                const supported = await BarcodeDetector.getSupportedFormats();
                const detector = new BarcodeDetector({ formats: FORMATS.filter((f) => supported.includes(f)) });
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                const video = sheet.querySelector('video');
                video.srcObject = stream; await video.play();
                timer = setInterval(async () => {
                    try { const found = await detector.detect(video); if (found[0]?.rawValue) deliver(input, found[0].rawValue.trim()); } catch (e) {}
                }, 250);
                return;
            }
            await loadFallback();
            const view = sheet.querySelector('.erp-scan-view');
            view.innerHTML = '<div id="erp-scan-fallback"></div>';
            fallback = new Html5Qrcode('erp-scan-fallback');
            await fallback.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 260, height: 140 } }, (text) => deliver(input, String(text).trim()), () => {});
        } catch (e) {
            note.textContent = 'เปิดกล้องไม่ได้: ' + (e?.message || e) + ' (ต้องใช้ https และอนุญาตกล้อง)';
            note.style.color = '#c9212d';
        }
    }

    function attach(root) {
        root.querySelectorAll('input[data-scan]:not([data-scan-ready])').forEach((input) => {
            input.setAttribute('data-scan-ready', '1');
            const btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'erp-scan-btn'; btn.title = 'สแกนด้วยกล้อง';
            btn.innerHTML = '<i class="bi bi-upc-scan"></i>';
            btn.addEventListener('click', () => open(input));
            input.insertAdjacentElement('afterend', btn);
        });
    }

    document.addEventListener('DOMContentLoaded', () => attach(document));
    // ช่องที่ Alpine/หน้าเพิ่มทีหลังก็ได้ปุ่มด้วย รวบการตรวจไว้ครั้งเดียวต่อเฟรม
    let queued = false;
    new MutationObserver(() => { if (queued) return; queued = true; requestAnimationFrame(() => { queued = false; attach(document); }); }).observe(document.documentElement, { childList: true, subtree: true });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && sheet) stop(); });
})();
</script>
