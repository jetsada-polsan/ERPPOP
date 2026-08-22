# Legacy Schema Inventory — BPLUSERP_POPSTAR_2021

วันที่: 2026-08-23 · ผู้ทำ: Claude · **สถานะ: ยังทำไม่ได้ — ติดที่ไม่มีเครื่อง SQL Server ให้แนบฐาน**

## สรุปสั้น

งานนี้ต้องแนบฐานแล้วอ่านผ่าน SQL Server **ไม่มีทางลัดที่เชื่อถือได้** และตอนนี้
**ไม่มีเครื่อง SQL Server ที่ผมเข้าถึงได้เลย** จึงยังไม่มี inventory จริงในเอกสารนี้

สิ่งที่ทำได้แล้วโดยไม่ต้องแนบฐาน อยู่ด้านล่าง — รวมถึงเวอร์ชันที่เครื่องปลายทางต้องเป็น
ซึ่งเป็นข้อที่ brief สั่งให้ตรวจก่อน attach

## สิ่งที่ตรวจแล้ว (ไม่ได้แตกไฟล์ทั้งก้อน ไม่ได้แนบฐานที่ไหน)

อ่าน boot page ของ MDF โดยตรง (page 9, offset `0x12000`) โดยสตรีมเฉพาะ 128 KB แรก
ออกจาก ZIP ไม่ได้คลายไฟล์ 3.87 GB ลงดิสก์

| หัวข้อ | ค่า |
|---|---|
| ชื่อฐานในไฟล์ | `BPLUSERP_POPSTAR_2021` |
| page type ที่ตรวจ | 13 (boot page) — ยืนยันว่าอ่านตำแหน่งถูก |
| internal version ที่เขียนล่าสุด | **869 → SQL Server 2017** |
| internal version ตอนสร้างฐาน | 661 → SQL Server 2008 R2 |
| **เครื่องปลายทางต้องเป็น** | **SQL Server 2017 (major version 14) ขึ้นไป** |

หมายความว่าฐานนี้ถูกใช้งานล่าสุดบน SQL Server 2017 ถ้าเครื่องวิเคราะห์เป็น 2016 หรือเก่ากว่า
`FOR ATTACH` จะไม่ผ่าน และถ้าแนบบน 2019/2022 ฐานจะถูก upgrade ทันทีที่แนบ (ทางเดียว ย้อนไม่ได้)
— แต่เราแนบ **สำเนา** จึงไม่กระทบต้นฉบับ

## สิ่งที่อยู่ใน ZIP จริง (ต่างจากที่ brief ระบุ)

brief พูดถึง 2 ไฟล์ แต่ในไฟล์ ZIP มี **12 ไฟล์ 6 ฐานข้อมูล รวม 23 GB**

| ไฟล์ | ขนาดจริง | อยู่ในขอบเขตงานนี้ไหม |
|---|---|---|
| `BusinessData/Data/BPLUSERP_POPSTAR_2021.mdf` | 3.87 GB | ✅ ใช่ |
| `BusinessData/Data/BPLUSERP_POPSTAR_2021_log.ldf` | 35 MB | ✅ ใช่ |
| `BusinessData/Data/BPLUSERP_POPSTAR_2021BK.mdf` + log | 10.0 GB + 2.58 GB | ❌ สำเนาสำรอง ไม่ได้สั่งให้วิเคราะห์ |
| `BusinessData/Data/Pack/POPSTAR_FOOD_TRADING_2023.mdf` | 4.48 GB | ❌ คนละบริษัท |
| `BusinessData/Data/Pack/POPSTAR_64.mdf` | 505 MB | ❌ ฐานปีเก่า |
| `BusinessData/Data/Pack/POPSTART_65.mdf`, `POPSTAR_66.mdf` | 4 MB ต่อไฟล์ | ❌ ฐานว่างเปล่า/โครงเปล่า |

**ข้อควรระวังเรื่องพื้นที่**: แตกทั้ง ZIP ต้องใช้ 23 GB แต่ Mac เครื่องนี้เหลือ 18 GB
ให้แตกเฉพาะ 2 ไฟล์ที่ต้องใช้ (`unzip <zip> "BusinessData/Data/BPLUSERP_POPSTAR_2021*"`)
ซึ่งใช้ประมาณ 3.9 GB

## ทำไมถึงยังทำ inventory ไม่ได้

| เครื่อง | ผลตรวจ |
|---|---|
| Mac เครื่องนี้ | ไม่มี SQL Server และรันไม่ได้ (Microsoft ไม่มีรุ่นสำหรับ macOS) · ไม่มี Docker/Colima/Podman จึงรัน container ไม่ได้ · มีแต่ `tsql` ซึ่งเป็น **client** ต้องมี server ปลายทาง |
| Linux host `27.254.143.219` | ไม่มี SQL Server, ไม่มี container runtime, **RAM 1 GB** (SQL Server ต้องการอย่างน้อย 2 GB) และเป็นเครื่อง production ของ ERP ใหม่ ไม่ควรเอามาลงอะไรเพิ่ม |
| `192.168.88.200` | **ห้ามแตะตามข้อกำหนด** |

ผมไม่แตกไฟล์ 3.9 GB ลงดิสก์ที่เหลือ 18 GB ทิ้งไว้เฉย ๆ เพราะยังไม่มีปลายทางให้คัดลอกไป
ถ้ามีเครื่องแล้วค่อยแตกทีเดียวจบ

## ต้องการอะไรจึงจะเดินต่อได้

เครื่อง **Windows หรือ Linux ที่มี SQL Server 2017 ขึ้นไป** อย่างใดอย่างหนึ่ง:

1. เครื่อง Windows ที่มี SQL Server อยู่แล้ว (เช่นเครื่องที่ใช้ BPlus เดิม แต่เป็น **instance คนละตัว** กับ production)
2. VM/เครื่องใหม่ลง SQL Server 2022 Developer Edition (ฟรี) — ต้องการ RAM 4 GB+ และดิสก์ว่าง 10 GB+
3. เครื่อง Linux ลง `mssql-server` ผ่าน apt/yum — RAM 4 GB+ เช่นกัน

เมื่อมีเครื่องแล้ว ขั้นตอนอยู่ใน `tools/legacy-analysis/README.md`:

1. `tools/legacy-analysis/01_attach_readonly.sql` — ตรวจเวอร์ชัน → แนบ → ล็อก read-only → ยืนยัน
2. **`docs/legacy-schema-inventory.sql`** — สคริปต์เก็บข้อเท็จจริง **ไฟล์กลางที่ Codex กับ Claude ใช้ร่วมกัน**
   (Codex สร้างไว้ใน `b718a18` ผมตรวจแล้วว่าเป็น SELECT ล้วนจริง และเติมส่วนที่ขาดกับแก้บั๊กหนึ่งจุดให้)

ใช้ไฟล์กลางไฟล์เดียวเพื่อให้ตัวเลขที่สองฝั่งได้ตรงกันเป๊ะ ข้อเสนอออกแบบต่างกันได้ แต่ข้อเท็จจริงต้องตรง

## เอกสารนี้จะถูกเติมด้วยอะไร

เมื่อได้ผลจาก `docs/legacy-schema-inventory.sql`:

1. ตารางทั้งหมดพร้อมจำนวนแถวและขนาด แยกเป็น business / system / archive
2. คอลัมน์ ชนิดข้อมูล nullable identity ของทุกตาราง
3. PK, FK (พร้อม referential action), index
4. View, stored procedure, function, **trigger** — trigger สำคัญเป็นพิเศษเพราะ BPlus
   มักซ่อนกฎธุรกิจไว้ในนั้น ถ้าอ่านแต่ตารางจะเข้าใจ flow ผิด
5. ชนิดเอกสารทั้งหมดใน `DOCTYPE` และปริมาณเอกสารรายปีแยกตามชนิด
   ซึ่งจะบอกได้ว่า flow ไหนบริษัทใช้จริงและ flow ไหนมีแต่เมนู
6. ตารางตาม flow ว่ามีอยู่จริงไหมและมีกี่แถว — ตัวไหนขึ้น `missing` คือข้อสันนิษฐานที่ต้องทิ้ง

### สิ่งที่ผมแก้ในสคริปต์กลาง

- **บั๊กจำนวนแถว**: query แรกเดิมทำ `SUM(p.rows)` ผ่าน join ไปยัง `sys.allocation_units`
  ซึ่งคืน 1–3 แถวต่อ partition (IN_ROW / LOB / ROW_OVERFLOW) ตารางที่มีคอลัมน์ LOB
  จึงถูกนับแถว **คูณสองหรือสาม** — `REPORTFILE.RPF_SQL` เป็นตัวอย่างชัด
  แก้เป็นอ่าน `p.rows` จาก heap/clustered index อย่างเดียวและใช้เป็น GROUP BY key
  ส่วนขนาดยังรวมทุก allocation unit เหมือนเดิมเพราะแต่ละหน่วยกินหน้าจริง
- **เพิ่มที่ขาด**: index, trigger, function, การแบ่ง business/system/archive,
  `DOCTYPE`, ปริมาณเอกสารรายปี และตารางตาม flow
- **เพิ่มด่านกัน**: คอลัมน์ `read_only_check` ในผลลัพธ์แรก ถ้าฐานยังไม่ถูกล็อก read-only
  จะขึ้น `STOP` ให้เห็นทันทีก่อนเก็บข้อมูลใด ๆ

## หลักฐานที่มีอยู่แล้วระหว่างรอ

การวิเคราะห์ flow ↔ ตาราง ทำไปได้บางส่วนแล้วโดยไม่ต้องแนบฐาน — อ่านจาก SQL ของรายงานเดิม
1,502 ตัวที่มีอยู่ใน `docs/ai/legacy/reportfile-index.csv` ผลอยู่ที่
`docs/ai/legacy-popstar-2021-report-mapping.md`
