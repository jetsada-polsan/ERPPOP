# ซิงค์ BPlus สาย B18 ขึ้น ERP อัตโนมัติ

เครื่องในร้าน (BK Server) ดึงเอกสารสาย B18 จาก BPlus แล้วส่งขึ้น ERP วันละ 4 รอบ: 12:00, 15:00, 18:00 และ 00:00
แทนการรันสคริปต์บน Mac แล้วอัปโหลดไฟล์ที่หน้า **เอกสารสายขาย (ระบบเดิม)** (วิธีเดิมยังใช้ได้เหมือนเดิม)

## ทำงานอย่างไร
- `scripts/bplus-auto-sync.py` รัน `scripts/bplus-route-export.py` ตั้งแต่วันเริ่ม (`since = 2026-11-01`)
  - อ่าน BPlus แบบ SELECT อย่างเดียว
  - ส่งทุกใบในช่วงทุกรอบ ใบที่แก้หรือยกเลิกใน BPlus จึงตามมาครบ
  - ไฟล์บีบอัดแล้วประมาณ 150 KB ต่อเดือนข้อมูล
- ส่งขึ้น `POST /api/bplus/sync` พร้อม token
  - token ส่งได้แค่สาย B18 ทำอย่างอื่นในระบบไม่ได้
  - ERP บันทึกและสร้างเอกสารแบบเดียวกับตอนอัปโหลดไฟล์
- ผลแต่ละรอบ:
  - หน้า **เอกสารสายขาย (ระบบเดิม)** บรรทัด "ส่งอัตโนมัติล่าสุด"
  - log บนเครื่องในร้านที่ `bplus-sync-work\logs\`
- **ห้ามเลื่อน `since`** ไปข้างหน้า บิลที่อยู่ใน ERP แล้วจะถูกนับเป็นลูกหนี้ยกมาซ้ำ

## ติดตั้ง (ครั้งเดียว)

### 1. บน server ERP: เจ้าของรันเอง
```bash
ssh root@27.254.143.219 "cd /var/www/jeterp && php artisan bplus:sync-token --issue --route=B18 --name='BK Server'"
```
- token แสดงครั้งเดียว ให้คัดลอกไปใส่ไฟล์ตั้งค่าบนเครื่องในร้านเลย **ห้ามวางในแชท**
- ดูรายการ token: `php artisan bplus:sync-token --list`
- เพิกถอน: `php artisan bplus:sync-token --revoke=<id>`

### 2. ที่ BPlus SQL Server: ผู้ดูแล BPlus
สร้าง login สำหรับซิงค์ที่**อ่านได้อย่างเดียว** (db_datareader ของ BPLUSERP_POPSTAR_2021) อย่าใช้ user ของคน

### 3. บนเครื่องในร้าน (Windows)
1. ติดตั้ง Python 3.12 ขึ้นไป (64-bit) ติ๊ก "Add python.exe to PATH"
2. `py -m pip install pymssql`
3. สร้างโฟลเดอร์ `C:\BplusSync` แล้วคัดลอกไฟล์จาก repo ไปวาง:
   - `scripts/bplus-route-export.py`
   - `scripts/bplus-auto-sync.py`
   - `scripts/bplus-auto-sync.ini.example` แล้วเปลี่ยนชื่อเป็น `bplus-sync.ini`
4. แก้ `C:\BplusSync\bplus-sync.ini` ใส่ user/รหัส SQL และ token แล้วล็อกไฟล์ให้ admin อ่านได้คนเดียว:
   ```
   icacls C:\BplusSync\bplus-sync.ini /inheritance:r /grant:r Administrators:F SYSTEM:F
   ```
5. ทดสอบดึงอย่างเดียว ไม่ส่งขึ้น ERP:
   ```
   py C:\BplusSync\bplus-auto-sync.py --dry-run
   ```
6. ทดสอบส่งจริง 1 รอบ แล้วดูหน้า **เอกสารสายขาย (ระบบเดิม)** ว่าขึ้น "สำเร็จ":
   ```
   py C:\BplusSync\bplus-auto-sync.py
   ```
7. ตั้งเวลา 4 รอบ (cmd แบบ Run as administrator, user ที่ใช้ต้องเป็น admin ของเครื่อง):
   ```
   for %t in (12:00 15:00 18:00 00:00) do schtasks /create /tn "BPlus Sync B18 %t" /tr "py C:\BplusSync\bplus-auto-sync.py" /sc daily /st %t /ru SYSTEM /rl HIGHEST /f
   ```
   `/ru SYSTEM` ทำให้รันได้แม้ไม่มีใครล็อกอิน ถ้าลง Python แบบ "ติดตั้งสำหรับผู้ใช้คนเดียว" ให้ใช้ path เต็มของ python.exe แทน `py`
8. ตรวจ: `schtasks /query /tn "BPlus Sync B18 12:00" /v /fo LIST` ดู Last Run Result (0 = สำเร็จ)

## เมื่อมีปัญหา
- **ล้มที่ "ดึงจาก BPlus ไม่สำเร็จ"**: user/รหัส SQL ผิด หรือเครื่องต่อ 192.168.88.200:1433 ไม่ได้
- **ERP ตอบ 401**: token ผิดหรือถูกเพิกถอน ให้ออกใหม่ที่ข้อ 1
- **ERP ตอบ 409**: มีรอบอื่นกำลังซิงค์อยู่ รอบถัดไปจะส่งใหม่เอง
- **มีปัญหา N ใบ**: เปิดหน้า **เอกสารสายขาย (ระบบเดิม)** ดูคอลัมน์ "ใน ERP" เช่น ลูกค้าหรือสินค้าที่ยังไม่มีใน ERP
