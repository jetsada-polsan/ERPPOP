# เครื่องมือวิเคราะห์ฐาน BPlus (อ่านอย่างเดียว)

ชุดสคริปต์สำหรับวิเคราะห์สำเนาฐาน `BPLUSERP_POPSTAR_2021` บนเครื่อง SQL Server แยกต่างหาก

## ข้อห้าม

- **ห้ามรันกับ `192.168.88.200`** (production เดิม) ไม่ว่ากรณีใด
- **ห้ามแนบไฟล์ต้นฉบับ** ให้คัดลอกสำเนาไปเครื่องวิเคราะห์เท่านั้น
- ทุกไฟล์ในโฟลเดอร์นี้เป็น `SELECT` ล้วน ยกเว้น `01_attach_readonly.sql` ที่มี
  `CREATE DATABASE ... FOR ATTACH` และ `ALTER DATABASE ... SET READ_ONLY`
  ซึ่งต้องรันครั้งเดียวตอนแนบ **หลังจากนั้นฐานเป็น read-only ทำอะไรไม่ได้อีก**
- ห้ามนำ `.mdf`, `.ldf`, backup, ข้อมูลลูกค้า หรือ credentials ขึ้น GitHub
  (`.gitignore` ของ repo กันไฟล์ `.mdf`/`.ldf` ไว้แล้ว — ดูด้านล่าง)

## อ่านฐาน live ที่ 192.168.88.200 (คนละงานกับสำเนา MDF)

ใช้ `mssql_readonly.php` เท่านั้น ห้ามต่อด้วยเครื่องมืออื่นที่อาจเขียนข้อมูล

```bash
# ครั้งแรกครั้งเดียว — เจ้าของรันเอง รหัสไม่ผ่านแชตและไม่ลงไฟล์
security add-generic-password -a jetsada -s erppop-legacy-mssql -w

# ยืนยันปลายทางก่อนเสมอ
php tools/legacy-analysis/mssql_readonly.php --db=<ชื่อฐาน> \
  "SELECT @@SERVERNAME AS server_name, DB_NAME() AS database_name"

# เก็บผลทั้งชุด (READ COMMITTED เป็นค่าเริ่มต้น)
php tools/legacy-analysis/mssql_readonly.php --file=docs/legacy-schema-inventory.sql \
  --db=<ชื่อฐาน> --split > legacy-live-output.txt
```

### ระดับ isolation — เรื่องนี้ตัดสินว่าตัวเลขไหนเชื่อได้

| ใช้อ่านอะไร | isolation | เหตุผล |
|---|---|---|
| schema, trigger, index, view, procedure | `--dirty` (READ UNCOMMITTED) ได้ | เป็น metadata ไม่ใช่ยอด |
| **จำนวนเอกสาร ยอดเงิน ต้นทุน สต๊อก เจ้าหนี้ เงินสด UAT** | **ค่าเริ่มต้น (READ COMMITTED) เท่านั้น** | dirty read อ่านแถวที่อาจถูก rollback ทีหลัง เอาไปกระทบยอดไม่ได้ |

ตัวรัน**ปฏิเสธเอง**ถ้าใส่ `--dirty` แล้ว query แตะตารางนอก `sys.` / `INFORMATION_SCHEMA.`
จึงลืมไม่ได้ และค่าเริ่มต้นคือตัวที่ปลอดภัยอยู่แล้ว

## ลำดับการรัน

| ลำดับ | ไฟล์ | ทำอะไร |
|---|---|---|
| 1 | `tools/legacy-analysis/01_attach_readonly.sql` | ตรวจเวอร์ชันปลายทาง → แนบฐาน → ล็อก read-only → ยืนยัน |
| 2 | **`docs/legacy-schema-inventory.sql`** | เก็บข้อเท็จจริงทั้งหมด — ตาราง/แถว/คอลัมน์/PK/FK/index/view/procedure/function/trigger + ชนิดเอกสาร + ปริมาณเอกสารรายปี + ตารางตาม flow |

**`docs/legacy-schema-inventory.sql` เป็นไฟล์กลางไฟล์เดียว** ที่ทั้ง Codex และ Claude ใช้ร่วมกัน
เพื่อให้ข้อเท็จจริงที่ได้ตรงกันเป๊ะ ห้ามแยกไปเขียนสคริปต์เก็บข้อมูลของใครของมัน
ถ้าจะเพิ่ม query ให้เพิ่มในไฟล์นั้นแล้ว push ให้อีกฝั่งใช้ตาม

## ข้อมูลไฟล์ฐานข้อมูล (ตรวจจาก header ของ MDF แล้ว)

| หัวข้อ | ค่า |
|---|---|
| ชื่อฐานในไฟล์ | `BPLUSERP_POPSTAR_2021` |
| internal version ที่เขียนล่าสุด | **869 = SQL Server 2017** |
| internal version ตอนสร้าง | 661 = SQL Server 2008 R2 |
| **เครื่องปลายทางต้องเป็น** | **SQL Server 2017 (major 14) ขึ้นไป** |
| ขนาด MDF | 3.87 GB |
| ขนาด LDF | 35 MB |

ตรวจโดยอ่าน boot page (page 9, offset `0x12000`) ของไฟล์ MDF โดยตรง
ไม่ได้แตกไฟล์ทั้งก้อนและไม่ได้แนบฐานที่ไหน

## ส่งผลกลับมาอย่างไร

รัน `02` และ `03` แล้ว export เป็น CSV/TXT ส่งกลับมา ผมจะเอาไปเขียน
`docs/ai/legacy-popstar-2021-schema-inventory.md` และเติม
`docs/ai/legacy-popstar-2021-report-mapping.md` ให้ครบ

**อย่าส่งข้อมูลลูกค้าจริงมา** — สคริปต์ทั้งชุดอ่านแต่ metadata กับจำนวนแถว
ไม่ได้ดึงเนื้อข้อมูลออกมา ยกเว้น `DOCTYPE` ซึ่งเป็นตารางรหัสชนิดเอกสาร
