# รายงานสรุปงานที่ 26: ระบบนัดเปลี่ยนราคาขายล่วงหน้า (หลังบ้าน + POS)

> **Branch**: `antigravity/scheduled-price` (แยกจาก `origin/main`)  
> **สถานะ**: ผ่านการทดสอบทั้งหมด (10/10 เทสต์เฉพาะเรื่อง, 951/951 เทสต์ทั้งระบบ) · พร้อมให้ Claude ตรวจสอบก่อน Merge

---

## 1. วัตถุประสงค์และสถานการณ์จำลองจริงของเจ้าของ

เจ้าของระบบเจอกรณีจริงที่ซัพพลายเออร์แจ้งปรับราคาสินค้า "คอหมึก" 3 ขนาด ล่วงหน้า โดยมีผลในวันที่ **12/11/2569 (= 12 พ.ย. 2026)**:
- คอหมึก 100/300 = **54 บาท** (เดิม 50 บาท)
- คอหมึก 300/500 = **59 บาท** (เดิม 55 บาท)
- คอหมึก 500 ขึ้นไป = **62 บาท** (เดิม 60 บาท)

ระบบที่สร้างขึ้นช่วยให้เจ้าของหรือผู้จัดการสามารถเข้าเมนู **"นัดเปลี่ยนราคา"** แล้วตั้งราคาสินค้าทั้ง 3 ตัวล่วงหน้าได้ในฟอร์มเดียว พร้อมระบุวันที่มีผลเป็น `2026-11-12 00:00:00` (ค่าเริ่มต้นคือ 00:00 ของวันพรุ่งนี้ตามเวลาไทย):
1. **ส่งราคาล่วงหน้าให้เครื่อง POS ทันที**: สร้างแถวในตาราง `pos_price_schedules` เพื่อให้เครื่อง POS ได้รับตอนซิงก์รอบ 5 นาที และสามารถขายราคาใหม่ได้อัตโนมัติตามช่วงเวลา แม้ตอนเที่ยงคืนเน็ตจะหลุดหรือเป็นออฟไลน์
2. **ปรับราคาหลังบ้านอัตโนมัติ**: เมื่อถึงเวลาที่กำหนด คำสั่ง `php artisan erp:apply-scheduled-prices` จะปรับ `products.default_price` และบันทึกประวัติราคา `PriceChange` และ `AuditLog`
3. **ปิดแถวราคาของ POS**: เมื่อราคาหลักในหลังบ้านเปลี่ยนแล้ว ระบบจะปิดแถว `PosPriceSchedule` ที่ผูกไว้ เพื่อไม่ให้ไปทับการแก้ราคาหลักในอนาคต

---

## 2. การนำโค้ดเดิมมาใช้ซ้ำ (Code Reuse)

ตามข้อกำหนดที่ห้ามเขียนกลไกซ้ำ ระบบนี้ใช้ประโยชน์จากโค้ดเดิมอย่างสมบูรณ์:
1. **สิทธิ์การใช้งาน (Permissions)**:
   - ใช้สิทธิ์เดิมคือ `masterdata.manage` ซึ่งเป็นสิทธิ์เดียวกับที่ใช้จัดการทะเบียนสินค้าและตารางราคา
   - เส้นทางทั้งหมดขึ้นต้นด้วย `products.scheduled-prices.*` ทำให้แม็ปเข้ากับ `'products.' => 'masterdata.manage'` ใน `App\Support\RoutePermissions` โดยอัตโนมัติ **ไม่มีการสร้างสิทธิ์ใหม่**
2. **การสร้างและยกเลิกช่วงราคา POS**:
   - แยกตรรกะการเผยแพร่และยกเลิกราคา POS ออกมาเป็น `PosPriceScheduleService::publish()` และ `PosPriceScheduleService::cancel()`
   - ทั้ง `ProductController::storePosPriceSchedule`, `ProductController::cancelPosPriceSchedule` และ `ScheduledPriceChangeService` ต่างเรียกใช้ Service ตัวเดียวกันทุกประการ ทำให้กติกาเรื่องการบันทึกสถานะ published, วันที่เริ่ม-สิ้นสุด และผู้สร้าง ทำงานเหมือนกัน 100%
3. **การเปลี่ยนราคาหลังบ้านและผลข้างเคียง**:
   - ปรับปรุงผ่าน Eloquent Model `Product::update(['default_price' => ...])` หรือ `ProductPrice::updateOrCreate(...)`
   - บันทึกประวัติการเปลี่ยนราคาลงตาราง `PriceChange` พร้อมระบุ `old_price`, `new_price`, `effective_date`, `changed_by`
   - บันทึกประวัติการตรวจสอบลง `AuditLog` (action `price_updated`, table `products`, old/new values) สอดคล้องกับหน้าแก้ราคาสินค้าเดิม
4. **การค้นหาและเลือกสินค้าในหน้าเว็บ**:
   - นำ Component ร่วม `partials.product-picker` (ที่มี Typeahead search ค้นหาด้วยรหัส SKU / ชื่อ / บาร์โค้ด) มาใช้ในหน้าจอ
   - ข้อมูลราคาเดิมดึงจากฟิลด์ `default_price` ผ่าน `/search/products`

---

## 3. ข้อสรุปเรื่องราคาใน `pos_price_schedules` กับ VAT

จากการตรวจสอบโค้ดใน `PosController::products`, `PosPricingGuard`, และแฟ้มข้อมูลสินค้า:
- **ราคาขายใน JET ERP เป็นราคารวม VAT**: ฟิลด์ `products.default_price`, `pos_price` ที่ส่งให้เครื่อง POS, และ `pos_price_schedules.price` เป็นราคาขายปลีกหน้าร้าน (Shelf/Retail Price) ที่รวมภาษีมูลค่าเพิ่ม 7% แล้วสำหรับสินค้าที่จด VAT (`is_vat = true`)
- **การคำนวณรายได้สุทธิและกำไรขั้นต้น**: ใน `PosPricingGuard` และ `PosController`, ระบบจะแยก VAT ออกจากราคาขายปลีกโดยอัตโนมัติด้วยสูตร:
  $$\text{Net Revenue} = \frac{\text{Price} \times 100}{100 + \text{VAT Rate}}$$
- **ข้อสรุป**: ราคาที่ระบุในหน้านัดเปลี่ยนราคา และแถวที่สร้างลงใน `pos_price_schedules` **เป็นราคาเดียวกับราคาขายปลีกหน้าร้าน ไม่ต้องบวกหรือหัก VAT เพิ่มเติม** ตรงกันทั้งระบบ POS และหลังบ้าน

---

## 4. ตรรกะการปิดแถว `PosPriceSchedule` หลังมีผล และผลพิสูจน์ Mutation Test

### ทำไมต้องปิดแถว POS หลัง Apply?
ใน `App\Services\Sales\PosPriceScheduleService::active()` ระบบจะดึงแถวที่ `effective_from <= now()` และ (`effective_to IS NULL OR effective_to > now()`) เรียงลำดับตาม `effective_from DESC` แถวล่าสุดจะชนะและนำไปทับราคาหลักเสมอ
- หากแถวใน `pos_price_schedules` ยังคงสถานะ `published` และ `effective_to` เป็น null ตลอดไป เมื่อผู้จัดการมาแก้ราคาหลักในหลังบ้านในวันหลัง (เช่น จาก 54 บาท เป็น 65 บาท) เครื่อง POS จะยังคงหยิบแถว 54 บาทของตาราง `pos_price_schedules` มาทับราคาใหม่อยู่เสมอ!
- ด้วยเหตุนี้ เมื่อคำสั่ง `erp:apply-scheduled-prices` ปรับ `products.default_price` เรียบร้อยแล้ว ระบบจึงสั่ง `cancel()` แถว `PosPriceSchedule` ที่ผูกไว้ทันที เพื่อให้เครื่อง POS ถอยกลับมาใช้ `default_price` ก้อนใหม่

### ผลการทดสอบ Mutation Test (ข้อ 6 & 32)
ได้ทำการทดสอบเพื่อพิสูจน์ว่าเทสต์สามารถตรวจจับข้อผิดพลาดนี้ได้จริง:
1. **ทำการปิดโค้ด (Comment out)** ส่วนที่ปิดแถว POS ใน `ScheduledPriceChangeService.php`:
   ```php
   // if ($locked->pos_price_schedule_id) {
   //     $posSchedule = PosPriceSchedule::find($locked->pos_price_schedule_id);
   //     if ($posSchedule && $posSchedule->status === 'published') {
   //         $this->posPriceScheduleService->cancel($posSchedule);
   //     }
   // }
   ```
2. **รัน PHPUnit**:
   ผลลัพธ์ปรากฏว่าเทสต์ `test_closing_pos_schedule_after_apply_prevents_shadowing_subsequent_price_updates` และ `test_owner_scenario_squid_supplier_three_sizes_batch_scheduling` เกิดข้อผิดพลาดและเปลี่ยนสถานะเป็น **RED (Failed)** ทันที:
   ```text
   Failed asserting that App\Models\PosPriceSchedule Object ... is null.
   แถว PosPriceSchedule หลัง apply ต้องถูกปิด (cancelled) ไม่งั้นจะบังราคาหลักที่ผู้จัดการแก้ในวันถัดไป
   ```
3. **เปิดโค้ดกลับคืน**: เทสต์ทั้งหมดกลับมาผ่านเป็น **GREEN 100%**

---

## 5. การตั้งเวลาและข้อควรระวังเรื่องสิทธิ์ผู้ใช้ (User Permission in Cron)

ใน `routes/console.php` ได้เพิ่มคำสั่ง:
```php
$priceSchedule = Schedule::command('erp:apply-scheduled-prices')
    ->everyMinute()->withoutOverlapping();
if (function_exists('posix_getuid') && posix_getuid() === 0) {
    $priceSchedule->user((string) env('QUEUE_WORKER_USER', 'www-data'));
}
```
**เหตุผล**: Production รัน cron ด้วยผู้ใช้ `root` หากคำสั่งสร้าง cache/log/view ในนาม root หน้าเว็บที่รันด้วย `www-data` จะไม่สามารถเขียนไฟล์ทับได้ (ปัญหาเดียวกับ PR #42) การสลับไปรันในนาม `www-data` ช่วยป้องกันปัญหานี้ได้อย่างสมบูรณ์

---

## 6. คำสั่งที่เจ้าของระบบต้องรันบน Server เมื่อขึ้น Production

เมื่อนำโค้ดขึ้น Server จริง ให้รันคำสั่ง Migration เพียงคำสั่งเดียว:
```bash
php artisan migrate --force
```
*(ตารางเวลาใน `routes/console.php` จะถูกเรียกอัตโนมัติผ่าน crontab เดิมของเซิร์ฟเวอร์ทุกนาที)*

---

## 7. ผลการทดสอบ (Test Results)

1. **เทสต์เฉพาะเรื่อง `tests/Feature/ScheduledPriceChangeTest.php`**: ผ่านทั้ง 10 เทสต์ (85 assertions)
   - `test_owner_scenario_squid_supplier_three_sizes_batch_scheduling`: สถานการณ์จริงซัพพลายเออร์ปรับราคาคอหมึก 3 ขนาด มีผลตามเวลาตรงเป๊ะ
   - `test_exact_decimal_precision`: ตรวจสอบความแม่นยำของทศนิยม (เช่น 35.5, 38.25)
   - `test_cancellation_before_effective_time_cancels_pos_schedule`: การยกเลิกก่อนถึงเวลาต้องยกเลิกแถว POS ด้วย
   - `test_idempotency_running_apply_multiple_times`: การรันซ้ำหลายครั้งไม่เกิดผลซ้ำซ้อน
   - `test_closing_pos_schedule_after_apply_prevents_shadowing_subsequent_price_updates`: พิสูจน์ว่าแถว POS ถูกปิดและไม่ทับการแก้ราคาในวันถัดมา
   - `test_permission_checks_masterdata_manage`: ตรวจสอบสิทธิ์ `masterdata.manage`
   - `test_validation_rejects_non_positive_price_and_past_date`: ตรวจสอบการปฏิเสธราคา $\le 0$ และวันในอดีต
   - `test_existing_documents_keep_old_price_new_documents_use_new_price`: เอกสารเดิมคงราคาเดิม เอกสารใหม่ใช้ราคาใหม่
   - `test_quick_paste_multi_line_tab_or_comma_parsing`: การวางข้อความหลายบรรทัดแยกด้วย Tab หรือ Comma
   - `test_failed_item_does_not_block_other_items_in_batch`: รายการที่ล้มเหลวไม่ขัดขวางรายการอื่นในชุดเดียวกัน

2. **เทสต์ทั้งระบบ (Full Test Suite)**:
   ```text
   Tests: 951 passed, 0 failed, 2 skipped, 6 incomplete (8,316 assertions)
   Duration: 39.50s
   ```

---

## 8. ภาพหน้าจอการทำงาน (Screenshots)

ภาพหน้าจอทั้งหมดถูกบันทึกไว้ในไดเรกทอรี [`docs/ai/scheduled-price-shots/`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/scheduled-price-shots/):

1. **หน้าฟอร์มบันทึกนัดเปลี่ยนราคา (ตัวอย่าง 3 สินค้าคอหมึก 100/300, 300/500, 500 ขึ้นไป)**:  
   [`01_scheduled_prices_form.png`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/scheduled-price-shots/01_scheduled_prices_form.png)
2. **ป๊อปอัปตรวจสอบตัวอย่างก่อนบันทึก (Preview Modal: ราคาเดิม → ราคาใหม่ และส่วนต่าง +/−)**:  
   [`02_scheduled_prices_preview_modal.png`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/scheduled-price-shots/02_scheduled_prices_preview_modal.png)
3. **ป๊อปอัปวางข้อความหลายรายการ (Quick Paste: วางข้อความแบบ `รหัสสินค้า<Tab>ราคา` ทีละหลายตัว)**:  
   [`03_scheduled_prices_quick_paste_modal.png`](file:///Applications/MAMP/htdocs/erp/ERPPOP/docs/ai/scheduled-price-shots/03_scheduled_prices_quick_paste_modal.png)
