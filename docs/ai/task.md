# Handoff — 2026-08-23 รอบที่ 2 (Claude)

## Commit

```
1ec1877 Prepare the legacy database analysis
34c1a87 Correct and complete the shared inventory script
```

ต่อจาก `b718a18` (Codex — Add read-only legacy schema inventory script)

## สถานะงานวิเคราะห์ฐาน BPlus: **ติดบล็อก ยังอ่านฐานไม่ได้**

ไม่มีเครื่อง SQL Server ที่เข้าถึงได้เลย จึงยังไม่มี schema inventory จริง
เอกสาร `docs/ai/legacy-popstar-2021-schema-inventory.md` บอกสถานะไว้ตรง ๆ ไม่ได้เดาข้อมูล

| เครื่อง | ทำไมใช้ไม่ได้ |
|---|---|
| Mac เครื่องนี้ | Microsoft ไม่มี SQL Server สำหรับ macOS · ไม่มี Docker/Colima/Podman · มีแต่ `tsql` ซึ่งเป็น client |
| Linux host `27.254.143.219` | ไม่มี SQL Server, ไม่มี container runtime, **RAM 1 GB** (SQL Server ต้องการ 2 GB ขั้นต่ำ) และเป็น production ของ ERP ใหม่ |
| `192.168.88.200` | ห้ามแตะตามข้อกำหนด |

**ต้องการ**: เครื่อง Windows หรือ Linux ที่มี **SQL Server 2017 ขึ้นไป** (จะเป็น Developer Edition ฟรีก็ได้)
RAM 4 GB+ ดิสก์ว่าง 10 GB+

## สิ่งที่ทำได้แล้วโดยไม่ต้องแนบฐาน

### 1. เวอร์ชันที่เครื่องปลายทางต้องเป็น (ข้อที่ brief สั่งให้ตรวจก่อน attach)

อ่าน boot page ของ MDF โดยตรง (page 9, offset `0x12000`) โดย **สตรีมแค่ 128 KB แรก
ออกจาก ZIP ไม่ได้คลายไฟล์ 3.87 GB ลงดิสก์**

| หัวข้อ | ค่า |
|---|---|
| ชื่อฐานในไฟล์ | `BPLUSERP_POPSTAR_2021` |
| page type ที่อ่านได้ | 13 = boot page (ยืนยันว่าอ่านตำแหน่งถูก) |
| internal version ที่เขียนล่าสุด | **869 = SQL Server 2017** |
| internal version ตอนสร้าง | 661 = SQL Server 2008 R2 |
| **เครื่องปลายทางต้องเป็น** | **SQL Server 2017 (major 14) ขึ้นไป** |

### 2. ZIP มีมากกว่าที่ brief ระบุ

brief พูดถึง 2 ไฟล์ แต่ในนั้นมี **12 ไฟล์ 6 ฐานข้อมูล รวม 23 GB**
มี `BPLUSERP_POPSTAR_2021BK` (10 GB + log 2.58 GB), `POPSTAR_FOOD_TRADING_2023` (4.48 GB),
`POPSTAR_64/65/66` ซึ่งไม่อยู่ในขอบเขตงาน

⚠️ **แตกทั้ง ZIP ต้องใช้ 23 GB แต่ Mac เหลือ 18 GB** — ให้แตกเฉพาะ 2 ไฟล์ที่ต้องใช้:
`unzip <zip> "BusinessData/Data/BPLUSERP_POPSTAR_2021*"` (~3.9 GB)

### 3. flow ↔ ตาราง จากหลักฐานจริง

`docs/ai/legacy-popstar-2021-report-mapping.md` — สร้างจาก **SQL ของรายงานเดิม 1,502 ตัว**
ที่มีอยู่แล้วใน `docs/ai/legacy/reportfile-index.csv` (ดึงชื่อตารางจาก FROM/JOIN)
ไม่ใช่การเดาจากชื่อตาราง แต่**ยังไม่ได้ยืนยันกับฐานจริง**

ช่องว่างที่เห็นแล้ว:
- ❌ **`DEPTTAB` (แผนก) และ `PRJTAB`/`MKTPLAN` (โครงการ)** โผล่ในเกือบทุก flow ของรายงานเดิม
  แปลว่าบริษัทเคยแบ่งยอดตามสองมิตินี้จริง **ERP ใหม่ไม่มีเลย**
- ❌ `CASHACCOUNT` — บัญชีเงินสดหลายบัญชีต่อสาขา ของใหม่มีสมุดเดียวต่อสาขา
- ⚠️ `CHEQUEIN`/`CHEQUEBOOK` (เช็ครับ/เช็คจ่าย) ของใหม่ยุบเป็น `cheques` ตารางเดียว
- ⚠️ `ARCAMPAIGN` ตารางเดียว ของใหม่แตกเป็น 3 ตาราง
- ❓ รายงาน POS 15 ตัว **ไม่มี SQL ในทะเบียนเลย** ตรรกะอยู่ใน `.rpt` ทั้งหมด

## สคริปต์กลาง — ใช้ไฟล์เดียวกันทั้งสองฝั่ง

ตามที่เจ้าของสั่ง ใช้ **`docs/legacy-schema-inventory.sql` ของ Codex เป็นไฟล์กลางไฟล์เดียว**
ผมลบสคริปต์เก็บข้อมูลของตัวเองทิ้ง (เดิมอยู่ที่ `tools/legacy-analysis/02_*.sql`, `03_*.sql`)
แล้วเอาส่วนที่ขาดไปเติมในไฟล์กลางแทน จะได้ไม่มีสองชุดให้ตัวเลขไม่ตรงกัน

**ตรวจความปลอดภัยแล้ว**: ไฟล์ของ Codex เป็น SELECT ล้วนจริง ไม่มี INSERT/UPDATE/DELETE/
MERGE/ALTER/DROP/CREATE/EXEC แม้แต่คำสั่งเดียว

### บั๊กที่เจอและแก้ในไฟล์กลาง (`34c1a87`)

**query แรกนับจำนวนแถวผิด** — เดิมทำ `SUM(p.rows)` ผ่าน join ไป `sys.allocation_units`
ซึ่งคืน 1–3 แถวต่อ partition (IN_ROW_DATA / LOB_DATA / ROW_OVERFLOW_DATA)
ตารางที่มีคอลัมน์ LOB จึงถูกนับแถว **คูณสองหรือสาม** — `REPORTFILE.RPF_SQL` เป็นตัวอย่างชัด
และ BPlus มีตารางแบบนี้เยอะ

แก้เป็นอ่าน `p.rows` จาก heap/clustered index อย่างเดียวและใช้เป็น GROUP BY key
ส่วนขนาด (`total_mb`) ยังรวมทุก allocation unit เหมือนเดิมเพราะแต่ละหน่วยกินหน้าจริง

> ถ้าไม่แก้ ตัวเลข "จำนวนแถวต่อตาราง" ที่เป็นข้อเท็จจริงข้อแรกที่เจ้าของอยากให้ตรงกัน
> จะผิดทั้งสองฝั่งเหมือนกัน — ผิดตรงกันแต่ผิดทั้งคู่

### ที่เติมเข้าไป

index · trigger · function · การแบ่ง business/system/archive · `DOCTYPE` ·
ปริมาณเอกสารรายปีแยกตามชนิด · ตารางตาม flow (พร้อมบอกว่าตัวไหน `missing`) ·
คอลัมน์ `read_only_check` ที่จะขึ้น `STOP` ถ้าฐานยังไม่ถูกล็อก read-only

**trigger สำคัญที่สุดในกลุ่มที่เติม** — BPlus ซ่อนกฎ posting ไว้ใน trigger
ถ้าอ่านแต่ตารางจะ map ผลกระทบต่อสต๊อกและบัญชีของเอกสารผิด

## ทดสอบไปแล้วแค่ไหน

- `php artisan test` → **158 passed / 1,998 assertions** (ไม่ได้แตะโค้ดแอป รอบนี้เป็นเอกสารกับสคริปต์ SQL)
- ตรวจสคริปต์กลางด้วย grep ว่าไม่มีคำสั่งเขียนหลงเหลือ — เจอแต่บรรทัด comment
- **ยังไม่ได้รันสคริปต์กับฐานจริงเลยสักครั้ง** เพราะไม่มี SQL Server

## ยังไม่ทดสอบ / ความเสี่ยง

- **สคริปต์กลางยังไม่เคยรันจริง** — query 12 (`DOCINFO`) ใช้ชื่อคอลัมน์ `DI_REF_TYPE` / `DI_DATE`
  ที่อ่านมาจาก SQL ของรายงานเดิม ถ้าชื่อจริงไม่ตรงจะ error ผมใส่ query 13 เป็น fallback
  ให้ดึงชื่อคอลัมน์จริงของ `DOCINFO` ส่งกลับมาแก้
- การแบ่ง business/system/archive ใช้ pattern ชื่อตาราง ต้องเอาผลจริงมาตรวจว่าจัดถูกไหม
- flow map ทั้งหมดยังเป็นสมมติฐานจากรายงาน ยังไม่ยืนยันกับฐาน
- เพิ่มกฎใน `.gitignore` กัน `*.mdf` `*.ldf` `*.bak` `*.zip` ขึ้น GitHub แล้ว
  (เดิมไม่มีกฎพวกนี้เลย มีแต่ `*.bak`)

## Deploy

ไม่เกี่ยวกับ deploy — รอบนี้ไม่มีการแก้โค้ดแอป
งาน deploy ที่ค้างอยู่ยังเป็นชุดเดิมตาม `docs/ai/deploy-plan-2026-08-23.md`

## งานถัดไป

1. **เจ้าของจัดเครื่อง SQL Server 2017+ ให้** แล้วบอกมาว่าเป็นเครื่องไหน
2. แตกเฉพาะ 2 ไฟล์ → คัดลอกไปเครื่องนั้น → รัน `tools/legacy-analysis/01_attach_readonly.sql`
3. รัน `docs/legacy-schema-inventory.sql` แล้วส่งผลกลับมาเป็น CSV/TXT
4. ผมเติม `legacy-popstar-2021-schema-inventory.md` ให้ครบ และยืนยัน/แก้ flow map
5. จากนั้นค่อยเสนอ mapping กับ P0/P1/P2 ที่อิงข้อเท็จจริงจริง ๆ (ตอนนี้เสนอไว้แล้วแต่ยังอิงรายงาน)
