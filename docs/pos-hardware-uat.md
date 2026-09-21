# POS hardware UAT

ชุดนี้ใช้ตรวจเครื่องจริงหลังติดตั้ง Python POS โดยไม่ต้องแก้โค้ดหรือเดารูปแบบบาร์โค้ดเอง

## ก่อนเริ่ม

- ให้เครื่อง POS sync catalog และ scale profiles จาก ERP สำเร็จแล้ว
- ติดตั้ง printer driver และให้ Windows เห็นชื่อ printer queue จริง
- ใช้ SQLite ของเครื่องนั้นกับ `--db` และอย่ารันคำสั่งนี้พร้อมการขายจริง

## ตรวจ scanner และสินค้าชั่ง

บน Windows POS เปิด PowerShell ที่โฟลเดอร์โปรแกรม แล้วรัน:

```powershell
python e2e\hardware_uat.py --db C:\PopCentral\popstar-pos.db --scan 8850000000003
```

ให้ยิงบาร์โค้ดสินค้าปกติจริงแทนตัวอย่าง ควรได้ `SCAN PASS [normal]` และจำนวน 1 หน่วย

สำหรับสินค้าชั่ง ให้ยิงป้ายที่ขึ้นต้น 800 หรือ 801 จากเครื่องชั่งจริง:

```powershell
python e2e\hardware_uat.py --db C:\PopCentral\popstar-pos.db --scan 801xxxxxxxxx
```

ควรได้ `SCAN PASS [scale]` พร้อม `qty` ที่คำนวณจากยอดเงินบนป้ายหารด้วยราคาต่อกิโลกรัม
ถ้ากระดาษป้ายหมด ให้เลือกสินค้าชั่งจากหน้าขายและกรอกน้ำหนักเอง ระบบจะใช้เส้นทางเดียวกันในตะกร้า

## ตรวจ printer 80mm

อ่านชื่อ queue จาก Settings > Printers & scanners แล้วตรวจแบบไม่ส่งกระดาษก่อน:

```powershell
python e2e\hardware_uat.py --db C:\PopCentral\popstar-pos.db `
  --printer "Receipt 80mm" --paper-width 80
```

ถ้าชื่อถูกต้อง ให้ทดสอบพิมพ์จริงด้วย `--print` และตรวจว่ากระดาษไม่ล้น/ไม่ตัดชื่อสินค้า/ยอดเงินอยู่ครบ:

```powershell
python e2e\hardware_uat.py --db C:\PopCentral\popstar-pos.db `
  --printer "Receipt 80mm" --paper-width 80 --print
```

## เกณฑ์ผ่าน

1. สินค้าปกติจาก scanner เข้า 1 หน่วยและไม่ถูกตีความเป็นป้ายชั่ง
2. ป้าย 800 และ 801 ที่ ERP sync profile มาแล้วได้สินค้า น้ำหนัก และยอดเงินถูกต้อง
3. เลือกสินค้าชั่งแล้วกรอกน้ำหนักเองได้เมื่อไม่มีป้าย
4. printer queue จริงถูกค้นพบและพิมพ์ใบเสร็จ 80mm ได้
5. หลังตัดเน็ตขายได้เฉพาะภายใน stock snapshot ที่เครื่องรู้ล่าสุด; การขายติดสต๊อกลบต้องกลับมาออนไลน์และใช้สิทธิ์ `pos.sell_negative_stock` พร้อมเหตุผล
