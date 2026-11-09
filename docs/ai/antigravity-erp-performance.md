# รายงานการปรับปรุงประสิทธิภาพ ERP (Task 5: ERP Performance Phase 1)
**สาขา (Branch):** `antigravity/erp-performance` (สร้างจาก `origin/main` ล่าสุด)  
**วันที่ดำเนินการ:** 9 พฤศจิกายน 2026  
**สถานะผลทดสอบ PHPUnit:** ผ่านทั้งหมด 868 tests (`866 passed`, `2 skipped`, `0 failed`, `7,387 assertions`)

---

## 1. ผลการ Audit วัดค่าจริง (ก่อน / หลัง ปรับปรุง)

ทดสอบวัดค่าจริงด้วย Laravel HTTP Kernel & Built-in Web Server บนฐานข้อมูล SQLite (มีข้อมูลสินค้า 50+ รายการ และใบจองพร้อมเอกสารสต๊อก 20+ ฉบับ)

| หน้าจอที่วัด | เวลาตอบสนอง TTFB | จำนวน Queries | เวลา SQL รวม | ขนาด Response (HTML) |
|---|---|---|---|---|
| **หน้าหลัก (`/dashboard`)** | 33.7ms – 52.2ms | 37 queries | ~2.3ms – 8.5ms | ~263 KB |
| **ใบจอง - รายการ (`/bookings`)** | 32.9ms – 36.1ms | 34 queries | ~1.0ms – 3.9ms | ~328 – 355 KB |
| **ใบจอง - ฟอร์มสร้าง (`/bookings/create`)** | 11.5ms (Redirect -> Index Modal) | 2 queries | ~0.05ms | 366 B |
| **สินค้า (`/products`)** | 24.2ms – 26.4ms | 32 queries | ~0.7ms – 2.9ms | ~274 – 325 KB |
| **รายงาน (`/reports`)** | 29.7ms – 35.7ms | 31 queries | ~1.1ms – 3.5ms | ~318 KB |
| **ตั้งค่า → ค่าคงที่ (`/basic-master/...`)** | 18.1ms – 22.2ms | 24 queries | ~0.4ms – 0.5ms | ~253 – 255 KB |

### ขนาด Assets หลักของระบบ:
- `adminlte.min.css`: 332 KB (CSS หลักของโครงร่าง)
- `bootstrap-icons.min.css`: 85 KB (ไอคอนระบบ)
- `tailwind.min.css`: 22 KB (Tailwind แบบ pre-built คงที่ ไม่ใช่ runtime JIT CDN)
- `sweetalert2.all.min.js`: 77 KB (แจ้งเตือน Modal / Popup)
- `alpine.min.js`: 45 KB (State interaction หน้าบ้าน)
- `document-workflow.js`: 5.8 KB (ระบบ Draft recovery & lifecycle)

---

## 2. ปัญหาและสาเหตุจริงที่ตรวจพบ (Root Causes)

1. **ปัญหา Browser Loading ไม่หยุด / Polling ไม่ตัด:**
   - **สาเหตุจริงที่ 1:** ใน `resources/views/settings/pos-builds.blade.php` มี recursive `setTimeout(checkPosBuild, 15000 / 30000)` คอยยิง polling ทุก 15-30 วินาที แม้ผู้ใช้จะสลับแท็บไปหน้าอื่น (`document.hidden`) ทำให้เบราว์เซอร์ทำงานเบื้องหลังตลอดเวลา
   - **สาเหตุจริงที่ 2:** ใน `resources/views/pos/index.blade.php` มี `this.hostTimer = setInterval(() => this.checkHost(), 20 * 1000)` ทำงานทุก 20 วินาทีแม้แท็บจะ inactive
   - **การแก้ไข:** ปรับให้ตรวจ `if (document.hidden) return;` และเพิ่ม listener `visibilitychange` ให้หยุด polling ทันทีที่ผู้ใช้สลับแท็บ และปรับรอบความถี่ไม่ให้ถี่เกิน 30 วินาที

2. **ปัญหา Full Page Reload ที่ไม่จำเป็น:**
   - **สาเหตุจริง:** ใน `resources/views/products/index.blade.php` มีการเปิด iframe เพื่อดู/แก้ไขรายละเอียดสินค้า (`openProduct(url)`) เมื่อกดปิดหน้าต่าง popup ฟังก์ชัน `closeProduct()` ทำการสั่ง `window.location.reload();` เสมอ แม้ผู้ใช้แค่เปิดดูเฉยๆ โดยไม่ได้กดบันทึกหรือแก้ไขข้อมูลใดๆ
   - **การแก้ไข:** ปรับกลไกให้ `products/index` รอรับ `window.postMessage('erp:product:saved', '*')` จากหน้าแก้ไขสินค้าด้านใน หากผู้ใช้แค่กดดูแล้วปิด จะไม่มีการ reload หน้าจอ แต่หากมีการบันทึกราคา/สินค้าจริง จึงจะ reload อัปเดตข้อมูล

3. **ปัญหาฟอร์มใบจองเด้ง / ข้อมูลหาย:**
   - **สาเหตุจริงที่ 1:** หน้า `resources/views/bookings/create.blade.php` เดิมไม่มี Unsaved-changes Guard (`beforeunload`) และไม่มีระบบกู้คืนร่าง หากเบราว์เซอร์เด้ง รีเฟรช หรือกดลิงก์อื่น ข้อมูลที่กรอกไว้จะหายทั้งหมด
   - **สาเหตุจริงที่ 2:** การเก็บร่างเอกสารใน `public/document-workflow.js` เดิมใช้เพียง `sessionStorage` ซึ่งหากผู้ใช้เผลอปิดแท็บ หรือปิดหน้าต่างเบราว์เซอร์ ข้อมูลใน `sessionStorage` จะถูกลบทันที
   - **สาเหตุจริงที่ 3:** การค้นหาลูกค้าและสินค้าในหน้า `bookings/create` เดิมไม่มี `AbortController` ทำให้ request เดิมที่ยังค้างอยู่ยิงทับผลลัพธ์ใหม่ และไม่มีการรองรับการเลือกด้วยคีย์บอร์ด (ArrowUp / ArrowDown / Enter)
   - **การแก้ไข:**
     - ขยาย `public/document-workflow.js` และ `resources/views/layout.blade.php` ให้จัดเก็บร่างลง `localStorage` (มีอายุ 24 ชั่วโมง) ควบคู่กับ `sessionStorage` เพื่อให้กู้คืนได้แม้เผลอปิดแท็บหรือรีสตาร์ทเบราว์เซอร์ และจะถูกล้างทิ้งอัตโนมัติเมื่อบันทึกเอกสารสำเร็จ (`document_draft_saved`)
     - ปรับปรุง `bookings/create.blade.php` ให้มี debounce 250ms พร้อม `AbortController` ยกเลิก request เก่าเมื่อพิมพ์คำค้นใหม่
     - เพิ่มคีย์บอร์ดนำทาง `moveCustomerActive()` / `moveProductActive()` / `selectActiveCustomer()` / `selectActiveProduct()` สามารถใช้ปุ่มลูกศรขึ้น-ลง และกด Enter เลือกรายการได้ทันที
     - เพิ่ม `beforeunload` guard เตือนเมื่อมีข้อมูลที่ยังไม่ได้บันทึก

---

## 3. รายการไฟล์ที่แก้ไขใน Task 5 (Phase 1)

1. `public/document-workflow.js`: รองรับ `localStorage` สำหรับกู้คืนข้อมูลร่างเอกสารข้าม session พร้อม auto-clean เมื่อบันทึกสำเร็จ
2. `resources/views/layout.blade.php`: เพิ่มการล้าง draft ใน `localStorage` เมื่อ session แจ้ง `document_draft_saved`
3. `resources/views/bookings/create.blade.php`: ปรับ debounce 250ms, `AbortController`, การเลือกด้วยคีย์บอร์ด, Unsaved guard (`beforeunload`) และกู้คืนข้อมูลร่างจาก `localStorage`
4. `resources/views/products/index.blade.php`: ไม่ reload ทั้งหน้าเมื่อเปิดดูสินค้าแล้วปิด เปลี่ยนเป็น reload เฉพาะเมื่อมีการบันทึกแก้ไขสินค้าจริงผ่าน `postMessage`
5. `resources/views/products/show.blade.php`: ส่ง `postMessage('erp:product:saved')` แจ้งหน้าหลักเมื่อบันทึกราคาสำเร็จ
6. `resources/views/settings/pos-builds.blade.php`: หยุด polling ทันทีเมื่อแท็บไม่ active (`document.hidden`) และตั้งรอบไม่ถี่เกิน 30 วินาที
7. `resources/views/pos/index.blade.php`: ปรับ host timer ให้หยุดทำงานเมื่อแท็บ inactive (`document.hidden`) และเพิ่มรอบเวลาเป็น 30 วินาที

---

## 4. ข้อเสนอแนะสำหรับระยะที่ 2 (Phase 2 Proposals — Blade + Alpine)

1. **Progressive Navigation สำหรับ Blade (เปรียบเทียบทางเลือก):**
   - **ทางเลือก A: Hotwire / Turbo Drive (แนะนำ)**
     - *ข้อดี:* ขนาดเล็ก (~14 KB gzip) แปลงลิงก์ทุกหน้าให้โหลดเฉพาะ `<body>` แบบ AJAX อัตโนมัติ ทำให้ไม่ต้องดาวน์โหลด CSS/JS ซ้ำ ลดเวลาโหลดหน้าเหลือ < 80–120ms
     - *ข้อเสีย / ข้อควรระวัง:* ต้องระวัง Lifecycle ของ Alpine.js (ต้องเรียก `Alpine.initTree()` หรือใช้ปลั๊กอิน turbo-alpine เพื่อไม่ให้ event listeners ผูกซ้ำ)
   - **ทางเลือก B: htmx (hx-boost)**
     - *ข้อดี:* เข้ากับแนวคิด Blade + Alpine ได้ดีมาก (~15 KB) ใช้ `hx-boost="true"` ที่คอนเทนเนอร์หลัก
     - *ข้อเสีย:* อาจกระทบฟอร์มเอกสารที่มี modal ซ้อนอยู่ หากไม่ได้แยก target ให้ชัดเจน
   - **ทางเลือก C: InstantClick / Quicklink (Prefetch on Hover)**
     - *ข้อดี:* ไม่กระทบ DOM หรือสคริปต์ Alpine เลย แค่ prefetch HTML เมื่อผู้ใช้ชี้เมาส์ที่เมนู
     - *ข้อเสีย:* เปลือง request server เพิ่มขึ้นเล็กน้อยถ้าผู้ใช้เลื่อนเมาส์ผ่านหลายเมนู

2. **Server/HTTP Reference Data Cache:**
   - แคชข้อมูลหมวดหมู่สินค้า, ยี่ห้อ, แผนก, หน่วยนับ, และรายชื่อสาขา ในระดับ HTTP Cache-Control หรือ Redis/Database Tag Cache
   - **ข้อจำกัดเข้มงวด:** ห้ามใช้แคชกับยอดสต๊อกคงเหลือจริง, ราคาหน้าบิล, ภาษีมูลค่าเพิ่ม (VAT), หรือสถานะการเงิน/การรับชำระ โดยส่วนเหล่านี้ต้องตรวจสอบสดที่ Server เสมอ

3. **Skeleton Loading & Instant Modals:**
   - เปลี่ยนจากการเปิด modal ที่รอข้อมูล AJAX ให้เป็น Instant Modal Opening (แสดง Skeleton Placeholder ทันทีภายใน 100ms) แล้วจึงดึงข้อมูลมาเติม เพื่อให้ความรู้สึกตอบสนองทันที
