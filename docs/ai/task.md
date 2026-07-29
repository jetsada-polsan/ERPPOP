# งานรอตรวจ — 2026-07-29

> ไฟล์นี้ Codex เป็นคนเขียนทุกรอบ รอบนี้ Claude ตั้งต้นให้จากสถานะจริงของ repo
> เพื่อให้ลูปเริ่มทำงานได้เลย รอบหน้า Codex เขียนทับตามรูปแบบใน `WORKFLOW.md`

## ช่วง commit

`origin/main..HEAD` — 7 commit ยังไม่ push

```
b7cb3b8 Add bundle price quantity promotions
7fd248e Cover bundle promotion pricing UAT
3876e75 Add end to end bundle promotion cost UAT
6611d24 Add POS local SQLite diagnostics and recovery
5d805d9 Document POS local SQLite schema
efa08e2 Add POS local SQLite ERD diagram
85184a3 Add POS sales history for 90 days
```

## ทำอะไรไป

ยังไม่ได้สรุปโดย Codex — จากการดู diff คร่าวๆ มีสามกลุ่ม

- โปรโมชันราคาชุด (bundle price) บน `qty_promotions` พร้อม migration ใหม่
- เครื่องมือวินิจฉัย/กู้คืนฐานข้อมูล SQLite ฝั่งเครื่อง POS + เอกสารและ ERD
- ประวัติการขายย้อนหลัง 90 วันในแอป POS

## จุดที่อยากให้ดูเป็นพิเศษ

ยังไม่ได้ระบุ

## ทดสอบไปแล้วแค่ไหน

ยังไม่ได้ระบุ

## ความเสี่ยงที่รู้ตัว

- มี migration ใหม่ `2026_07_28_000001_add_bundle_price_to_qty_promotions` ต้องดูว่ารันได้บน
  PostgreSQL ไม่ใช่แค่ SQLite
- แตะ `PosPricingGuard` ซึ่งเป็นทางเดินของการคิดราคาทุกบิล
- แตะ `apps/pos-desktop/src-tauri/src/lib.rs` (โค้ด Rust) ซึ่ง `vue-tsc` ตรวจไม่ถึง
