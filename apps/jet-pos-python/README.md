# JET POS Python (UAT candidate)

แอป POS Python/PySide6 ตัวใหม่สำหรับทดสอบคู่กับ `apps/pos-desktop` โดยแยกข้อมูลและตัวติดตั้งจาก JET POS เดิมโดยสิ้นเชิง

## สถานะ

- ใช้เพื่อ UAT เท่านั้น; การต่อ production ต้องเปิดสวิตช์ยืนยันในโปรแกรมและใช้เฉพาะ Device Token ที่เจ้าของออกให้กับเครื่อง UAT
- แกนราคาใช้ `Decimal` และเริ่มย้ายกติกาโปรโมชันจาก Vue แล้ว
- หน้าจอ `main.py` มีการตั้งค่าเครื่อง, โหลดสินค้า/โปรโมชัน, เข้าแคชเชียร์, เปิดกะ และรับชำระเงินสด/โอน/บัตร/เช็ค/ผสม
- ยังไม่ครบ feature parity: พิมพ์ใบเสร็จ, hold bill, คืน/ยกเลิก, สมาชิก, ปิดกะ/Z Report และ offline UI ยังอยู่ระหว่างพัฒนา จึงยังไม่ใช่ตัวแทน Vue POS

## รันชุดทดสอบแกนราคา

```bash
cd apps/jet-pos-python
python3 -m unittest discover -s tests -v
```

## รัน UI (หลังติดตั้ง dependencies)

```bash
python3 -m pip install -r requirements.txt
python3 main.py
```

## ข้อห้าม

- ห้ามนำ device token, PIN หรือข้อมูล production ลงในโฟลเดอร์นี้
- ห้ามแก้ `apps/pos-desktop` หรือ API ERP เพื่อให้แอปนี้ทำงาน
- ก่อนเปรียบเทียบ 50 บิลและ offline 20 บิล ให้ใช้ local ERP หรือ staging เท่านั้น
