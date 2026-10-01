# PopCentral POS — Developer Handoff

เอกสารนี้เป็นข้อตกลงกลางสำหรับผู้พัฒนา POS รุ่น Python ที่เขียนขึ้นใหม่

วันที่ปรับปรุง: 1 ตุลาคม 2026

## 1. ภาพรวมระบบ

- POS Desktop: Python + PySide6
- ฐานข้อมูลประจำเครื่อง: SQLite
- ERP: Laravel JSON API
- Production API: `https://erp.popstarcenter.com`
- POS ห้ามต่อฐานข้อมูลกลางโดยตรง ไม่ว่าจะเป็น Microsoft SQL, PostgreSQL หรือฐานข้อมูลชนิดใด
- POS ห้ามเก็บ database password หรือ credentials ของ ERP
- Production ห้ามใช้ HTTP เพราะ Device Token อยู่ในทุก request

โครงสร้างปัจจุบันของ POS อยู่ที่ `apps/pos-python` และ API อยู่ที่ `routes/api.php` กับ `app/Http/Controllers/Api/PosApiController.php`.

## 2. การยืนยันตัวตนเครื่อง

ทุก request ที่อยู่ใต้ `/api/pos/*` ต้องส่ง:

```http
Authorization: Bearer <DEVICE_TOKEN>
Accept: application/json
```

Device Token ต้องออกจาก ERP และผูกกับ:

- สาขา
- เครื่อง POS / terminal
- User ที่ได้รับอนุญาต
- สิทธิ์ขาย POS

ห้าม hardcode Token ใน source code และห้ามส่ง Token ผ่าน Git, แชต หรือ log.

การตรวจสิทธิ์ทำที่ Laravel middleware `pos.device` ทุกครั้ง เซิร์ฟเวอร์จะเป็นผู้บังคับสาขาและคนขาย ไม่เชื่อค่าที่ client ส่งมาแบบอิสระ

## 3. API Contract

### Bootstrap และข้อมูล master

| หน้าที่ | Method | Endpoint |
|---|---:|---|
| ตรวจ Token, สาขา, เครื่อง, VAT, QR, รูปแบบเครื่องชั่ง | GET | `/api/pos/ping` |
| โหลดสินค้า ราคา และบาร์โค้ด | GET | `/api/pos/products?branch_id={branch_id}&all=1` |
| โหลดรายชื่อแคชเชียร์ | GET | `/api/pos/cashiers` |

ต้องเริ่มจาก `GET /api/pos/ping` ก่อนเสมอ ถ้าไม่ผ่านให้แสดงสถานะเชื่อมต่อไม่สำเร็จ และอย่าถือว่า sync ผ่าน

ข้อมูลสำคัญที่ได้จาก `ping`:

```json
{
  "success": true,
  "branch_id": 1,
  "branch_name": "สาขาตัวอย่าง",
  "device": {
    "id": 10,
    "terminal_code": "POS-001",
    "user_id": 20
  },
  "vat_rate": 7,
  "cashier_login_mode": "selection",
  "qr_payment": {},
  "scale_profiles": [],
  "receipt_template": {},
  "hardware_profile": {},
  "pos_layout": {}
}
```

ให้ cache ข้อมูลจาก `ping` ลง SQLite เพื่อใช้งาน Offline ได้ โดยเฉพาะ `branch_id`, `terminal_code`, `vat_rate`, `qr_payment`, `scale_profiles` และ receipt template

ข้อมูลสินค้าให้ upsert โดยใช้ `server_id` ของ ERP เป็นหลัก ห้ามใช้ local SQLite id ส่งกลับไป ERP

### แคชเชียร์

เลือกใช้ได้ 2 โหมดตามค่าที่ ERP ส่งมา:

```text
cashier_login_mode = selection  → เลือกชื่อคนขาย
cashier_login_mode = pin        → ยืนยัน PIN แล้วเลือก/ยืนยันชื่อ
```

```http
GET /api/pos/cashiers
POST /api/pos/cashier/login
```

โหมดเลือกชื่อ ส่งตัวอย่าง:

```json
{
  "cashier_id": 25
}
```

โหมด PIN ส่งตัวอย่าง:

```json
{
  "pin": "ตัวเลขจากผู้ขาย"
}
```

ถ้า API คืน `selection_required: true` ให้แสดงรายชื่อให้ผู้ใช้เลือก แล้วส่ง `cashier_id` กลับไปพร้อมการยืนยันตาม flow ของ ERP

`cashier_id` ใน POS API คือ `salesmen.id` ของ ERP ไม่ใช่ local SQLite id และไม่ใช่ `users.id`

### เปิดกะ

```http
POST /api/pos/shift/open
Idempotency-Key: SHIFT_UUID
```

```json
{
  "branch_id": 1,
  "cashier_id": 25,
  "opening_cash": "1000.00"
}
```

API จะคืน `shift.id` ของ ERP ให้เก็บไว้ใน SQLite เป็น `server_id` ของกะ local ก่อนส่งบิล

### เงินเข้าออกลิ้นชัก

```http
POST /api/pos/shift/cash-movement
Idempotency-Key: MOVEMENT_UUID
```

```json
{
  "shift_id": 100,
  "movement_type": "cash_in",
  "amount": "500.00",
  "reference_no": null,
  "reason": "เงินทอนเพิ่ม"
}
```

`movement_type` ที่รองรับ: `cash_in`, `drop`, `payout`

### ส่งบิลขาย

```http
POST /api/pos/checkout
Idempotency-Key: SALE_UUID
```

ตัวอย่างขั้นต่ำ:

```json
{
  "branch_id": 1,
  "shift_id": 100,
  "cashier_id": 25,
  "method": "cash",
  "cash_received": "500.00",
  "payment_confirmed": true,
  "items": [
    {
      "product_id": 123,
      "qty": "2",
      "unit_price": "35.00",
      "barcode": "8850000000000",
      "barcode_type": "EAN13"
    }
  ]
}
```

ค่าที่ต้องเข้าใจ:

- `product_id` ต้องเป็น `products.id` ของ ERP
- `shift_id` ต้องเป็น `pos_shifts.id` ของ ERP
- `cashier_id` ต้องเป็น `salesmen.id` ของ ERP
- `method` รองรับ `cash`, `transfer`, `credit_card`, `cheque`, `mixed`
- รายการบิลต้องมี `qty` และ `unit_price`
- จำนวนเงินต้องส่งเป็น string decimal เพื่อป้องกันปัญหา floating point
- ถ้าส่งบิลซ้ำ ต้องใช้ `Idempotency-Key` เดิม คือ `sale_uuid` เดิม ห้ามสร้าง UUID ใหม่ตอน retry
- สำเร็จแล้วต้องเก็บ `receipt_no` ที่ ERP คืนกลับมา

สำหรับการโอน/QR ต้องส่งข้อมูลยืนยันเงินเข้าให้ครบตามที่ UI ใช้ เช่น `payment_confirmed`, `payment_ref`, `transfer_account_last4`

### ปิดกะ

```http
POST /api/pos/shift/close
Idempotency-Key: SHIFT_CLOSE_UUID
```

```json
{
  "shift_id": 100,
  "counted_cash": "2450.00",
  "closing_note": "ปิดกะปกติ"
}
```

### ยกเลิกและรับคืน

```http
POST /api/pos/receipt/void
Idempotency-Key: VOID_UUID
```

```json
{
  "receipt_no": "POS-20261001-0001",
  "shift_id": 100,
  "reason": "ยิงสินค้าผิดรายการ"
}
```

รับคืนใช้ `/api/pos/receipt/return` และต้องมีสิทธิ์ผู้จัดการตามที่ ERP ตรวจสอบ

## 4. สินค้าปกติและสินค้าชั่ง

### สินค้าปกติ

- สแกนบาร์โค้ดแล้วเพิ่มจำนวน 1 หน่วย
- ไม่ต้องเปิด dialog กรอกน้ำหนัก
- ใช้ barcode ที่ sync ลง `product_barcodes`

### สินค้าชั่ง

- ห้าม hardcode กฎจากเลข 800/801 อย่างเดียว
- ให้ใช้ `scale_profiles` ที่ได้จาก `/api/pos/ping`
- 800/801 เป็นตัวอย่าง prefix ของระบบปัจจุบันเท่านั้น
- ตรวจ prefix, PLU, value, ความยาว และ EAN check digit ตาม profile
- กรณีสแกนฉลากจากตาชั่ง ให้คำนวณน้ำหนัก/จำนวนตาม profile
- ต้องส่งบาร์โค้ดฉลากดิบไปในรายการบิล เพื่อให้ ERP ตรวจซ้ำ
- ใช้ `barcode_type: SCALE_WEIGHT`
- กรณีกระดาษฉลากหมด ให้เลือกสินค้าเครื่องชั่งจากหน้าจอแล้วกรอกน้ำหนักเอง
- `qty` ของสินค้าชั่งอาจเป็นทศนิยม เช่น `0.375`

ตัวอย่างรายการชั่ง:

```json
{
  "product_id": 456,
  "qty": "0.375",
  "unit_price": "119.00",
  "barcode": "8001234567890",
  "barcode_type": "SCALE_WEIGHT"
}
```

ERP จะ re-parse และตรวจราคาสินค้าชั่งอีกครั้ง ห้ามเชื่อราคาที่คำนวณจาก client เพียงฝั่งเดียว

## 5. SQLite และ Offline-first

ข้อมูลหลักที่ควรมีในเครื่อง:

- `products`
- `product_barcodes`
- `price_versions`
- `local_cashiers`
- `shifts`
- `sales`
- `sale_items`
- `payments`
- `sync_outbox`
- `sync_state`
- `sync_runs`
- `sync_logs`
- `scale_profiles`
- `device_settings`

หลักการบันทึกขาย:

```text
กดรับชำระ
→ SQLite transaction: sale + items + payment + sync_outbox
→ พิมพ์ใบเสร็จ
→ worker ส่ง ERP ภายหลัง
```

ห้ามรอ API ก่อนบันทึกบิล local เพราะจะทำให้ขายไม่ได้เมื่อเน็ตล่ม

เมื่อกลับมา Online ให้ทำตามลำดับ:

```text
ping
→ sync profile/catalog/cashiers
→ sync shift_open
→ sync checkout sales
→ sync cash movements
→ sync void/return
→ sync shift_close
→ sync auth events
```

ถ้า dependency ยังไม่สำเร็จ ให้คงรายการไว้ใน outbox และแสดง error ที่อ่านได้ ห้ามลบคิวทิ้ง

## 6. Error handling

| HTTP | ความหมาย | การทำงานของ POS |
|---:|---|---|
| 401 | Token ไม่มี/ผิด/ถูกเพิกถอน | แสดงให้ผูกเครื่องใหม่ ห้ามถือว่า Online |
| 403 | ไม่มีสิทธิ์หรือผิดสาขา | แสดงข้อความจาก API และหยุดรายการนั้น |
| 404 | ไม่พบสินค้า/บิล/ข้อมูลอ้างอิง | แสดงรายการที่หาไม่พบ |
| 422 | ข้อมูลหรือเงื่อนไขธุรกิจไม่ผ่าน | แสดง `message` จาก ERP ให้ผู้ใช้แก้ |
| 409 | ข้อมูลชนกันหรือทำซ้ำ | ตรวจ idempotency และสถานะ local ก่อน retry |
| network error | ติดต่อ ERP ไม่ได้ | บันทึก local/outbox และทำ Offline ต่อ |

อย่าแสดง traceback ให้แคชเชียร์เห็น แต่ต้องเก็บรายละเอียดไว้ใน `sync_logs` หรือไฟล์ log สำหรับ IT

## 7. สิ่งที่ห้ามทำ

- ห้ามต่อ Microsoft SQL/PostgreSQL จาก POS โดยตรง
- ห้ามเขียน SQL ของ ERP ลงใน POS
- ห้ามใช้ HTTP กับ production
- ห้าม hardcode Device Token, PIN หรือ password
- ห้ามส่ง local SQLite id แทน server id
- ห้ามสร้างบิลใหม่เมื่อ retry บิลเดิม
- ห้าม hardcode รูปแบบบาร์โค้ดเครื่องชั่งแทน `scale_profiles`
- ห้ามลบข้อมูล local ที่ยังอยู่ใน `sync_outbox`
- ห้ามถือว่าสถานะคิวว่างเท่ากับ API ผ่าน ต้องตรวจ `/api/pos/ping` แยกเสมอ

## 8. Checklist ก่อนส่งงาน

- [ ] เปิดโปรแกรมโดยไม่มี config ได้เป็น Offline/demo โดยไม่ crash
- [ ] Token ผิดแสดง 401 ชัดเจน
- [ ] Token ถูกต้องแล้ว ping ได้และได้ branch/device
- [ ] โหลดสินค้า ราคา บาร์โค้ด และแคชเชียร์ได้
- [ ] เลือกแคชเชียร์และเปิดกะได้
- [ ] ขายสินค้าปกติได้
- [ ] สแกนฉลาก 800/801 หรือ profile จริงแล้วคำนวณน้ำหนักถูก
- [ ] เลือกสินค้าชั่งแล้วกรอกน้ำหนักเองได้
- [ ] บันทึกขายตอน Offline ได้
- [ ] กลับ Online แล้วบิล sync ได้ครั้งเดียว
- [ ] retry เดิมไม่สร้าง receipt ซ้ำ
- [ ] รับเงินสดและโอน/QR ได้ตาม payment contract
- [ ] ยกเลิกบิลและปิดกะได้
- [ ] มี log ที่ IT อ่านได้เมื่อ sync ไม่สำเร็จ

## 9. คำสั่งทดสอบของโปรเจกต์

```bash
cd apps/pos-python
python3 -m unittest discover -s tests -v
```

ห้ามใส่ Token จริงใน test, source code, screenshot, commit หรือเอกสารที่แชร์ต่อ
