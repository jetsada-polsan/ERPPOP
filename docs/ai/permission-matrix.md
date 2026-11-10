# ตารางสิทธิ์จริงและการเข้าถึงเมนูระบบ (ERP Role × Menu Permission Matrix)

> วันที่ตรวจสอบ: 2026-11-10 09:37 · สร้างอัตโนมัติจากโค้ดจริงและการทดสอบระบบ (Test-Driven)

## 1. คำอธิบายสัญลักษณ์ในตาราง

- ✅ **เห็นและเข้าได้**: เมนูปรากฏใน `ErpMenu::forUser()` และเมื่อกดเข้าใช้งาน (HTTP GET) ได้ผลลัพธ์ **200 OK** หรือ **302 Redirect** สมบูรณ์
- ❌ **ไม่เห็น / สิทธิ์ไม่อนุญาต**: เมนูถูกซ่อนจากแถบเมนูตามสิทธิ์จริง และเมื่อพยายามเข้า URL โดยตรง ระบบจะปฏิเสธด้วย **403 Forbidden**
- ⚠️ **ไม่เห็นเมนูแต่ควรใช้ (Workplace Gap)**: ทางเทคนิคถูกซ่อนตามสิทธิ์ปัจจุบัน (403) แต่ในทางปฏิบัติควรพิจารณาปรับให้บทบาทนี้เข้าถึงได้ (รายละเอียดในตารางข้อเสนอแนะ)

## 2. บทบาททั้งหมดในระบบ (14 บทบาท)

| รหัสบทบาท | ชื่อตำแหน่ง | จำนวนสิทธิ์ตรง | จำนวนเมนูที่เห็น |
|:---|:---|:---:|:---:|
| `GM` | ผู้จัดการทั่วไป | 36 | 78 / 78 |
| `IT_MGR` | ผู้ดูแลระบบ (IT) | 18 | 78 / 78 |
| `BRANCH_MGR` | ผู้จัดการสาขา | 23 | 37 / 78 |
| `ACC_MGR` | ผู้จัดการฝ่ายบัญชี | 10 | 39 / 78 |
| `ACC` | พนักงานบัญชี | 5 | 26 / 78 |
| `PURCHASING` | ฝ่ายจัดซื้อ | 5 | 30 / 78 |
| `SALES` | ฝ่ายขาย | 3 | 22 / 78 |
| `WAREHOUSE` | คลังสินค้า | 2 | 19 / 78 |
| `CASHIER` | Cashier | 3 | 2 / 78 |
| `DELIVERY` | พนักงานจัดส่ง | 0 | 1 / 78 |
| `EXECUTIVE` | ผู้บริหาร / กรรมการ | 4 | 12 / 78 |
| `HR_MGR` | ผู้จัดการฝ่ายบุคคล | 4 | 15 / 78 |
| `HR` | พนักงานฝ่ายบุคคล | 1 | 11 / 78 |
| `MARKETING` | การตลาด | 4 | 25 / 78 |

---

## 3. ตารางความสัมพันธ์ บทบาท × เมนู (Permission Matrix)

### หมวด: ภาพรวม

| เมนู | Route | สิทธิ์ที่ต้องใช้ | `GM` | `IT_MGR` | `BRANCH_MGR` | `ACC_MGR` | `ACC` | `PURCHASING` | `SALES` | `WAREHOUSE` | `CASHIER` | `DELIVERY` | `EXECUTIVE` | `HR_MGR` | `HR` | `MARKETING` |
|:---|:---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| ภาพรวมกิจการ | `dashboard` | *(ทุกคนที่ Login)* | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| รวมเมนูการทำงาน | `features.index` | `reports.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ |
| คู่มือ JET ERP | `core-modules.index` | `reports.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ |
| ศูนย์ควบคุมบริหาร | `management-controls.index` | `management.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ | ❌ | ✅ |

### หมวด: งานประจำวัน

| เมนู | Route | สิทธิ์ที่ต้องใช้ | `GM` | `IT_MGR` | `BRANCH_MGR` | `ACC_MGR` | `ACC` | `PURCHASING` | `SALES` | `WAREHOUSE` | `CASHIER` | `DELIVERY` | `EXECUTIVE` | `HR_MGR` | `HR` | `MARKETING` |
|:---|:---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| เอกสารย้อนหลัง | `documents.browser` | `sales.manage` | ✅ | ✅ | ✅ | ❌ | ⚠️ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ใบเสนอราคา | `quotations.index` | `sales.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ใบจอง / ขายเชื่อ | `bookings.index` | `sales.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ใบขายสด | `cash-sales.index` | `sales.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ใบรับคืนสินค้า | `sale-returns.index` | `sales.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ใบวางบิล | `billing-notes.index` | `sales.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ใบเพิ่ม/ลดหนี้ | `credit-debit-notes.index` | `sales.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ลูกค้าสัมพันธ์ (CRM) | `crm.index` | `sales.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Pipeline งานขาย | `crm.pipeline` | `sales.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

### หมวด: POS / หน้าร้าน

| เมนู | Route | สิทธิ์ที่ต้องใช้ | `GM` | `IT_MGR` | `BRANCH_MGR` | `ACC_MGR` | `ACC` | `PURCHASING` | `SALES` | `WAREHOUSE` | `CASHIER` | `DELIVERY` | `EXECUTIVE` | `HR_MGR` | `HR` | `MARKETING` |
|:---|:---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| ศูนย์ควบคุม POS | `pos.control` | `reports.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ |
| จัดการเครื่อง POS | `pos-admin.index` | `settings.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| QR รับเงิน / จอแสดงราคา | `bplus.qr-payments` | `settings.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ⚠️ | ❌ | ❌ | ❌ | ❌ | ❌ |

### หมวด: คลัง / ผลิต / ซื้อ

| เมนู | Route | สิทธิ์ที่ต้องใช้ | `GM` | `IT_MGR` | `BRANCH_MGR` | `ACC_MGR` | `ACC` | `PURCHASING` | `SALES` | `WAREHOUSE` | `CASHIER` | `DELIVERY` | `EXECUTIVE` | `HR_MGR` | `HR` | `MARKETING` |
|:---|:---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| รับสินค้าเข้าคลัง (ซื้อเข้า) | `purchases.index` | `purchasing.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ⚠️ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ใบขอซื้อ / สั่งซื้อ (PO) | `purchase-orders.index` | `purchasing.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| สแกนใบรับสินค้า OCR | `ocr.documents.index` | `purchasing.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ⚠️ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| โอนย้ายสินค้าข้ามสาขา | `stock-transfers.index` | `stock.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ขอโอนสินค้าข้ามสาขา | `stock-transfers.request` | `stock.request` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| แนะนำเติมสต็อกสาขา | `stock-transfers.replenishment.index` | `stock.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| เบิกใช้ / ตัดชำรุด / คืนเบิก | `stock-issues.index` | `stock.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| แปรรูปสินค้า / สูตรผลิต | `production.index` | `stock.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| รายงานผลผลิต & Yield | `production.efficiency` | `stock.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ตรวจนับสต๊อกสินค้า | `stock-counts.index` | `stock.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| คลังมือถือ (สแกนบาร์โค้ด) | `wh.index` | `stock.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ⚠️ | ❌ | ❌ | ❌ | ❌ |
| แผนจัดซื้อและเติมสต็อก | `bplus.purchase-planning` | `purchasing.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

### หมวด: การเงิน / บัญชี

| เมนู | Route | สิทธิ์ที่ต้องใช้ | `GM` | `IT_MGR` | `BRANCH_MGR` | `ACC_MGR` | `ACC` | `PURCHASING` | `SALES` | `WAREHOUSE` | `CASHIER` | `DELIVERY` | `EXECUTIVE` | `HR_MGR` | `HR` | `MARKETING` |
|:---|:---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| บัญชีครบวงจร | `accounting-automation.index` | `finance.manage` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ผังบัญชี / บันทึกบัญชี | `chart-of-accounts.index` | `finance.manage` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| งวดบัญชี / ปิดงวด | `accounting-periods.index` | `finance.manage` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ปิดบัญชีรายเดือน | `monthly-accounting.index` | `finance.manage` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ภาษีไทย / E-Tax | `tax-compliance.index` | `finance.manage` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| งบการเงิน | `financial-statements.index` | `finance.manage` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ทะเบียนทรัพย์สิน | `fixed-assets.index` | `finance.manage` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| เงินสด / ภาษี | `bplus.finance` | `finance.manage` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ใบเสร็จรับเงิน (รับชำระลูกหนี้) | `ar-receipts.index` | `finance.manage` | ✅ | ✅ | ⚠️ | ✅ | ✅ | ❌ | ⚠️ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| จ่ายชำระเจ้าหนี้ | `ap-payments.index` | `finance.manage` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| บัญชีธนาคาร | `bank-accounts.index` | `finance.manage` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ฝาก/ถอนเงินสด | `cash-transfers.index` | `finance.manage` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ทะเบียนเช็ค | `cheques.index` | `finance.manage` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| อนุมัติเอกสาร | `bplus.approvals` | `finance.manage` | ✅ | ✅ | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

### หมวด: รายงาน

| เมนู | Route | สิทธิ์ที่ต้องใช้ | `GM` | `IT_MGR` | `BRANCH_MGR` | `ACC_MGR` | `ACC` | `PURCHASING` | `SALES` | `WAREHOUSE` | `CASHIER` | `DELIVERY` | `EXECUTIVE` | `HR_MGR` | `HR` | `MARKETING` |
|:---|:---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| ภาพรวมผู้บริหาร | `executive.index` | `reports.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ |
| ศูนย์รวมรายงาน | `reports.index` | `reports.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ |
| รายงานขาย | `reports.index` | `reports.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ |
| รายงานซื้อ | `reports.index` | `reports.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ |
| รายงานคลัง | `reports.index` | `reports.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ |
| รายงานบัญชี | `reports.index` | `reports.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ |
| รายงานภาษี | `reports.index` | `reports.view` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ |

### หมวด: ระบบ

| เมนู | Route | สิทธิ์ที่ต้องใช้ | `GM` | `IT_MGR` | `BRANCH_MGR` | `ACC_MGR` | `ACC` | `PURCHASING` | `SALES` | `WAREHOUSE` | `CASHIER` | `DELIVERY` | `EXECUTIVE` | `HR_MGR` | `HR` | `MARKETING` |
|:---|:---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| ค่าคงที่ระบบ | `basic-master.index` | `masterdata.manage` | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| สินค้า / บริการ | `products.index` | `masterdata.manage` | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ | ⚠️ | ⚠️ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| หน่วยนับสินค้า | `product-units.index` | `masterdata.manage` | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| คลังสินค้า | `warehouse-locations.index` | `stock.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ลูกค้า (รวมลูกหนี้) | `customers.index` | `sales.manage` | ✅ | ✅ | ✅ | ⚠️ | ⚠️ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ผู้จำหน่าย / เจ้าหนี้ | `suppliers.index` | `purchasing.manage` | ✅ | ✅ | ✅ | ⚠️ | ⚠️ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| สมาชิก | `members.index` | `masterdata.manage` | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ | ⚠️ | ❌ | ❌ | ❌ | ❌ | ✅ |
| พนักงาน | `employees.index` | `users.manage` | ✅ | ✅ | ⚠️ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ⚠️ | ❌ |
| ตารางราคา | `price-tables.index` | `masterdata.manage` | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ | ⚠️ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| ราคาเครื่องชั่ง | `scale-prices.index` | `masterdata.manage` | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| ส่วนลดราคาสินค้า (แบบเดิม) | `promotions.index` | `masterdata.manage` | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ | ⚠️ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| ราคานาทีทอง | `flash-sales.index` | `masterdata.manage` | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| ป้ายราคา | `price-tags.index` | `masterdata.manage` | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ | ❌ | ⚠️ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| บัตรส่วนลด | `discount-cards.index` | `masterdata.manage` | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| แต้มสมาชิก | `member-points.index` | `masterdata.manage` | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| โปรโมชัน / แคมเปญ | `qty-promotions.index` | `masterdata.manage` | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ | ⚠️ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| ยานพาหนะ / ขนส่ง | `fleet.index` | `transport.manage` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ⚠️ | ❌ | ❌ | ❌ | ❌ |
| ศูนย์ตั้งต้นระบบ | `master-data-setup.index` | `masterdata.manage` | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| สมุดเอกสาร | `document-books.index` | `settings.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ตั้งค่า Workflow เอกสาร | `settings.workflows` | `settings.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ศูนย์ควบคุมโมดูล | `settings.module-controls` | `settings.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ผู้ใช้และสิทธิ์ | `users.index` | `users.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |
| ผังองค์กร | `organizational-units.index` | `users.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |
| LINE แจ้งเตือน | `line-integrations.index` | `settings.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| E-Commerce | `ecommerce-channels.index` | `settings.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| ตั้งค่าระบบ | `settings.index` | `settings.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| นโยบายขายติดลบ | `settings.stock-policies` | `settings.manage` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Backup / Security | `operations.index` | `system.admin` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| โครงสร้างฐานข้อมูล | `database-structure.index` | `system.admin` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

---

## 4. ตารางข้อเสนอแนะเพื่อการตัดสินใจ (Proposals for Owner / Claude)

> **หมายเหตุสำคัญ**: ตามข้อกำหนดของงาน Antigravity **จะไม่แก้ไข** `RoutePermissions.php`, สิทธิ์บทบาท หรือ Seeders เองเด็ดขาด โดยรวบรวมข้อเสนอแนะพร้อมเหตุผลทางธุรกิจเพื่อให้เจ้าของระบบหรือ Claude พิจารณาปรับปรุงในรอบถัดไป

| ลำดับ | บทบาท (Role) | เมนูที่ควรเข้าถึงได้ | Route / สิทธิ์ปัจจุบัน | เหตุผลทางธุรกิจและผลกระทบ (Business Rationale) | แนวทางแก้ไขที่แนะนำ |
|:---:|:---|:---|:---|:---|:---|
| 1 | `SALES` (ฝ่ายขาย) | ใบเสร็จรับเงิน (รับชำระลูกหนี้) | `ar-receipts.index`<br>`finance.manage` | พนักงานขายออกไปเก็บเงิน/เช็คจากลูกค้า ต้องสามารถดูหรือออกใบเสร็จรับเงินให้ลูกค้าได้ | เพิ่มสิทธิ์ `finance.receipt.view` หรือแยกสิทธิ์การดู/ออกใบเสร็จจาก `finance.manage` |
| 2 | `SALES` (ฝ่ายขาย) | สินค้า / บริการ | `products.index`<br>`masterdata.manage` | พนักงานขายต้องตรวจสอบข้อมูลสินค้า สเปก ราคาขาย และหน่วยนับ | สร้างสิทธิ์ `masterdata.view` (ดูอย่างเดียว ห้ามแก้ไข) ให้กับฝ่ายขายและคลังสินค้า |
| 3 | `SALES` (ฝ่ายขาย) | ตารางราคา | `price-tables.index`<br>`masterdata.manage` | พนักงานขายต้องดูตารางราคาตามกลุ่มลูกค้าและระดับการค้า | สร้างสิทธิ์ `masterdata.view` (ดูอย่างเดียว ห้ามแก้ไข) ให้กับฝ่ายขายและคลังสินค้า |
| 4 | `SALES` (ฝ่ายขาย) | โปรโมชัน / แคมเปญ | `qty-promotions.index`<br>`masterdata.manage` | พนักงานขายต้องดูเงื่อนไขโปรโมชันแถม/ส่วนลดเพื่อนำเสนอขาย | สร้างสิทธิ์ `masterdata.view` (ดูอย่างเดียว ห้ามแก้ไข) ให้กับฝ่ายขายและคลังสินค้า |
| 5 | `SALES` (ฝ่ายขาย) | ส่วนลดราคาสินค้า (แบบเดิม) | `promotions.index`<br>`masterdata.manage` | พนักงานขายต้องตรวจสอบโปรโมชันส่วนลดสินค้า | สร้างสิทธิ์ `masterdata.view` (ดูอย่างเดียว ห้ามแก้ไข) ให้กับฝ่ายขายและคลังสินค้า |
| 6 | `WAREHOUSE` (คลังสินค้า) | รับสินค้าเข้าคลัง (ซื้อเข้า) | `purchases.index`<br>`purchasing.manage` | พนักงานคลังสินค้ามีหน้าที่ตรวจรับสินค้าจริงเข้าคลัง (GRN) จากผู้จำหน่าย | อนุญาตให้ผู้มีสิทธิ์ `stock.manage` เข้าดูรายการรับสินค้าเข้าคลังได้ |
| 7 | `WAREHOUSE` (คลังสินค้า) | สแกนใบรับสินค้า OCR | `ocr.documents.index`<br>`purchasing.manage` | พนักงานคลังต้องสแกนใบส่งของ/บิลรับสินค้าของผู้ขายหน้างานผ่าน OCR | อนุญาตให้ผู้มีสิทธิ์ `stock.manage` สแกนเอกสารรับเข้าได้ |
| 8 | `WAREHOUSE` (คลังสินค้า) | สินค้า / บริการ | `products.index`<br>`masterdata.manage` | พนักงานคลังต้องตรวจสอบข้อมูลสินค้า บาร์โค้ด และหน่วยนับ | สร้างสิทธิ์ `masterdata.view` (ดูอย่างเดียว ห้ามแก้ไข) ให้กับฝ่ายขายและคลังสินค้า |
| 9 | `WAREHOUSE` (คลังสินค้า) | ป้ายราคา | `price-tags.index`<br>`masterdata.manage` | พนักงานคลังต้องพิมพ์ป้ายบาร์โค้ดติดกล่อง/พาเลท/เชลฟ์สินค้า | อนุญาตให้ผู้มีสิทธิ์ `stock.manage` พิมพ์ป้ายบาร์โค้ดติดกล่อง/เชลฟ์สินค้าได้ |
| 10 | `DELIVERY` (พนักงานจัดส่ง) | ยานพาหนะ / ขนส่ง | `fleet.index`<br>`transport.manage` | พนักงานจัดส่งต้องดูคิวงาน รอบรถขนส่ง และทะเบียนยานพาหนะของตนเอง | มอบสิทธิ์ `transport.manage` ให้กับบทบาท `DELIVERY` |
| 11 | `DELIVERY` (พนักงานจัดส่ง) | คลังมือถือ (สแกนบาร์โค้ด) | `wh.index`<br>`stock.manage` | พนักงานจัดส่งต้องใช้มือถือสแกนส่งมอบสินค้าหน้างาน | มอบสิทธิ์ `stock.manage` ให้กับบทบาท `DELIVERY` สำหรับสแกนส่งมอบ |
| 12 | `CASHIER` (Cashier) | QR รับเงิน / จอแสดงราคา | `bplus.qr-payments`<br>`settings.manage` | แคชเชียร์ประจำเครื่อง POS ต้องเปิดจอ QR รับเงิน และจอแสดงราคาให้ลูกค้าดู | ปรับสิทธิ์ของหน้า QR รับเงินจาก `settings.manage` เป็น `pos.use` หรือหน้าสาธารณะประจำเครื่อง |
| 13 | `CASHIER` (Cashier) | สมาชิก | `members.index`<br>`masterdata.manage` | แคชเชียร์หน้าร้านต้องรับสมัครหรือค้นหาข้อมูลสมาชิกลูกค้า | แยกสิทธิ์ดู/สมัครสมาชิก `members.manage` ออกจาก `masterdata.manage` หรือให้ `pos.use` เข้าถึงได้ |
| 14 | `ACC` (พนักงานบัญชี) | ลูกค้า (รวมลูกหนี้) | `customers.index`<br>`sales.manage` | พนักงานบัญชีลูกหนี้ (AR) ต้องตรวจสอบข้อมูลลูกค้า เลขประจำตัวผู้เสียภาษี และที่อยู่ออกใบกำกับ | อนุญาตให้ฝ่ายบัญชี (`finance.manage`) เปิดดูข้อมูลลูกหนี้และเงื่อนไขเครดิตได้ |
| 15 | `ACC` (พนักงานบัญชี) | ผู้จำหน่าย / เจ้าหนี้ | `suppliers.index`<br>`purchasing.manage` | พนักงานบัญชีเจ้าหนี้ (AP) ต้องตรวจสอบข้อมูลผู้จำหน่าย ข้อมูลหัก ณ ที่จ่าย และเงื่อนไขเครดิต | อนุญาตให้ฝ่ายบัญชี (`finance.manage`) เปิดดูข้อมูลผู้จำหน่ายและภาษีหัก ณ ที่จ่ายได้ |
| 16 | `ACC` (พนักงานบัญชี) | เอกสารย้อนหลัง | `documents.browser`<br>`sales.manage` | ฝ่ายบัญชีต้องเปิดค้นหาเอกสารขาย/บิลย้อนหลังเพื่อกระทบยอด | พิจารณาเพิ่มสิทธิ์ที่เกี่ยวข้องในบทบาทนี้ |
| 17 | `ACC_MGR` (ผู้จัดการฝ่ายบัญชี) | ลูกค้า (รวมลูกหนี้) | `customers.index`<br>`sales.manage` | ผู้จัดการฝ่ายบัญชีต้องตรวจสอบลูกหนี้ วงเงินเครดิต และอนุมัติเงื่อนไขการค้า | อนุญาตให้ฝ่ายบัญชี (`finance.manage`) เปิดดูข้อมูลลูกหนี้และเงื่อนไขเครดิตได้ |
| 18 | `ACC_MGR` (ผู้จัดการฝ่ายบัญชี) | ผู้จำหน่าย / เจ้าหนี้ | `suppliers.index`<br>`purchasing.manage` | ผู้จัดการฝ่ายบัญชีต้องตรวจสอบเจ้าหนี้ ภาษีหัก ณ ที่จ่าย และเงื่อนไขการชำระ | อนุญาตให้ฝ่ายบัญชี (`finance.manage`) เปิดดูข้อมูลผู้จำหน่ายและภาษีหัก ณ ที่จ่ายได้ |
| 19 | `HR` (พนักงานฝ่ายบุคคล) | พนักงาน | `employees.index`<br>`users.manage` | เจ้าหน้าที่บุคคลมีหน้าที่ดูแลทะเบียนประวัติพนักงาน แต่ปัจจุบันผูกอยู่กับ users.manage | แยกสิทธิ์ `employees.view` ออกจาก `users.manage` เพื่อให้ HR และ ผู้จัดการสาขา ดูประวัติพนักงานได้ |
| 20 | `BRANCH_MGR` (ผู้จัดการสาขา) | พนักงาน | `employees.index`<br>`users.manage` | ผู้จัดการสาขาต้องตรวจสอบรายชื่อพนักงานและแคชเชียร์ประจำสาขา | แยกสิทธิ์ `employees.view` ออกจาก `users.manage` เพื่อให้ HR และ ผู้จัดการสาขา ดูประวัติพนักงานได้ |
| 21 | `BRANCH_MGR` (ผู้จัดการสาขา) | ใบเสร็จรับเงิน (รับชำระลูกหนี้) | `ar-receipts.index`<br>`finance.manage` | กรณีลูกค้านำเงินสดมาชำระหนี้ที่สาขา ผู้จัดการสาขาควรดู/ออกใบเสร็จรับเงินได้ | เพิ่มสิทธิ์ `finance.receipt.view` หรือแยกสิทธิ์การดู/ออกใบเสร็จจาก `finance.manage` |
