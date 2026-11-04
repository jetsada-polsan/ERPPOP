# JET POS Python (UAT candidate)

แอป POS Python/PySide6 ตัวใหม่สำหรับทดสอบคู่กับ `apps/pos-desktop` โดยแยกข้อมูลและตัวติดตั้งจาก JET POS เดิมโดยสิ้นเชิง

## สถานะ

- ใช้เพื่อ UAT เท่านั้น ห้ามใช้ขายจริงหรือเชื่อม production
- แกนราคาใช้ `Decimal` และเริ่มย้ายกติกาโปรโมชันจาก Vue แล้ว
- หน้าจอ `main.py` เป็น desktop shell ตามหน้าจออ้างอิง: แค็ตตาล็อก/ค้นหาซ้าย และตะกร้า/ชำระเงินขวา

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

