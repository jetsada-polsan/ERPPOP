## Handoff - 2026-11-05 (Codex: Python POS campaigns → local Laravel E2E)
- Branch: `claude/pos-python-settings-popup`; base `490137b`; commits `22e2349` (isolated runner), `63fa278` (VAT fix + regression), `ba47843` (boundary/mixed/safety coverage), `ca7fb36` (gift assertions/form-header test). Commit เอกสารดู git log หัวข้อนี้
- Git: `git pull origin main` ใน workspace เดิมถูก sandbox ปฏิเสธเพราะ `.git` ชี้ metadata นอกพื้นที่เขียน; clone `--no-hardlinks` ไป `/private/tmp/pos-campaign-work`, คง branch ชื่อเดิมและ origin GitHub เดิม แล้ว pull main สำเร็จ (Already up to date). Commit/push จากสำเนานี้; source ที่แก้คงอยู่ใน workspace เดิม แต่ HEAD/index เดิมยังเป็น `490137b` จึงห้าม commit ซ้ำจาก worktree เก่า ให้รับจาก remote branch/สำเนาดังกล่าว
- ทำอะไร: ทำตาม `docs/ai/codex-pos-python-campaign-e2e.md`; เพิ่ม `apps/pos-python/e2e/pos_campaigns_to_laravel.py` และ README.md. Composer install ผ่าน, สร้าง `.env`/SQLite แยกใน temporary scratch, migrate + seed UAT fixture เดิม, สุ่ม password/PIN/device token สำหรับ DB ทดสอบในขณะรันแล้วลบทิ้ง; ไม่ใช้ credentials จริงและไม่บันทึก plaintext ลงไฟล์/report
- Route หลังบ้าน: login HTTP จริงด้วย user ที่มี `masterdata.manage`, ผ่าน session/CSRF/ErpAuthorize และ `POST /qty-promotions`/validation จริงทุกแคมเปญ; ไม่ disable middleware/ไม่เรียก controller ตรง. ปิดโปรจาก scenario ก่อนหน้าด้วย SQL เฉพาะ DB fixture เพื่อแยกเคส. ทดสอบครบ 8 แบบ รวม discount baht/percent และ bill_discount baht/percent+max_discount, โปรหมดอายุ/อนาคต/ปิด/ต่างสาขา
- POS: `ProvisioningService.ping/sync_down/pull_promotions/online_cashier_login/open_server_shift` เรียก HTTP API จริง; คำนวณด้วย `Order`, บันทึก local-first ด้วย `PosService.checkout`, ส่งด้วย `SyncService`. ERP รับ 41 บิลและเป็น completed/synced ทุกบิล; อีก 1 เคสไม่มีผู้อนุมัติถูกปฏิเสธก่อนเข้าคิว. ตรวจ net/discount/VAT ด้วย Decimal cents, จำนวน/รหัสสินค้า/จำนวนแถว/ของแถมราคา 0 และ transfer last4. มี expected discount แยกจากสูตร production สำหรับ boundary ทั้ง 30 บิล และ expected gift quantity
- พบและแก้เฉพาะ Python: เดิม `SyncService.sync_sale` ไม่ส่ง `vat_amount` ทำให้ ERP บันทึก VAT 0 ขณะที่ POS คิด 9.81 ในบิลแรก. เพิ่ม `vat_amount` จาก snapshot `sales.vat_total` และ `vat_mode=included`; retry ส่งค่าเดิม. เพิ่ม regression test ที่พิสูจน์แดงก่อนแก้ (`KeyError: vat_amount`) และผ่านหลังแก้; ไม่แก้ PHP application/test/migration ใด ๆ
- ผล firstWhere: สินค้าเดียวกันแยก 2 บรรทัด จำนวน 2+7 (ขั้นต่ำ 3) ไม่ได้ลด แม้รวมได้ 9; จำนวน 3+6 ลดเพียงชุดจากบรรทัดแรก 15 บาท ไม่ใช่ 45 บาท. Python/ERP ตรงกันทั้งสองบิล; Order ใน UI อาจรวมสินค้าปกติ แต่ E2E ตั้งแยกบรรทัดโดยตรงเพื่อพิสูจน์ semantics
- การเทียบส่วนลดของแถม: POS แสดงของแถมราคา 0 จึงไม่รวมมูลค่าของแถมใน subtotal/display discount; ERP ใช้ราคา catalog เป็น gross แล้วลดมูลค่าของแถม. คอลัมน์ POS ด้านล่างเพิ่มมูลค่าของแถมกลับในส่วนลดเพื่อเทียบความหมายเดียวกับ ERP; ยอดลูกค้าจ่ายเหมือนกันโดยไม่ปรับยอดสุทธิ
- ทดสอบ: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python -m pytest apps/pos-python/tests -q` **294 passed / 5.48s**; E2E คำสั่งใน README **42/42 ผ่าน** (41 บิลรับ + 1 ปฏิเสธที่คาดไว้); `git diff --check` ผ่าน. ทุกครั้งที่รัน Qt/pytest ตั้ง offscreen
- Laravel: `php -d memory_limit=1G artisan test` เริ่มแล้ว process ลูกหยุดเพราะ memory limit 128MB; รัน `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' php -d memory_limit=1G vendor/bin/phpunit` ต่อจบ exit 0, runner รายงาน `tests=753, passed=751, assertions=6199, skipped=2, incomplete=6, duration_ms=19693` (บันทึก counters ตาม output จริง ไม่ตีความ skipped/incomplete ว่าผ่าน). ไม่แก้ Vue/Tauri จึงไม่รัน vue-tsc
- ความปลอดภัย: runner สร้าง loopback URL เอง ไม่อ่าน POS_E2E_URL/TOKEN/PIN, ปิด proxy, ปฏิเสธ request/redirect ออกนอก literal `http://127.0.0.1`; pytest ตรวจปลายทาง public/domain/IP/credentials/lookalike ถูกบล็อกโดยไม่มี network request. Server/DB ทดสอบถูกปิดและลบทิ้ง. ไม่เรียก production แม้แต่ health/ping และไม่เรียก legacy
- ยังไม่ทดสอบ/ความเสี่ยง: SQLite/HTTP ในเครื่องไม่แทน PostgreSQL หรือ Windows/hardware UAT; ไม่ได้ตรวจ printer/QR bank settlement จริง. ตรวจ VAT ที่ header receipt ตาม snapshot POS; ไม่ได้เปลี่ยน PHP การกระจาย VAT ต่อแถว (ERP เดิมบันทึก item VAT เป็น 0). โปรแกรมไม่แก้สูตรส่วนลด ERP หรือเพิ่ม approval policy ใหม่
- Deploy: **ยังไม่ deploy** และไม่เรียก production ทุกกรณี
- งานถัดไป: เจ้าของ/agent ถัดไปใช้ branch ที่ push แล้วเพื่อ review; UAT/build/deploy ต้องได้รับคำสั่งแยกตาม workflow

ตารางผลรอบสุดท้าย (บาท; แต่ละช่องเรียง **สุทธิ / ส่วนลด / VAT**, receipt references อยู่เฉพาะ DB ทดสอบที่ลบทิ้งแล้ว):

| แคมเปญ / บิล | ยอด POS | ยอด ERP | ผ่าน? |
| --- | ---: | ---: | --- |
| free_item/exact | 150.00 / 20.00 / 9.81 | 150.00 / 20.00 / 9.81 | ผ่าน |
| free_item/below | 100.00 / 0.00 / 6.54 | 100.00 / 0.00 / 6.54 | ผ่าน |
| free_item/multiple | 450.00 / 60.00 / 29.44 | 450.00 / 60.00 / 29.44 | ผ่าน |
| bundle_price/exact | 120.00 / 30.00 / 7.85 | 120.00 / 30.00 / 7.85 | ผ่าน |
| bundle_price/below | 100.00 / 0.00 / 6.54 | 100.00 / 0.00 / 6.54 | ผ่าน |
| bundle_price/multiple | 360.00 / 90.00 / 23.55 | 360.00 / 90.00 / 23.55 | ผ่าน |
| discount-baht/exact | 135.00 / 15.00 / 8.83 | 135.00 / 15.00 / 8.83 | ผ่าน |
| discount-baht/below | 100.00 / 0.00 / 6.54 | 100.00 / 0.00 / 6.54 | ผ่าน |
| discount-baht/multiple | 405.00 / 45.00 / 26.50 | 405.00 / 45.00 / 26.50 | ผ่าน |
| discount-percent/exact | 131.25 / 18.75 / 8.59 | 131.25 / 18.75 / 8.59 | ผ่าน |
| discount-percent/below | 100.00 / 0.00 / 6.54 | 100.00 / 0.00 / 6.54 | ผ่าน |
| discount-percent/multiple | 393.75 / 56.25 / 25.76 | 393.75 / 56.25 / 25.76 | ผ่าน |
| mix_bundle/exact | 120.00 / 20.00 / 7.85 | 120.00 / 20.00 / 7.85 | ผ่าน |
| mix_bundle/below | 90.00 / 0.00 / 5.89 | 90.00 / 0.00 / 5.89 | ผ่าน |
| mix_bundle/multiple | 360.00 / 80.00 / 23.55 | 360.00 / 80.00 / 23.55 | ผ่าน |
| mix_free/exact | 100.00 / 40.00 / 6.54 | 100.00 / 40.00 / 6.54 | ผ่าน |
| mix_free/below | 90.00 / 0.00 / 5.89 | 90.00 / 0.00 / 5.89 | ผ่าน |
| mix_free/multiple | 300.00 / 140.00 / 19.63 | 300.00 / 140.00 / 19.63 | ผ่าน |
| bill_discount-percent/exact | 120.00 / 30.00 / 7.85 | 120.00 / 30.00 / 7.85 | ผ่าน |
| bill_discount-percent/below | 149.00 / 0.00 / 9.75 | 149.00 / 0.00 / 9.75 | ผ่าน |
| bill_discount-percent/multiple | 415.00 / 35.00 / 27.15 | 415.00 / 35.00 / 27.15 | ผ่าน |
| bill_discount-baht/exact | 133.00 / 17.00 / 8.70 | 133.00 / 17.00 / 8.70 | ผ่าน |
| bill_discount-baht/below | 149.00 / 0.00 / 9.75 | 149.00 / 0.00 / 9.75 | ผ่าน |
| bill_discount-baht/multiple | 433.00 / 17.00 / 28.33 | 433.00 / 17.00 / 28.33 | ผ่าน |
| bill_free_item/exact | 150.00 / 20.00 / 9.81 | 150.00 / 20.00 / 9.81 | ผ่าน |
| bill_free_item/below | 149.00 / 0.00 / 9.75 | 149.00 / 0.00 / 9.75 | ผ่าน |
| bill_free_item/multiple | 450.00 / 20.00 / 29.44 | 450.00 / 20.00 / 29.44 | ผ่าน |
| bill_addon/exact | 160.00 / 30.00 / 10.47 | 160.00 / 30.00 / 10.47 | ผ่าน |
| bill_addon/below | 189.00 / 0.00 / 12.36 | 189.00 / 0.00 / 12.36 | ผ่าน |
| bill_addon/multiple | 460.00 / 30.00 / 30.09 | 460.00 / 30.00 / 30.09 | ผ่าน |
| mixed-vat-rounding | 218.12 / 18.88 / 14.27 | 218.12 / 18.88 / 14.27 | ผ่าน |
| item+bill | 190.00 / 50.00 / 12.43 | 190.00 / 50.00 / 12.43 | ผ่าน |
| item+bill+manual-approved | 182.87 / 57.13 / 11.96 | 182.87 / 57.13 / 11.96 | ผ่าน |
| item+bill+manual-unapproved | ปฏิเสธก่อนออกบิล | ไม่ส่ง | ผ่าน (ไม่มีผู้อนุมัติ) |
| duplicate-first-below | 450.00 / 0.00 / 29.44 | 450.00 / 0.00 / 29.44 | ผ่าน |
| duplicate-first-exact | 435.00 / 15.00 / 28.46 | 435.00 / 15.00 / 28.46 | ผ่าน |
| addon-excludes-self | 189.00 / 0.00 / 12.36 | 189.00 / 0.00 / 12.36 | ผ่าน |
| percent-cap | 415.00 / 35.00 / 27.15 | 415.00 / 35.00 / 27.15 | ผ่าน |
| expired | 450.00 / 0.00 / 29.44 | 450.00 / 0.00 / 29.44 | ผ่าน |
| future | 450.00 / 0.00 / 29.44 | 450.00 / 0.00 / 29.44 | ผ่าน |
| disabled | 450.00 / 0.00 / 29.44 | 450.00 / 0.00 / 29.44 | ผ่าน |
| other-branch | 450.00 / 0.00 / 29.44 | 450.00 / 0.00 / 29.44 | ผ่าน |

## Handoff - 2026-11-05 (Codex: Python POS touch popups)
- Branch: `claude/pos-python-settings-popup`; base `514b5d0`; commits `aedfd81` (shared touch controls / popup flows), `6c940f0` (guards, central held bills, tests / capture tool); commit ภาพและ handoff ดู git log หัวข้อนี้
- Git: worktree เดิมเขียน metadata นอก sandbox ไม่ได้ (`FETCH_HEAD: Operation not permitted`); clone `--no-hardlinks` ไป `/private/tmp/pos-popups-work` โดยคง branch ชื่อเดิมและ origin GitHub เดิม แล้ว `git pull origin main` สำเร็จ (Already up to date). แก้ source ใน workspace นี้และคัดลอกเฉพาะไฟล์งานไป commit/push จากสำเนา. HEAD/index ของ workspace เดิมยังอยู่ `514b5d0`; อย่า commit ไฟล์งานนี้ซ้ำจาก metadata เดิม ให้รับงานต่อจาก remote branch หรือสำเนาข้างต้น.
- ทำอะไร: ทำตาม `docs/ai/codex-pos-python-popups.md`; เพิ่ม `TouchDialog`, `NumPad`, `PinPad`, `ValueDialog`, `TextDialog`, `ChoiceDialog` ใช้ร่วมกัน; การ์ดขอบมน/header แบรนด์/บรรทัดรอง/×/backdrop มืด, แป้นจริง ≥64×64 ตัวเลข 24px, ปุ่มยืนยัน ≥56px. เปิดกะและรับเงินใช้ปุ่มยืนยันเต็มคอลัมน์; สีอ้าง PALETTE. Payment สรุป/โปร ≥16px และระยะห่างเพิ่ม, ขนาด 980×744.
- PIN: ตัวเลข ASCII 4–20 หลัก, dots/ล้าง/ลบ, พิมพ์คีย์บอร์ดได้, ปุ่มยืนยันใช้ได้เมื่อครบ 4 หลัก; duplicate PIN เลือกชื่อโดย server ID. เปลี่ยน PIN เป็นสองขั้นพร้อมยืนยันตรงกันและเรียก API เดิมเมื่อ ERP บังคับเปลี่ยน; ไม่บันทึก PIN ลงคิว/ฐานข้อมูลจาก UI.
- ปิดกะ: ดึง active shift จาก ERP ตรวจ server shift ID และใช้ `expected_cash` ล่าสุดเป็นค่าเริ่ม; หากดึงไม่ได้ใช้สรุปในเครื่องพร้อมแจ้งสถานะ. แสดงนับจริงและเกิน/ขาดตามสี. นำส่งเงินเป็นขั้นจำนวนเงินด้วย numpad → เหตุผล/เลขอ้างอิง → บันทึก, ใช้ local-first UUID/outbox เดิมและส่งเลขอ้างอิงถึง API; กลับมาหน้าปิดกะแล้วโหลด expected ใหม่. ปิดไม่ได้เมื่อมีบิล pending/failed ทั้งที่ปุ่ม/accept และ service (ตรวจซ้ำก่อนเปลี่ยนสถานะกะ).
- บิลพัก: ออนไลน์และมีกะที่ ERP ยืนยันแล้วใช้ `/api/pos/held-bills` และ `/resume`; ส่ง server product/cashier/shift IDs, snapshot เก็บส่วนลดและแปลง product ID กลับเป็น local ก่อนเรียกคืน (ตรวจสินค้าครบก่อน consume บิล). บิลจาก Vue ใช้ cart เดิมได้. ออฟไลน์ยังพัก/เรียกในเครื่องได้และรองรับรายการ JSON แบบเก่า; เลือกบิลจากรายการแทน pop เงียบ ๆ. API ล้มเหลวไม่ล้างตะกร้า.
- ประวัติ: เลือกบิลในเครื่อง → รายละเอียด → ยกเลิก/รับคืน. ต้องออนไลน์ มีกะ ERP และบิลมี server receipt; เลือกหัวหน้า/PIN ผ่าน API เดิม. ยกเลิกเฉพาะบิลวันนี้ในกะปัจจุบันที่ยังไม่ void. รับคืนกรอกจำนวนทีละรายการด้วย numpad ไม่เกินขาย; ส่ง server product IDs และไม่ทำเครื่องหมายบิลเป็น void. PIN ไม่เข้าคิวและถูกลบออกจาก payload หลัง request; ERP เป็นผู้ตรวจสิทธิ์และอนุมัติคืนเงินขั้นสุดท้าย.
- Z Report: หลังปิดกะแสดงรายงานและพิมพ์อัตโนมัติเมื่อเลือก Windows printer แล้ว; เปิดกะมีปุ่มพิมพ์รายงานกะล่าสุดซ้ำ. จำ response ปิดกะจาก ERP เพื่อแสดง expected/count เมื่อ shift ID ตรง; fallback ในเครื่องแสดงป้ายชัดเจน. ปิดโปรแกรมแสดงจำนวนตะกร้า/คิว/กะ พร้อมกลับไปขาย/ล็อกหน้าจอ/ปิด; draft และคิวคงอยู่ตาม persistence เดิม.
- เงินทอนส่งข้ามกะ: **ไม่พบการยกเงินทอนอัตโนมัติใน Vue/ERP**: Vue `openShift()` ส่ง `openingCash` ที่กรอก; `PosController::openShift()` รับ `opening_cash` และตั้ง expected เท่าค่านี้ ไม่ดึงยอดกะก่อน จึงคงการกรอกใหม่ใน Python.

| Vue modal | Python dialog / flow | สถานะ |
| --- | --- | --- |
| cashier | ValueDialog + PinPad → pin_login | dots/แป้น/คีย์บอร์ด/ยืนยัน ≥4 หลัก |
| selectCashier | SellerSelectionDialog / ChoiceDialog | โหมดเลือกชื่อ + PIN ซ้ำผูก server ID |
| changePin | ValueDialog + PinPad สองขั้น | ค่าใหม่/ยืนยันตรงกัน/API เดิม |
| shift | OpeningShiftDialog + NumPad | เงินทอน/เปิดกะ/พิมพ์ Z ซ้ำ |
| closeShift | CloseShiftDialog + ValueDialog/TextDialog นำส่ง | ERP expected/นับจริง/ส่วนต่าง/นำส่ง/เลขอ้างอิง/บล็อกบิลค้าง |
| closeApp | TouchDialog ใน closeEvent | แสดง draft/คิว/กะ, กลับไปขาย/ล็อก/ปิด |
| void | TextDialog → ChoiceDialog → ValueDialog(PinPad) | เหตุผล/หัวหน้า/PIN/receipt void API |
| return | TextDialog → ChoiceDialog → ValueDialog(จำนวน/PIN) | จำนวนคืน/หัวหน้า/PIN/receipt return API; ต้องอนุมัติ ERP ก่อนคืนเงิน |
| weight | ValueDialog + NumPad | ทศนิยม/ตรวจน้ำหนักเดิม; ป้ายชั่งยังล็อกจำนวน |
| holdBill | TextDialog | ตั้งชื่อ/ส่วนกลางเมื่อออนไลน์; local fallback |
| heldBills | ChoiceDialog | เลือกบิลส่วนกลางหรือในเครื่อง; รักษาส่วนลด |
| history | ChoiceDialog → TouchDialog รายละเอียด | ประวัติในเครื่อง/เปิดใบเสร็จ/ยกเลิก/รับคืน |
| payment | PaymentDialog + NumPad + ValueDialog เลขท้าย | เงินสด/QR เดิม, สรุปอ่านง่าย/แป้นร่วม/ยืนยันเงินเข้ายังบังคับ |
| Z report | TouchDialog ใน show_z_report | สรุป/พิมพ์เมื่อปิด/เรียกพิมพ์ซ้ำ |

- ทดสอบ: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python -m pytest apps/pos-python/tests -q` ผ่าน **286 tests / 6.02s** (เดิม 278 + ใหม่ 8). ครอบคลุม PIN ใส่/ลบ/ล้าง/คีย์บอร์ดผิดชนิด, decimal/selection, ERP expected ID, เกิน/ขาด, pending/failed และตรวจซ้ำที่ service, cash drop API/เลขอ้างอิง/UUID, return server IDs/ไม่เก็บ PIN, central hold/restore/discount, geometry/ขนาดปุ่ม. เทสต์ UI cleanup Qt widgets ด้วย deferred delete ก่อนปิด DB เพื่อไม่ให้หน้าต่างจากเคสก่อนค้างใน event loop.
- ภาพ: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python apps/pos-python/tools/capture_parity.py` ผ่าน; **17 ภาพ** ใน `docs/ai/pos-python-shots/popups/`, ทุกภาพ 1024×768 (รวม existing shift และสองขั้นนำส่งเงิน). ตรวจภาพจริง opening/payment/closeShift/Z และ contact sheet ทุกภาพแล้ว; bounds ของ dialog และปุ่มตรวจด้วย Qt. ภาพ PIN/void/return/changePin แสดงขั้นกรอก PIN ของ flow; ไม่ได้กรอก credentials จริง. ภาพใช้ SQLite ชั่วคราว ไม่ต่อ production.
- ตรวจเพิ่ม: `git diff --check` ผ่าน. `php artisan test` เริ่มไม่ได้เพราะไม่มี `vendor/autoload.php`; ไม่แก้ PHP หรือ `apps/pos-desktop/` จึงไม่ได้รัน vue-tsc. ไม่เรียก CI/build/install รอบนี้.
- ยังไม่ทดสอบ/ความเสี่ยง: ต้อง UAT Windows ร้านจริง 1024×768, ทัชสกรีน/คีย์บอร์ด/เครื่องชั่ง/สแกนเนอร์/เครื่องพิมพ์, ERP acceptance โดยสิทธิ์จริงของคนขาย/หัวหน้า, ข้ามเครื่องเรียกบิลพักและกรณีเครือข่ายขาดหลัง API รับสำเร็จ, ปิดกะ/คืนบางส่วนหลายครั้ง/ปิดโปรแกรมแล้วเปิดใหม่. Fake API และ offscreen ไม่แทน hardware/ERP UAT. หน้าชำระเงินยังใช้ช่องทางเงินสด/QR ตาม Python เดิม; ไม่ได้เพิ่มระบบ split/card/cheque ในรอบ popup นี้.
- Deploy: **ยังไม่ deploy**; ไม่สร้าง PIN/password/device token ให้ใคร และไม่สร้างธุรกรรม production.
- งานถัดไป: รับ source จาก remote branch แล้วให้เจ้าของสั่ง Windows UAT build/install เพื่อตรวจรายการข้างต้นก่อนปล่อยใช้งาน.

## Handoff - 2026-11-05 (Codex: Python POS promotion/payment parity)
- Branch: `claude/pos-python-settings-popup`; base `8721e55`; commits `1e22fae` (pricing/catalog/migration), `a31a6b9` (touch payment/pairing/persistence/tests); commit ภาพ/handoff ดู git log หัวข้อนี้
- Git: sandbox ไม่อนุญาตเขียน metadata ของ worktree เดิม จึง clone แบบ `--no-hardlinks` ไป `/private/tmp/pos-parity-copy` บน branch ชื่อเดิม, ตั้ง origin เป็น GitHub เดิม และ `git pull origin main` สำเร็จ (Already up to date). แก้ไฟล์ใน workspace เดิมแล้วใช้ Git metadata ของสำเนาสำหรับ commit/push; HEAD/index ของ worktree เดิมจึงยังอยู่ base เดิม (อย่า commit ไฟล์ซ้ำจาก worktree นี้). ก่อนรับงานต่อให้ใช้สำเนานี้หรือ worktree ใหม่จาก remote branch.
- ทำอะไร: รองรับ `discount`, `bundle_price`, `free_item`, `mix_bundle`, `mix_free`, `bill_addon`, `bill_discount`, `bill_free_item`; sync upcoming/bill/mix, เก็บ JSON กลุ่มสินค้า, threshold/cap, SKU/ชื่อ/ราคาของแถม และช่วงเวลา ISO พร้อม timezone ผ่าน additive SQLite migration ที่ไม่ลบข้อมูลเก่า.
- การคิดเงิน: ยึด `PosPricingGuard::qtyPromotionDiscount` ทั้ง mix (แพงไปถูก/แถมถูกสุด), firstWhere ของโปรสินค้า, threshold หลังโปรสินค้า, addon ไม่นับยอดตัวเอง, cap bill percent. คำนวณด้วย Decimal และปัดยอดรวมก่อนกระจายส่วนลดลงบรรทัด โดยไม่ลดเกินยอดบรรทัด. ของแถมเพิ่มอัตโนมัติเป็น item ราคา 0 ทั้งตะกร้า/ชำระเงิน/ใบเสร็จ/คิว; รวมของแถมรหัสเดียวกันเพื่อให้ firstWhere ฝั่ง ERP เห็นจำนวนครบ.
- ส่วนลดท้ายบิล: ช่องกรอกและ numpad ใช้หลังโปร, cap ตามยอดที่เหลือ, ใช้ adjustment/manual_discount_amount/discount_approved_by เดิมและคงกฎ approval เดิม. จำค่าและผู้อนุมัติใน current/held order, พัก/เรียกแบบ LIFO และเปิดแอปใหม่ได้. Checkout ลบ current draft ใน transaction เดียวกับ sale/payment/outbox เพื่อไม่เรียกบิลที่ขายแล้วกลับมาหากแอปล่มก่อนพิมพ์; clear รีเซ็ตส่วนลด/ผู้อนุมัติ.
- คิวเดิม: เพิ่ม flags ต่อ sale แบบ default 0 สำหรับบิลก่อนอัปเกรด; บิลใหม่บันทึก bill/mix=true และส่ง API ทั้งสองค่า, ไม่บังคับใช้โปรใหม่ย้อนหลังกับเงินที่เก็บไปแล้ว. ยังคง UUID/idempotency เดิม.
- UI: ชำระเงินสองคอลัมน์ 980×700 พอดีจอ 1024×768, แสดง subtotal/รายชื่อโปร/ของแถม/ส่วนลดมือ/ยอดสุทธิ/รับเงิน/ทอน, ปุ่มวิธีชำระ/ธนบัตร/numpad สูงอย่างน้อย 56px และ font 18px. QR ยังต้องยืนยันเงินเข้าและเลขท้ายบัญชี; แก้ส่วนลดแล้วสร้าง QR ใหม่และล้างการยืนยันเดิม. สีใช้ PALETTE.
- Pairing: การ์ดขอบมน/header แบรนด์/ปุ่มใหญ่; ช่อง Token ว่างทั้ง wizard และ Settings เมื่อเปิดใหม่, แสดง/วางแบบแทนข้อความเดิม. HTTP error แยก message จาก JSON; 401 แสดงคำแนะนำภาษาไทย ไม่แสดง JSON/Unicode escape ดิบ.
- Vue≠ERP: โปรสินค้า/จำนวนของแถมใช้บรรทัดแรกตาม ERP ไม่รวม qty ทุกบรรทัดแบบ Vue (เช่น 2+2 ชิ้น โปรครบ 3 ไม่เข้าเงื่อนไข). ไม่มี cap ต่อชุดเพิ่มเติมสำหรับ discount แบบ Vue; ปัดยอดโปรรวมครั้งเดียวตาม ERP. ราคาสุทธิใน payload คงความละเอียด 8 ตำแหน่งของ Python เดิม แทน 4 ตำแหน่งของ Vue เพื่อรักษายอดเมื่อ qty มาก; fixture 3×40 เป็น 100 จึงส่ง 33.33333333. API guard ยังเป็นตัวชี้ขาด.
- ทดสอบ: `cd apps/pos-python && QT_QPA_PLATFORM=offscreen /private/tmp/pos-parity-env/bin/python -m pytest -q` ผ่าน **278 tests / 5.98s** (เดิม 253 + เพิ่ม 25), ครอบคลุม Vue UAT fixtures ทุกกลุ่ม, 8 โปร, รวมโปรสินค้า+ท้ายบิล+มือ, VAT, เวลา/timezone, duplicate lines, gifts ซ้อน, allocation, upgrade SQLite เก่า, local checkout/approval/receipt, flags บิลใหม่/เก่า, sync ซ้ำ payload/UUID เดิม, hold/recall/restart, กด keypad ใน dialog จริง และ pairing blank/paste/show.
- ทำซ้ำ: ติดตั้ง `python -m pip install -r requirements-test.txt` ใน `apps/pos-python` แล้ว `QT_QPA_PLATFORM=offscreen python -m pytest -q`. Windows UAT workflow เปลี่ยนให้ติดตั้ง test requirements และใช้ pytest เพื่อรันเคสใหม่ด้วย; ยังไม่ได้สั่ง workflow/build/install รอบนี้.
- ภาพ: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python apps/pos-python/tools/capture_parity.py` ผ่าน; ตรวจภาพจริงแล้ว ปุ่มครบและไม่ล้น. ไฟล์ `docs/ai/pos-python-shots/{cart,payment,pairing}-1024x768.png` ขนาด 1024×768 ทุกภาพ; ใช้ SQLite ชั่วคราว ไม่เรียกเครือข่าย/สร้าง credentials.
- ตรวจเพิ่ม: `git diff --check` ผ่าน; `php artisan test` เริ่มไม่ได้เพราะไม่มี `vendor/autoload.php`; ไม่ได้แก้ PHP หรือ `apps/pos-desktop/` และไม่ได้ทดสอบ Laravel HTTP E2E ในรอบนี้.
- ยังไม่ทดสอบ/ความเสี่ยง: ต้อง UAT บน Windows ร้านจริง 1024×768 ทั้งเงินสด/QR/แก้ส่วนลด, โปรซ้อนและสินค้าที่ขายหลายหน่วย/ป้ายชั่ง, วันเวลาจริงของเครื่อง, catalog/โปรที่ตั้งจริงเทียบ ERP, พักบิลข้ามเปิดแอป, การอัปเกรดเครื่องที่มีคิวเดิม และเครื่องพิมพ์/ทัชสกรีนจริง. Offscreen และ fake-API tests ไม่แทน ERP acceptance หรือ Windows hardware UAT.
- Deploy: **ยังไม่ deploy**, ไม่สร้าง PIN/password/device token, ไม่สร้างธุรกรรม production.
- งานถัดไป: เจ้าของสั่ง build UAT จาก commit ส่งมอบ แล้วติดตั้งบนเครื่องทดสอบและตรวจรายการข้างต้นก่อนใช้งานจริง.

## Handoff - 2026-11-05 (Codex: approved Python POS settings popup UAT)
- Commit: บันทึก handoff เริ่มต้น `c1717e3`; source ที่ทดสอบ/build `87ca3de2fe76696d8d33b8c022249ab8926b32ca`; commit ส่งมอบดู git log ของหัวข้อนี้
- ทำอะไร: ทำตาม `docs/ai/codex-pos-python-handoff.md` บน branch `claude/pos-python-settings-popup`; ไม่ต้องแก้ source หลังเทสต์ผ่าน ใช้ workflow `pos-python-windows-uat.yml` สำหรับ `apps/pos-python` โดยตรง (ไม่ใช่ `jet-pos-python-uat.yml` ซึ่ง build อีกแอป)
- Git: worktree เดิมเขียน Git metadata ไม่ได้เพราะ sandbox; clone local ไป `/private/tmp/pos-python-codex-uat`, เปลี่ยน origin เป็น GitHub เดิม, `git pull origin main` สำเร็จ (Already up to date), ตรวจ remote branch ตรงกับ source ก่อนทำงาน; commit/push จากสำเนานี้
- ทดสอบ: `cd apps/pos-python && QT_QPA_PLATFORM=offscreen python3 -m unittest discover -s tests` ผ่าน 253 tests (4.700s); offscreen UI smoke ใช้ SQLite ชั่วคราวเปล่า ไม่สร้าง credentials เปิดหน้าขายและ resize 1024×768 ผ่าน พร้อมตรวจภาพหน้าจอ; ไม่ใช่ Windows UAT
- Laravel: `php artisan test` เริ่มไม่ได้เพราะไม่มี `vendor/autoload.php`; ไม่ได้แก้ ERP/PHP ในงานนี้
- Build: version `0.1.0-uat.20261105`, `source_ref` เป็น source SHA เต็มข้างต้น, `publish=false`, `build_id=codex-settings-popup-20261105`; workflow https://github.com/jetsada-polsan/ERPPOP/actions/runs/41985915890
- ผล build/ตัวติดตั้ง: workflow สำเร็จ; Windows Python 3.12 ผ่าน 253 tests (50.562s), PyInstaller build, EXE `--self-check`, Inno Setup และตรวจ installer MZ/ขนาด ผ่านทั้งหมด; publish skipped
- ตัวติดตั้ง: `PopCentral-POS-UAT-0.1.0-uat.20261105-setup.exe` (178,678,030 bytes); artifact https://github.com/jetsada-polsan/ERPPOP/actions/runs/41985915890/artifacts/13395474247 (ต้องล็อกอิน GitHub และอยู่ภายใต้อายุเก็บ artifact)
- รอบแรก https://github.com/jetsada-polsan/ERPPOP/actions/runs/41985777307 ล้มเหลวที่ checkout เพราะส่ง SHA ย่อ; สั่งรอบใหม่ด้วย SHA เต็มแล้ว (ยังไม่ถึงขั้น build ในรอบแรก)
- ยังไม่ทดสอบ/ความเสี่ยง: เครื่องทำงานเป็น macOS ไม่มีเครื่อง Windows/เครื่องพิมพ์/สแกนเนอร์ที่เชื่อมต่อสำหรับ UAT; ยังไม่ได้ติดตั้ง EXE หรือทำธุรกรรมจริง การ build บน Windows runner ไม่ทดแทน UAT
- Deploy: ไม่ deploy production; ปิด publish จึงไม่อัปโหลดตัวติดตั้งขึ้น ERP; ไม่แตะ `apps/pos-desktop`; ไม่สร้าง PIN/password/device token ให้ใคร
- งานถัดไป: ดาวน์โหลด artifact ของ workflow ไปเครื่อง Windows ตั้งจอ 1024×768 ใช้ข้อมูล/สิทธิ์ที่เจ้าของเตรียมและให้เจ้าของกรอก credentials เอง แล้วตรวจรายการด้านล่าง บันทึกเลขบิล/ผลพิมพ์และ reconciliation ก่อนยอมรับ UAT

### Windows UAT ที่ยังค้าง (ทั้งหมดไม่ได้รันบนเครื่องจริง)
- [ ] ติดตั้ง UAT แยกจาก Vue POS แล้วเปิดแอป ตรวจหน้าขายและ settings popup ว่าปุ่มครบ ไม่ล้นจอ 1024×768
- [ ] เปิดกะด้วยคนขายที่เจ้าของยืนยัน ตรวจเงินทอนต้นกะและตัวตน/สาขา/เครื่อง
- [ ] สแกนสินค้าจริง ตรวจสินค้า หน่วย จำนวน และราคาเทียบ ERP
- [ ] แก้จำนวนด้วย − / + และ popup; ลบรายการ แล้วตรวจยอดรวม
- [ ] ใช้ส่วนลดที่สิทธิ์อนุญาต ตรวจยอดสุทธิ/ภาษีและกฎราคาของ ERP
- [ ] รับเงินด้วย F12 ตรวจเงินรับ/เงินทอน บิล local-first และสถานะคิว sync ไม่ซ้ำ
- [ ] พิมพ์ใบเสร็จด้วยเครื่องพิมพ์จริง ตรวจภาษาไทย ยอด เลขบิล และพิมพ์ซ้ำไม่สร้างบิลใหม่
- [ ] ปิดกะ ตรวจยอดขาย เงินสด เงินเคลื่อนไหว ผลต่าง และรายงานปิดกะเทียบใบเสร็จ

# Handoff — 2026-11-02 (แก้สีหน้า Executive Dashboard)

## Handoff - 2026-11-05
- Branch / source: `claude/pos-python-settings-popup` / `apps/pos-python` (PopCentral POS) เท่านั้น; ไม่แตะ `apps/pos-desktop` และไม่ deploy production
- Build UAT: GitHub Actions run `41984806803` สำเร็จ; artifact `PopCentral-POS-UAT-0.1.0-dev` พร้อมดาวน์โหลดจาก GitHub Actions (178,173,903 bytes, ยังไม่หมดอายุ)
- ทดสอบ: `cd apps/pos-python && QT_QPA_PLATFORM=offscreen PYTHONPATH=. python3 -m unittest discover -s tests -v` ผ่าน 253 tests / 4.641s; ครอบคลุม shift, สแกน barcode/ตาชั่ง, จำนวน, ส่วนลด, ชำระเงิน, ใบเสร็จ, print queue, offline queue/sync และ UI style
- หน้าจอ 1024x768: source/test ยืนยัน minimum `960x600` และจำกัดฝั่งบิลแบบ responsive ที่ 400-520px เพื่อเหลือพื้นที่ product grid; ยังต้องตรวจด้วยตาบน Windows installer รุ่นนี้ก่อนประกาศผ่าน UAT
- Windows UAT: เริ่มตรวจเครื่อง BK Server แล้ว แต่เครื่องกำลังเปิด `JET POS Python — UAT` รุ่นเดิม (ไม่ใช่ artifact `PopCentral-POS-UAT-0.1.0-dev`) และหน้าจอถูกสลับระหว่างการควบคุม จึงยังไม่สามารถยืนยัน flow เปิดกะ → ขาย → scan → แก้จำนวน/ส่วนลด → รับเงิน → พิมพ์ → ปิดกะ ได้อย่างปลอดภัย
- ขั้นตอนถัดไป: ดาวน์โหลด/ติดตั้ง artifact ข้างต้นบน Windows test machine, ให้เจ้าของกรอก PIN เองหากต้องเชื่อม ERP, และใช้ข้อมูล UAT/offline เท่านั้นก่อนทดสอบ checkout/print; ห้ามสร้างบิลหรือเปิด/ปิดกะบน production โดยไม่ได้รับอนุมัติยืนยันเฉพาะจุด

## Handoff - 2026-11-05
- Commit: pending — compact POS touch keypad
- ทำอะไร: ลด pop-up แป้นตัวเลขของ JET POS Python จากขั้นต่ำ 560×620 เป็น 480×540, ลดปุ่มจาก 72px เป็น 58px, ลด font/margin/spacing เพื่อไม่บังหน้าขาย แต่ยังใหญ่พอสำหรับจอสัมผัส
- ทดสอบ: `PYTHONPATH=apps/jet-pos-python python3 -m unittest discover -s apps/jet-pos-python/tests -v` ผ่าน 25 tests; `QT_QPA_PLATFORM=offscreen` สร้าง `TouchKeypadDialog` และตรวจขนาดใหม่ผ่าน; `git diff --check` ผ่าน
- Deploy: ยังไม่ deploy; ต้อง build installer รุ่นใหม่หลัง push ก่อนเห็นผลบนเครื่อง POS

## Handoff - 2026-11-05
- Commit: pending — one-step POS cashier enablement
- ทำอะไร: เพิ่มปุ่ม `เปิดใช้ POS` ใน จัดการเครื่อง POS → คนขาย & PIN สำหรับคนขายที่มีบัญชี ERP แล้วแต่ยังไม่มีสิทธิ์ขาย; ปุ่มเดียวเพิ่ม role `CASHIER` แบบจำกัดเฉพาะสาขาของคนขาย, เปิดสิทธิ์ `pos.sell`, ถอนการยืนยันเครื่องเดิม และบันทึก audit จากนั้นตั้ง PIN ได้ทันที โดยไม่ให้สิทธิ์ข้ามสาขา
- ทดสอบ: `php artisan test --filter='PosAdminReassignTest|PosInstallPageTest|PosCashierIdentityTest|PosPinChangeTest'` ผ่าน 34 tests / 128 assertions; `php artisan route:list --name=pos-admin.cashiers.enable`, `php artisan view:cache`, และ `git diff --check` ผ่าน
- วิธีใช้หลัง deploy: หน้า จัดการเครื่อง POS → คนขาย & PIN → แถวป้ายแดง → `เปิดใช้ POS` → `ตั้ง PIN` → POS กดเข้ากะและกรอก PIN; หากขึ้น `ผูกบัญชี ERP ก่อน` ต้องผูก user ให้พนักงานครั้งเดียวก่อน
- Deploy: ยังไม่ deploy ERP

## Handoff - 2026-11-05
- Commit: pending — prevent unusable POS cashier PINs
- ทำอะไร: พบว่า ERP สามารถออก/ตั้ง PIN ให้รหัสคนขายที่ไม่ผูกบัญชี ERP หรือไม่มีสิทธิ์ `pos.sell` ในสาขาเครื่อง ทำให้ Python POS ได้ข้อความรวมว่า `PIN ไม่ถูกต้อง` แม้ PIN เพิ่งออก; เพิ่มการตรวจสิทธิ์ก่อนออก/ตั้ง PIN, หน้า จัดการเครื่อง POS แสดง `ไม่มีสิทธิ์ POS/สาขา`, ปิดช่องตั้ง PIN ของรหัสนั้น และนับความพร้อมเฉพาะคนขายที่เข้าเครื่องได้จริง
- ทดสอบ: `php artisan test --filter='PosInstallPageTest|PosCashierIdentityTest|PosPinChangeTest'` ผ่าน 31 tests / 112 assertions; `PYTHONPATH=apps/jet-pos-python python3 -m unittest discover -s apps/jet-pos-python/tests -v` ผ่าน 25 tests; `git diff --check` ผ่าน
- วิธีแก้เครื่องที่ใช้งานอยู่: เปิด ERP → จัดการเครื่อง POS → คนขาย & PIN; ถ้าเห็นป้ายแดง ให้ผูกบัญชี ERP ของคนขาย, เปิดบัญชี และให้สิทธิ์ `pos.sell` ในสาขาเดียวกับเครื่อง (`B001` สำหรับ `POS-B001-01`) ก่อนตั้ง PIN ใหม่ แล้วเปิดกะ
- Deploy: ยังไม่ deploy ERP หรือทดสอบขายจริง

## Handoff - 2026-11-05
- Commit: pending — simplify Python POS checkout panel
- ทำอะไร: ตามการตรวจหน้าจอ BK Server เอาปุ่มซ้ำ 6 ปุ่มออกจากฝั่งบิลทั้งหมด โดยใช้คำสั่งบน header เพียงชุดเดียว; ฝั่งสินค้าเปลี่ยนเป็นกริด 3 คอลัมน์ การ์ดสูงพอดีสำหรับจอสัมผัส และจัดรหัส/ชื่อ/คงเหลือ/ราคาให้เหมือน Vue POS
- ทดสอบ: `PYTHONPATH=apps/jet-pos-python python3 -m unittest discover -s apps/jet-pos-python/tests -v` ผ่าน 25 tests; `QT_QPA_PLATFORM=offscreen` UI smoke ตรวจจำนวน 3 คอลัมน์/ปุ่มรับชำระ/สถานะกะ ผ่าน; `git diff --check` ผ่าน
- ยังไม่ทดสอบ: เปิดกะบนเครื่อง BK Server ปัจจุบัน ERP ตอบ `PIN ไม่ถูกต้อง`; ต้องออก PIN คนขายที่ถูกต้องใน ERP ก่อนจึงทดสอบ shift/checkout จริงได้
- Deploy: ไม่ deploy ERP; push branch เพื่อ build/publish UAT installer แยก

## Handoff - 2026-11-05
- Commit: pending — make Python POS touch state truthful
- ทำอะไร: ทำหัวสาขา/เครื่องและแถบกะแสดงข้อมูลจริงแทนตัวเลขตัวอย่าง, ปุ่มรับชำระเปิดเฉพาะเมื่อมีทั้งกะที่เปิดจริงและรายการสินค้า, และกำหนด size policy ของการ์ดสินค้าให้ย่อเข้ากริดได้โดยไม่เกิด horizontal overflow บนจอ POS
- ทดสอบ: `PYTHONPATH=apps/jet-pos-python python3 -m unittest discover -s apps/jet-pos-python/tests -v` ผ่าน 25 tests; `QT_QPA_PLATFORM=offscreen` smoke สร้าง `PosWindow`, ตรวจ state ปิดกะ/เปิดกะและปุ่มรับชำระ ผ่าน; `git diff --check` ผ่าน
- UAT เครื่อง BK Server: ดาวน์โหลด `JET-POS-Python-UAT-0.1.7.exe` แล้ว แต่ Windows SmartScreen บล็อกการเริ่มติดตั้ง; ต้องให้เจ้าของกด More info → Run anyway เอง ห้าม agent ข้ามหน้าคำเตือน
- Deploy: ไม่ deploy ERP/ไม่สร้างบิลจริง; รอ push branch เพื่อ build installer UAT รุ่นใหม่
- งานถัดไป: ติดตั้งรุ่นใหม่บน BK Server, ให้เจ้าของกรอก PIN เองเพื่อทดสอบเปิดกะ, แล้วเก็บข้อความ ERP จริงหากเปิดกะไม่สำเร็จ ก่อนยิงบิลทดสอบ

## Handoff - 2026-11-05
- Commit: pending — compact Python POS product grid
- ทำอะไร: จากการตรวจบน BK Server พบการ์ดสินค้าสูง/กว้างเกินและชื่อยาวบังคับให้กริดล้นจอ; ปรับเป็นกริด 4 คอลัมน์, การ์ดสูง 96px, ตัดชื่อที่ยาวด้วย `…`, ซ่อน horizontal scroll ของ catalog และลด spacing ให้ใกล้ Vue POS
- ทดสอบ: `PYTHONPATH=apps/jet-pos-python python3 -m unittest discover -s apps/jet-pos-python/tests -v` ผ่าน 25 tests; `QT_QPA_PLATFORM=offscreen` UI smoke ผ่าน; `git diff --check` ผ่าน
- Deploy: ไม่ deploy ERP; push branch เพื่อ build/publish installer UAT แยก
- งานถัดไป: ติดตั้ง UAT ใหม่บน BK Server แล้วตรวจ card density/search/cart; การทดสอบ checkout จริงต้องให้เจ้าของกรอก Token/PIN เองและยืนยันก่อนสร้างบิล

## Handoff - 2026-11-04
- Commit: pending — fix Python POS UAT product-card rendering
- ทำอะไร: แก้หน้าจอที่ตรวจจากเครื่อง BK Server แล้วพบแท็ก HTML (`<div>`, `<b>`, `<span>`) หลุดเป็นข้อความในปุ่มสินค้าและหัว/รายการตะกร้า โดยเปลี่ยนเป็นข้อความ Qt ปกติและกำหนด style ของวิดเจ็ตโดยตรง; ลด toolbar ที่กว้างเกินจอเป็นคำสั่งหลัก 6 ปุ่ม และเมนู `เพิ่มเติม` สำหรับคำสั่งรอง เพื่อให้ catalog กับตะกร้าอยู่ใน viewport เดียวบนจอ 1366px
- ทดสอบ: `PYTHONPATH=apps/jet-pos-python python3 -m unittest discover -s apps/jet-pos-python/tests -v` ผ่าน 25 tests; `QT_QPA_PLATFORM=offscreen` เปิด `PosWindow` ผ่าน; `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ต้อง build Windows installer และติดตั้งทับบน BK Server เพื่อตรวจหน้าจอจริงก่อนทำรายการขาย; ห้ามถือว่า feature parity กับ Vue POS ครบจากการทดสอบนี้
- Deploy: ไม่ deploy ERP; workflow UAT จะ publish เฉพาะ installer แยกเมื่อ push branch
- งานถัดไป: ดาวน์โหลด installer รุ่นใหม่, ตรวจหน้า setup/product/cart/payment บน Windows และขอผู้ใช้กรอก Device Token/PIN เองก่อนทดสอบกับ ERP

## Handoff - 2026-11-04
- Commit: pending — Python POS UAT follow-up
- ทำอะไร: เพิ่ม Activation Code pairing, cache credential สำหรับยืนยัน PIN แบบออฟไลน์โดยไม่เก็บ PIN ดิบ, บังคับใช้ออฟไลน์เฉพาะแคชเชียร์/กะเดิมที่ ERP เคยยืนยัน, ประวัติขายและ backup SQLite, พร้อม flow UI สำหรับสินค้า/โปรโมชัน/พักบิล/ชำระ/ใบเสร็จ/ปิดกะ/คืน/ยกเลิก
- ทดสอบ: `QT_QPA_PLATFORM=offscreen` UI smoke ผ่าน และ `python3 -m unittest discover -s tests -v` ผ่าน 25 tests
- สถานะ: รอ push เพื่อให้ workflow `jet-pos-python-uat.yml` build/publish installer UAT รุ่นใหม่
- ความเสี่ยง: ยังไม่ผ่าน parity UAT 50 บิลและ offline 20 บิล; installer นี้ใช้เพื่อทดสอบเท่านั้น ไม่ใช่ตัวแทน Vue POS ในการใช้งานจริง

## Handoff - 2026-11-04
- Commit: `e738e0c` — `Add separate JET Python POS download channel`
- ทำอะไร: เพิ่มช่องทาง installer แยกของ `apps/jet-pos-python` ที่ `/download/jet-pos-python` และปุ่ม `JET POS Python UAT` วางข้าง Vue/Tauri กับ Python เดิมใน `/pos-admin` โดยแยกโฟลเดอร์ release เป็น `storage/app/jet-pos-python-releases` จึงไม่ปะปนกับ POS เดิม
- สถานะ: หากยังไม่มีไฟล์ `JET-POS-Python-UAT-<version>.exe` บนเซิร์ฟเวอร์ จะแสดงปุ่ม disabled `รอทดสอบ`; เมื่อวางไฟล์ที่ผ่าน UAT แล้วจะเปลี่ยนเป็นปุ่มดาวน์โหลดอัตโนมัติ
- ทดสอบ: `php artisan test tests/Feature/PosReleaseTest.php tests/Feature/PosInstallPageTest.php` ผ่าน 12 tests / 43 assertions, `php -l` ไฟล์ PHP ที่แก้ และ `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: workflow build อยู่ใน branch `codex/jet-pos-python`; GitHub จะกด `workflow_dispatch` ได้เมื่อ workflow อยู่ default branch เท่านั้น จึงยังไม่มี installer ให้ดาวน์โหลด และห้ามใช้ Python UAT ขายจริง
- Deploy: ยังไม่ deploy ตามขอบเขต UAT
- งานถัดไป: ทำ Python POS ให้ครบ API/event, printer, shift, payment และ offline parity ตาม `docs/ai/python-pos-brief.md`; รัน 50 บิล parity + 20 บิล offline ก่อนอนุมัติ installer
- หมายเหตุ deploy: workflow production `41804987520` ถูกหยุดที่ test ก่อนขึ้น production เพราะ `PermissionMatrixTest` ยังไม่ได้ลงทะเบียน route download ใหม่; แก้ matrix แล้วและ full suite ผ่านในเครื่องก่อน retry
- Deploy: production สำเร็จผ่าน GitHub Actions run `41805639194` ที่ commit `d134d4c`; full test, frontend build, upload และ `scripts/deploy.sh` ผ่านแล้ว
- Installer UAT: GitHub Actions run `41806471427` build/test/publish สำเร็จ; ดาวน์โหลดได้จาก `https://erp.popstarcenter.com/download/jet-pos-python` เป็น `JET-POS-Python-UAT-0.1.1.exe` (49,179,131 bytes) ในช่อง release แยก

## ทำอะไรไป
- แก้หน้า `/executive` ที่จางเพราะอ้าง CSS variable `--erp-info` ซึ่งไม่มีการประกาศ
- เปลี่ยนการ์ด KPI, เส้นกราฟ, จุดกราฟ, แถบยอดขาย และข้อความเน้น ไปใช้สีหลักของธีมที่มีอยู่จริง

## ทดสอบไปแล้วแค่ไหน
- `php artisan view:cache` ผ่าน
- Executive, layout และ POS install tests: 21 tests / 100 assertions ผ่าน
- `git diff --check` ผ่าน

# Handoff — 2026-11-03 (ย่อคอลัมน์รายงานสินค้าคงเหลือ)

## ทำอะไรไป
- ปรับรายงานสินค้าคงเหลือให้แสดงเฉพาะ รหัส, ชื่อ, จำนวน
- ใช้ชุดคอลัมน์เดียวกันกับหน้าจอ, พิมพ์, CSV และ XLSX
- คงข้อมูลสาขาไว้ที่หัวเอกสาร ไม่แสดงซ้ำในแต่ละแถว

## ทดสอบ
- `php artisan test --filter='StockBalanceReportTest|ReportSmokeTest' --no-coverage`: 10 tests ผ่าน, 1 skipped
- `php artisan view:cache` ผ่าน
- `git diff --check` ผ่าน

# Handoff — 2026-11-03 (แสดงหน่วยใหญ่และหน่วยย่อยในจำนวนคงเหลือ)

## ทำอะไรไป
- คงรายงานไว้ 3 คอลัมน์: รหัส, ชื่อ, จำนวน
- แปลงจำนวนหน่วยฐานเป็นรูปแบบหน่วยใหญ่และหน่วยย่อย เช่น `2 ลัง 3 ชิ้น`
- ใช้ตัวคูณหน่วยใหญ่ที่มากที่สุดของสินค้า โดยไม่แก้ยอดสต็อกจริง

## ทดสอบ
- `php artisan test --filter='StockBalanceReportTest|ReportSmokeTest' --no-coverage`: 10 tests ผ่าน, 1 skipped
- `php artisan view:cache` ผ่าน
- `git diff --check` ผ่าน

## งานถัดไป
- Deploy production และตรวจหน้า Executive หลัง cache clear

# Handoff — 2026-11-03 (มาตรฐานพิมพ์เอกสารและหน่วยคงเหลือ)

## ทำอะไรไป
- ปรับธีมพิมพ์ร่วมของเอกสาร A4 ให้ตารางไม่ถูก `overflow` ตัดหลังหน้าแรก, หัวตารางซ้ำทุกหน้า และแถว/ลายเซ็นไม่แตกกลางหน้า
- เอาความสูงหน้ากระดาษที่บังคับไว้และมุมตกแต่งออกเฉพาะตอนพิมพ์ เพื่อไม่สร้างหน้าว่างและไม่กินพื้นที่หัวเอกสาร
- ไม่ประกาศ `@page` ในธีมร่วม จึงคง A5 ใบส่งของและกระดาษความร้อน 80mm ของ Z Report ตามแม่แบบของแต่ละเอกสาร
- ย้ายการแสดงยอดสต๊อกเป็น `StockQuantityFormatter` กลาง: รายงานสินค้าคงเหลือและสต๊อกต่ำ/ติดลบแสดง `หน่วยหลัก + หน่วยย่อย` จาก pack ใหญ่ที่สุด เช่น `2 ลัง 3 ชิ้น`

## ทดสอบ
- `php artisan view:cache` ผ่าน
- `php artisan test --filter='QuotationConversionTest|BookingDeliveryAndCashTransferTest|SalesDocumentEndToEndTest|PrintablePageSizeTest|StockBalanceReportTest' --no-coverage`: 40 tests / 303 assertions ผ่าน
- `git diff --check` ผ่าน

## งานถัดไป
- ยังไม่ได้ deploy; ต้องเปิด Chrome print preview เอกสารหลายหน้าอย่างน้อยใบเสนอราคา, ใบจอง/ขาย, ใบส่งของ A5 และรายงานสต๊อก ก่อนสั่งพิมพ์จริง
- ระบบหลักเป็น Laravel multi-page; Vue ใช้เฉพาะ POS/launcher จึงไม่สามารถปิดวงกลมของ Chrome ได้ ต้อง profile response/query และค่อยวาง navigation layer แบบ Turbo/Inertia หากต้องการเปลี่ยนประสบการณ์ทั้งระบบ

# Handoff — 2026-11-02 (ใช้โลโก้ MPM และชื่อระบบ JET ERP)

## ทำอะไรไป
- ให้โลโก้มุมซ้ายกลับมาใช้โลโก้บริษัท MPM จากการตั้งค่าปัจจุบัน
- แสดงชื่อระบบ `JET ERP` แยกจากชื่อบริษัทบน navigation และแผงเมนู
- บังคับฟอนต์ของปุ่มและช่องกรอก รวมถึงหัวข้อ ให้สืบทอดฟอนต์กลางของระบบเพื่อให้ทุกหน้าสมดุล

## ทดสอบไปแล้วแค่ไหน
- ตรวจ Blade cache และทดสอบ layout ที่เกี่ยวข้องหลังแก้ไข

## งานถัดไป
- Deploy เมื่อเจ้าของสั่ง และตรวจหน้าองค์กร/แดชบอร์ดทั้ง classic กับ Odoo

# Handoff — 2026-11-02 (แยกโลโก้ระบบ JET ERP จากโลโก้บริษัท)

## ทำอะไรไป
- ปรับ layout ให้เมนูระบบใช้ตรา `logo-jet-erp-mark.svg` ที่พอดีกับพื้นที่ แทนโลโก้บริษัทที่อัปโหลด
- แสดงชื่อระบบ `JET ERP` บนแถบระบบและข้างโลโก้เมนูย่อย
- คง `$companyLogo` และชื่อบริษัทไว้สำหรับส่วนเอกสาร/การตั้งค่าที่เกี่ยวข้อง ไม่เปลี่ยนข้อมูลโลโก้บริษัท

## ทดสอบไปแล้วแค่ไหน
- `git diff --check` ผ่าน
- ยังไม่ได้รัน full test suite; เป็นการเปลี่ยน Blade/CSS เฉพาะ layout

## งานถัดไป
- ตรวจหน้า dashboard ทั้ง layout แบบ classic และ Odoo หลัง deploy/cache clear

# Handoff — 2026-11-01 (ลบเล่มใบจอง TMP ที่ว่างและเตรียม deploy)

## Commit
- `cd2f8f8` — `Remove empty legacy booking books`

## ทำอะไรไป
- ลบเล่มใบจอง legacy `BOOKING-TMP-*` เฉพาะรายการที่ไม่มีเอกสารผูกอยู่; รายการที่มีประวัติจะคงไว้แต่ปิดใช้งาน
- เพิ่มเมนูตรง `นโยบายขายติดลบ` ในหมวดตั้งค่าระบบ; ยังคุมการแก้ไขเฉพาะบทบาท `IT_MGR`
- ปิดการแก้ไขตัวเลือกสาขาสำหรับผู้ใช้ที่ไม่มีสิทธิ์ IT และขยายโลโก้บน rail ให้แสดงผลอ่านง่ายขึ้น

## ทดสอบไปแล้วแค่ไหน
- `php -d memory_limit=1G vendor/bin/phpunit --filter='NegativeStockPolicy|DocumentBook'`: 10 tests / 48 assertions ผ่าน
- `php -d memory_limit=1G vendor/bin/phpunit`: 698 tests, 697 ผ่าน, 1 skipped, 6 incomplete, 5,903 assertions
- `git diff --check` และ PHP syntax check ผ่าน

## ความเสี่ยง/งานถัดไป
- Deploy Production สำเร็จผ่าน GitHub Actions run `41315819875`; tests, build, upload และ deploy script ผ่าน
- migration ลบเฉพาะเล่ม `BOOKING-TMP-*` ที่ไม่มีเอกสารผูก และปิดใช้งานตัวที่มีประวัติ ไม่ลบเอกสารย้อนหลัง
- ตรวจหน้า `/document-books`, `/settings/stock-policies`, dashboard และใบพิมพ์หลัง deploy

# Handoff — 2026-11-01 (เปิดใช้แบรนด์ Meaw Foods)

## Commit
- `09e7bfb` — `Activate Meaw Foods branding`

## ทำอะไรไป
- เปิดใช้ชื่อแบรนด์ `เหมียวฟู๊ด / Meaw Foods` และโลโก้ที่ติดตั้งไว้ ผ่าน migration แบบ key-value โดยไม่ลบหรือแก้ธุรกรรมเดิม
- ปรับ login, ERP header/sidebar, favicon และจุดแสดงเอกสารให้ใช้โลโก้/ชื่อแบรนด์ปัจจุบันแทนค่าที่ hardcode
- คงโครงสร้าง single-brand เดิมไว้ตามคำสั่ง เพื่อขยาย multi-brand ภายหลัง

## ทดสอบไปแล้วแค่ไหน
- `php -d memory_limit=1G vendor/bin/phpunit --filter='SystemSetting|ReceiptTemplate|MemberPortal|PrintablePageSize'`: 9 tests / 34 assertions ผ่าน
- `php -d memory_limit=1G vendor/bin/phpunit`: 698 tests, 697 ผ่าน, 1 skipped, 6 incomplete, 5,903 assertions
- `git diff --check` และ PHP syntax check ผ่าน

## ความเสี่ยง/งานถัดไป
- Deploy Production สำเร็จผ่าน GitHub Actions run `41312221640`; เทสต์, frontend build, upload และ deploy script ผ่าน
- migration จะเปลี่ยนเฉพาะค่า branding และไม่แตะ tax/address/ยอดขาย
- ควรตรวจหน้า login, sidebar, ใบส่งของ และ POS receipt บน browser หลัง cache หมดอายุ

# Handoff — 2026-10-31 (แสดงบาร์โค้ดและค้นหาสินค้าอัตโนมัติ)

## Commit
- `a58769d` — `Show product barcodes and auto-search catalog`

## ทำอะไรไป
- ตารางสินค้าแสดงบาร์โค้ดทุกชุดของสินค้า; บาร์โค้ดที่ปิดใช้งานแสดงสีจางและขีดฆ่า โดยไม่มีการแก้รหัสหรือบาร์โค้ดในข้อมูล
- ค้นหาได้จาก SKU, ชื่อไทย, ชื่ออังกฤษ และบาร์โค้ด; ค้นหาอัตโนมัติหลังหยุดพิมพ์ 0.5 วินาที และยังกด Enter/ปุ่มค้นหาได้
- โหลดบาร์โค้ดของสินค้าในหน้าเดียวกันแบบ eager load เพื่อไม่ยิง query แยกต่อแถว

## ทดสอบไปแล้วแค่ไหน
- `php -d memory_limit=1G vendor/bin/phpunit --filter ProductCatalogFilterTest`: 3 tests / 34 assertions ผ่าน
- `php -d memory_limit=1G vendor/bin/phpunit`: 683 tests (682 ผ่าน, 1 skipped), 5,771 assertions; incomplete 6
- `vendor/bin/pint --test app/Http/Controllers/ProductController.php tests/Feature/ProductCatalogFilterTest.php` และ `git diff --check` ผ่าน
- `php artisan erp:health` ในเครื่อง dev: ฐานข้อมูล, ขาย-GL, ตรวจนับ, storage และ queue ผ่าน; migration ค้าง 25 รายการและไม่พบไฟล์ backup ใน local environment

## ความเสี่ยง/งานถัดไป
- ยังไม่ได้ deploy; ไม่มี migration หรือการแก้ข้อมูล production ในงานนี้
- ส่งขึ้น `main` แล้ว; deploy เมื่อเจ้าของสั่งตาม `docs/OPERATIONS.md` จากนั้นตรวจค้นหา SKU/ชื่อ/บาร์โค้ดและการแสดงบาร์โค้ดบนหน้า production

# Handoff — 2026-10-31 (เขียนทุนซื้อล่าสุดกลับ ERP และตรวจส่วนลด POS)

## Commit
- Deploy production จาก `58069af` ผ่าน GitHub Actions run `#109` (`41187776749`)
- งานนี้แก้ handoff เท่านั้น ไม่มีการแก้ source code

## ทำอะไรไป
- เขียน `last_purchase_cost` และ `last_purchase_cost_at` จากคิว BPlus ให้ ERP 1,380 SKU ใน transaction เดียว
- ข้าม SKU `208010`: ERP มีต้นทุนลงวันที่ 29 ต.ค. ใหม่กว่า BPlus วันที่ 28 ต.ค.; เทียบหน่วยครบ 1,381 SKU รวมรูปแบบ `กิโลกรัม*1` ที่เท่ากับหน่วยฐานคูณ 1
- ตรวจหลังเขียนครบ 1,380 แถว; `average_cost`, ราคาขาย, รหัสสินค้า, บาร์โค้ด, สต๊อก, บิลขายและบัญชีไม่ถูกแก้
- เก็บค่าก่อนเขียนเพื่อ rollback ไว้ที่ `outputs/01a17cae-92e2-7d10-8969-5e71afd637de/erp-latest-cost-writeback-before-20261031.csv`; ไฟล์มีข้อมูลต้นทุนและไม่ได้ commit
- ตรวจบิล POS วันที่ 31 ต.ค. แบบอ่านอย่างเดียว: 16 บิล มี 5 บิลระบุส่วนลดรวม 165 บาท แต่ยอดสุทธิ/ใบขาย/รับเงินจริงเท่ากับยอดก่อนลด บิลเหล่านี้เกิดก่อนโค้ดแก้สรุปส่วนลดถูก deploy; ไม่แก้ประวัติการขายเพื่อไม่ให้ยอดรับเงินจริงและ GL คลาดเคลื่อน
- ตรวจ source ที่ production ใช้อยู่ พบการส่งสรุปยอดสุทธิจาก `PosPricingGuard`; ยังไม่มีบิลส่วนลดหลังแก้ไขให้ยืนยันใน live โดยไม่สร้างยอดทดสอบจริง

## ทดสอบ
- `PosWebCheckoutRealTest` และ `PosWorkbenchTest`: 9 tests / 61 assertions ผ่าน
- `apps/pos-python/tests/test_sync_service.py`: 12 tests ผ่าน
- GitHub Actions: `php -d memory_limit=1G vendor/bin/phpunit` ผ่าน 682 tests (681 passed, 1 skipped, 6 incomplete); pnpm install/build ผ่าน
- Production `php artisan erp:health` ก่อนและหลัง deploy ผ่าน: DB, migrations, backup, ขาย-GL, ตรวจนับ, storage และ queue
- `php artisan test` บนเครื่อง dev ใช้หน่วยความจำ subprocess 128MB จนล้มใน XLSX export; คำสั่งเดียวกับ workflow ที่กำหนด 1GB ผ่านครบ
- Writeback เป็น transaction และตรวจยืนยันทุกค่า; ไม่มี SKU/บาร์โค้ด/ราคา/ทุนเฉลี่ยเปลี่ยน

## ความเสี่ยง/งานถัดไป
- ให้บัญชี/เจ้าของตรวจหลักฐานของ 5 บิลเก่าก่อนตัดสินใจแก้เอกสารหรือคืนเงิน; ห้ามปรับยอดย้อนหลังจากค่า discount อย่างเดียว เพราะยอดรับเงินจริงยังเต็มราคา
- ยังต้องยืนยันส่วนลดด้วยบิลขายหลังแก้ไข; รอบนี้ไม่สร้างบิลทดสอบ Production
- deploy production สำเร็จ; run `41187776749` จบ `success`

---

# Handoff — 2026-10-31 (ต้นทุนซื้อล่าสุด BPlus พร้อมรหัสสินค้าและบาร์โค้ดเดิม)

## Commit
`9e3dfe6` — ฐานโค้ดล่าสุด; งานนี้ไม่มีการแก้ source code, SKU, barcode, ราคาขาย หรือฐานข้อมูล ERP

## ทำอะไรไป
- สร้างรายงานในเครื่อง `outputs/01a17cae-92e2-7d10-8969-5e71afd637de/bplus-latest-purchase-costs-sku-barcodes.xlsx`; ไม่ commit ไฟล์เพราะมีข้อมูลต้นทุน
- รวม BPlus 4,201 SKU และบาร์โค้ด 10,685 แถว พร้อมเทียบ ERP; ยืนยันค่ารหัสสินค้า, บาร์โค้ด และราคาขายตรงกับ snapshot เดิมทุกแถว
- เลือกใบซื้อไม่ถูกลบ/ยกเลิก (IB, IB-N, CB) ล่าสุดตามวันที่/DI_KEY; ใช้ `TRD_B_UPRC` เทียบ `SKU_LAST_UCCOST` และจับคู่หน่วยสต๊อก `SKU_K` กับหน่วยฐาน ERP
- ผ่านเกณฑ์ 1,440 SKU: 1,381 แถวเป็นคิวปรับเฉพาะ `last_purchase_cost`; 59 แถวต้นทุน ERP ตรงอยู่แล้ว; 77 แถวต้องตรวจ; 47 SKU ที่มีใบซื้อยังไม่มีใน ERP; 2,637 SKU ไม่มีใบซื้อที่เข้าเงื่อนไข
- สรุป barcode: 8,998 exact matches, 1,687 ไม่พบ exact match, ราคาขายต่าง 7 คู่; ใช้ตรวจสอบเท่านั้น ไม่มีการแก้ราคา

## ทดสอบไปแล้วแค่ไหน
- BPlus ใช้ SELECT อย่างเดียว; ไม่มีการเขียนฐานข้อมูลต้นทาง
- สร้าง/เปิดไฟล์ XLSX กลับและ render ตรวจครบ 5 แผ่น; ตรวจจำนวนแถวและยืนยันว่า SKU, บาร์โค้ด และราคาขายทุกแถวตรงกับข้อมูลอ้างอิงเดิม
- ไม่มีการรันชุดทดสอบโค้ด เพราะไม่มีการเปลี่ยน source code

## ความเสี่ยง/งานถัดไป
- ยังไม่ได้เขียนต้นทุนกลับ ERP; ไม่แตะ `average_cost`, SKU, barcode หรือราคาขาย
- ก่อนนำเข้า ให้ทบทวน 77 รายการที่ต้นทุน/หน่วย/หลายบรรทัดไม่ผ่าน และ 47 SKU ที่ยังไม่มีสินค้าใน ERP; ใช้แผ่น “คิวอัปเดตต้นทุน” สำหรับ 1,381 ค่าเฉพาะ `last_purchase_cost` ที่ผ่านเกณฑ์
- ยังไม่ deploy

---

# Handoff — 2026-10-31 (ค้นหาสินค้าด้วยบาร์โค้ดและเทียบทะเบียน BPlus)

## Commit
- `a110746` — `Search products by barcode without auto-refresh`

## ทำอะไรไป
- หน้า `/products` ค้นหาได้ด้วย SKU, ชื่อ และบาร์โค้ด; กด Enter หรือปุ่มค้นหาเพื่อส่งคำค้น ไม่มี full-page refresh อัตโนมัติระหว่างพิมพ์
- เพิ่ม regression test สำหรับค้นหาด้วยบาร์โค้ด
- เทียบแค็ตตาล็อก BPlus กับ ERP แบบอ่านอย่างเดียวและทำรายงาน `outputs/01a17cae-92e2-7d10-8969-5e71afd637de/product-reconciliation.xlsx` ในเครื่อง รายงานไม่ได้ commit เพราะมีข้อมูลต้นทุน
- พบ SKU BPlus 4,201 รายการ, ERP 4,057, ขาดใน ERP 144 (ในนั้น BPlus เปิดใช้ 91); บาร์โค้ดเดิม 10,685, จับคู่ SKU+barcode ได้ 8,998, ไม่พบ exact match 1,687 (103 รายการเป็นบาร์โค้ดและ SKU ที่เปิดใช้ทั้งคู่)
- ราคาขายที่เทียบได้ 7,656 คู่ ต่าง 7 คู่; ไม่มีการแก้ SKU, barcode หรือราคาขาย
- ต้นทุน BPlus (`SKU_STD_COST`, `SKU_LAST_UCCOST`) และ ERP (`average_cost`, `last_purchase_cost`) แสดงในรายงานพร้อมหน่วยและตัวคูณ: เทียบฐานหน่วยตรง 1,605 SKU; standard-vs-average มีค่าทั้งคู่และหน่วยตรง 1,317 คู่ โดยต่างเกิน 0.01 จำนวน 27 คู่; last-cost เทียบตรงได้ 8 คู่และต่างเกิน 0.01 จำนวน 1 คู่ จึงยังไม่เขียน/นำเข้าต้นทุนอัตโนมัติ
- อ่าน BPlus ด้วย SELECT เท่านั้น; อ่าน ERP ใน read-only transaction แล้ว rollback; ไม่มีการเขียนข้อมูล production และไม่ได้ deploy

## ทดสอบ
- `ProductCatalogFilterTest`: 2 tests / 16 assertions ผ่าน
- PHP suite after rebase on latest `origin/main`: 680 tests, 679 passed, 1 skipped, 6 incomplete, 5,739 assertions
- Pint และ `git diff --check` ผ่าน

## งานถัดไป
- หากจะนำต้นทุนเข้า ERP ให้กำหนดนโยบายว่าจะใช้ standard cost หรือ last cost และตรวจการแปลงหน่วย/ต้นทุน 28 รายการต่างเกิน 0.01 ก่อน; ห้ามเปลี่ยนบาร์โค้ดหรือราคาขายตามขอบเขตรอบนี้

# Handoff — 2026-10-31 (แก้สิทธิ์เครื่อง POS และยอดส่วนลด ERP)

## Commit
`9a2f8a9c677577731a1a41f44f9f62c1d3a99ea0` — `Correct POS device access and discount reporting`

## ทำอะไรไป
- `/api/pos/ping` ใช้สาขาจากเครื่องก่อน และ fallback ไปสาขาบัญชีเมื่อ token ยังไม่ได้ผูกสาขา ให้ตรงกับข้อมูลที่ ping ส่งคืน
- ยืนยันขอบเขตสิทธิ์เดิม: `pos.use` เปิดดู POS, `pos.sell` เปิดกะ/ขาย; `IT_MGR` ใช้เครื่องข้ามสาขาได้โดยไม่ต้องเพิ่มบทบาท GM ส่วนผู้จัดการสาขาที่มีเพียงสิทธิ์ดูยังขายไม่ได้ ต้องใช้บัญชีผู้ขายที่มี `pos.sell`
- ให้ `PosPricingGuard` ส่งสรุปยอดที่คำนวณจากราคาฝั่ง ERP และส่วนลดที่ตรวจสอบแล้ว; ใบรับ POS เก็บยอดก่อนลด/ส่วนลด/สุทธิ และหน้าเครื่องมือ POS แสดงทั้งสามยอด
- ข้อความแจ้งสิทธิ์บนหน้า POS ระบุ `CASHIER` หรือ `IT_MGR` ตามหน้าที่ แทนการแนะนำให้เพิ่มผู้จัดการทั่วไป

## ทดสอบไปแล้วแค่ไหน
- `php -d memory_limit=1G vendor/bin/phpunit`: 670 tests, 669 passed, 1 skipped, 6 incomplete, 5,700 assertions
- ชุดเฉพาะสิทธิ์/checkout/report: 40 tests, 1,197 assertions ผ่าน
- Pint และ `git diff --check` ผ่าน
- `php artisan test` ใช้ memory limit เริ่มต้น 128MB แล้วล้มที่ `ReportExportTest` ขณะตรวจ MIME ของไฟล์ XLSX; รัน PHPUnit โดยตรงพร้อม 1GB แล้วผ่านครบ

## Deploy รอบนี้
- เจ้าของสั่ง `deploy`; ก่อน deploy รัน Production check #9 บน commit `d7ec7bf` ผ่าน: backup `erp-db-20261031-102916.sql.gz` (804 KB), restore drill ผ่านและลบทิ้ง, health `ok`, migrations pending `0`
- Snapshot อ่านอย่างเดียว: POS วันนี้ 6 บิล, offline needs review `0`, desktop rejects วันนี้ `0`; ไม่มีการสร้างหรือแก้บิลจาก check
- ปรับ `.github/workflows/deploy.yml` ให้รัน `php -d memory_limit=1G vendor/bin/phpunit` โดยตรง เพราะ `artisan test` spawn PHPUnit ใหม่และชน memory limit 128MB แม้ parent process ใช้ 1GB; คำสั่งตรงรันครบ 670 tests ผ่าน
- Deploy ERP [run #101](https://github.com/jetsada-polsan/ERPPOP/actions/runs/41164725214) บน commit `f760f989a27d7f81cd003c5d14ecfc24a62dea5b` สำเร็จ: เทสต์ 670 รายการ (669 ผ่าน, 1 skipped, 6 incomplete; 5,700 assertions), build frontend และ deploy script ผ่าน
- Production check หลัง deploy [run #10](https://github.com/jetsada-polsan/ERPPOP/actions/runs/41164972015) ผ่าน: backup `erp-db-20261031-103452.sql.gz` (804 KB), restore drill PostgreSQL ผ่านและลบฐานทดสอบแล้ว, counts ตรงกัน, `health=ok`, migrations pending `0`, failed queue `0`
- Snapshot หลัง deploy แบบอ่านอย่างเดียว: POS วันนี้ 6 บิล, offline needs review `0`, desktop rejects วันนี้ `0`; ไม่มีการสร้างหรือแก้บิลจาก check

## ความเสี่ยง/งานถัดไป
- เครื่อง POS ใช้งานได้ตามรายงานของทีม; ยังต้องทดสอบบัญชีจริงตามบทบาท โดย `IT_MGR` เข้าเครื่องข้ามสาขาได้ ส่วนการเปิดกะ/ขายต้องใช้ผู้ขายที่มี `pos.sell` (ไม่จำเป็นต้องเพิ่ม GM ให้ IT)
- GitHub แจ้งเตือนว่า actions/checkout@v4 และ webfactory/ssh-agent@v0.9.0 จะถูกบังคับใช้ Node.js 24 แทน Node.js 20; ไม่กระทบผล deploy รอบนี้ แต่ควรอัปเดต actions ก่อนการเปลี่ยน runtime รอบหน้า
- `docs/servers.md` เป็นไฟล์ untracked เดิมและไม่ได้แก้หรือรวมใน commit

# Handoff — 2026-10-30 (Codex: ตรวจ matrix สิทธิ์ทุก role และ route)

## Commit
- `dd8fce3` — `Audit role permissions and branch scopes`

## ทำอะไรและผลตรวจ
- เพิ่มชุดทดสอบเทียบ permission grants ของทั้ง 13 role กับสิทธิ์ทั้ง 35 รายการ รวมสิทธิ์ system.admin และตรวจ route เว็บที่ตั้งชื่อ 431 เส้นว่า map permission หรือเป็น public exception ที่ระบุชัด
- ตรวจ middleware allow/deny สำหรับ permission ที่ map อยู่, route อนุมัติสำคัญ และ API POS 24 เส้น, member 6 เส้น, OCR 9 เส้น; OCR API บังคับ `purchasing.manage` ที่ controller อยู่แล้วและมี regression test ครบทุก endpoint
- ปิดช่องอ่านยอดสต็อก/lot ข้ามสาขาจาก search endpoint, ไม่ส่ง `average_cost` ผ่าน product picker และแก้กรณี assignment สาขาที่ inactive/future/expired แล้วสิทธิ์ global เปิดทางกลับมาทุกสาขา
- `PermissionMatrixTest`: 9 tests / 1,091 assertions ผ่าน
- PHP suite ทั้งชุด: 665 tests, 664 ผ่าน, 1 skipped, 6 incomplete, 5,680 assertions
- Pint และ `git diff --check` ผ่าน

## Deploy และงานถัดไป
- `docs/servers.md` เป็นไฟล์ untracked เดิมและไม่ได้รวมใน commit; production check ใช้ฐาน restore drill แยกและลบทิ้งหลังตรวจเสร็จ
- PostgreSQL UAT [run 41076076617](https://github.com/jetsada-polsan/ERPPOP/actions/runs/41076076617) ผ่านครบ: PHP suite, ยิงพร้อมกัน 30 process สร้างเลขเอกสาร, ขายพร้อมกัน 30 process และ reconcile stock/COGS/GL/sales_postings
- ยังไม่ได้ทดสอบอุปกรณ์หน้าร้านจริง

## Deploy production — 2026-10-30
- Production check [run 41078569501](https://github.com/jetsada-polsan/ERPPOP/actions/runs/41078569501): backup `erp-db-20261030-221252.sql.gz` ขนาด 800 KB, restore drill ผ่านและจำนวนแถว 8 ตารางตรงกัน, ฐาน drill ถูกลบ, health ผ่าน, migrations pending 0, failed queue 0
- Deploy ERP [run 41078964196](https://github.com/jetsada-polsan/ERPPOP/actions/runs/41078964196) บน release commit `72dc05f52cd79a60d29f26ed8f23a590b726fdbf`: test และ frontend build ผ่าน, deploy สำเร็จ, `Nothing to migrate`, `erp:health` ผ่าน และแอปกลับมา live
- ยังไม่มี `rclone` และไฟล์ offsite backup config บน production; backup ปัจจุบันเป็น local-only และควรตั้งค่า/ทดสอบ DR แยกต่างหาก

# Handoff — 2026-10-30 (Codex: regression audit ERP และ POS ทั้งระบบ)

## Commit
- `8971126` — `Use valid branch codes in PostgreSQL tests` (push แล้ว)

## ทำอะไรและผลตรวจ
- รัน ERP suite บน SQLite: 656 tests, 655 ผ่าน, 1 skipped, incomplete 6, 4,589 assertions
- `RouteIntegrityTest`: 3 tests / 928 assertions ผ่าน; ตรวจ 476 routes, GET pages, route references และ method ของฟอร์มโดยไม่ยิง mutation ไป production
- PostgreSQL UAT [run 41071605247](https://github.com/jetsada-polsan/ERPPOP/actions/runs/41071605247): full suite ผ่าน 656 tests / 4,590 assertions (incomplete 6); migration และ workflow concurrency ผ่าน
- UAT concurrency: 30 process สร้างเลขเอกสารและ 30 process ขายจริง (รวม 150 sales documents) พบ error/deadlock/เลขซ้ำ 0; reconcile ผ่านทุกเกณฑ์ — GL ดุล, GL ต้นทุน 7,125.00 ตรงกับบรรทัดขาย, ยอดขาย 15,000.00 ตรงกับรายได้ 14,509.50 + VAT 490.50 และสต๊อกตัด 150 ตรงรายการขาย 150
- POS Python รุ่นใช้งาน [Windows UAT run 41069707946](https://github.com/jetsada-polsan/ERPPOP/actions/runs/41069707946): 244 tests ผ่าน, Windows build, packaged smoke test และ installer verification ผ่าน; upload เป็น UAT artifact และ publish ถูกข้ามตาม `publish=false`
- POS Central candidate [Windows UAT run 41066981240](https://github.com/jetsada-polsan/ERPPOP/actions/runs/41066981240): 52 tests, Windows build และ ZIP artifact ผ่าน
- Legacy POS desktop CI [run 41070454333](https://github.com/jetsada-polsan/ERPPOP/actions/runs/41070454333): Vue typecheck, unit tests, web build และ Rust build ผ่าน

## สิ่งที่พบและแก้
- PostgreSQL ครั้งแรกจับ fixture ใน `BookingSalesAreaTest` และ `SalesDocumentEndToEndTest` ใช้รหัสสาขายาวเกิน schema `branches.code` ที่กำหนด 10 ตัว (SQLite ไม่บังคับความยาว); ย่อเฉพาะรหัสทดสอบแล้วรัน SQLite และ PostgreSQL suite ผ่าน

## ขอบเขตที่ยังต้องทดสอบหน้างาน
- ไม่ได้คลิกทุกปุ่มที่สร้าง/อนุมัติ/ลบข้อมูลบน production และไม่มี production data ถูกสร้างหรือแก้ไขในรอบนี้; route/form contract และ end-to-end ใช้ test DB แทน
- ยังไม่ได้ต่อเครื่องจริงเพื่อทดสอบ scanner, เครื่องชั่ง, ลิ้นชักเงิน และเครื่องพิมพ์ใบเสร็จ; ควรทำ UAT ด้วยอุปกรณ์และสินค้าเดโมก่อนเปิดขาย
- ไม่มีการ deploy ในงานนี้; production deploy workflow เป็น manual-only

# Handoff — 2026-10-30 (คำนวณยอดขาย POS Central ให้ตรง ERP)

## Commits
- `aa4686a` — `Fix POS Central transaction calculations`
- `9a1873b` — `Align POS Central regression tests with precise values`
- `ef082eb` — `Correct POS receipt test totals`

## ทำอะไรไป
- ใช้ Decimal และกติกาปัดครึ่งขึ้นแบบ ERP: จำนวน/ราคาหน่วยเก็บ 8 ตำแหน่ง, รวมบรรทัดก่อนปัดยอดบิล, ส่วนลด/เงินทอน/VAT ปัดเป็นสตางค์; กระจายเศษส่วนลดและยอดแสดงรายบรรทัดให้รวมตรงกับยอดบิล
- คำนวณโปรซื้อครบ/ชุดราคา/แถมจากแคช ERP ระหว่าง offline และส่งจำนวน/ราคาความละเอียดเต็มกลับ API; ราคาลดต้องจัดสรรได้ตรงยอดก่อนอนุญาตให้รับเงิน
- เก็บข้อมูลหน่วยบาร์โค้ด น้ำหนัก และราคาให้ครบตอนพัก/เรียกบิล; ใบเสร็จแสดงน้ำหนักที่ขายจริง; บิลยกเลิกเก็บยอดก่อนลด ส่วนลดโปร และยอดที่ต้องชำระแยกกัน
- รองรับการตรวจยอดเงินสด, QR, เงินสด+โอน, บัตรเครดิต และเช็ค รวมถึงยอดแยกแต่ละช่องทางในรายงานกะ

## ทดสอบ
- Windows UAT run [41066981240](https://github.com/jetsada-polsan/ERPPOP/actions/runs/41066981240) ผ่านครบ: 52 pytest, PyInstaller build, ZIP package และ upload artifact
- Artifact `PopCentral-POS-Central-UAT-0.1.0-dev`, ID `12984470046`, ขนาด 285,957,118 bytes; ดาวน์โหลดจากหน้า [GitHub Actions run](https://github.com/jetsada-polsan/ERPPOP/actions/runs/41066981240)
- AST parse ทุกไฟล์ Python ใน `apps/pos-central` ผ่าน; สุ่มตรวจสูตรส่วนลด/ปัดยอดรายบรรทัด 10,000 กรณีผ่าน; ทดสอบ VAT และตัวอย่างโปรซื้อครบผ่าน
- `php -d memory_limit=512M artisan test --filter='PosWebCheckoutRealTest|PosPricingGuardTest'` ผ่าน 16 tests / 41 assertions; `git diff --check` ผ่าน
- รัน pytest/PySide UI tests บน Mac นี้ไม่ได้ เพราะไม่มี `pytest`, PySide6 และ SQLAlchemy; ใช้ Windows UAT runner ยืนยันชุด UI tests แทน

## Deploy production
- Deploy ERP workflow [41068609157](https://github.com/jetsada-polsan/ERPPOP/actions/runs/41068609157) สำเร็จจาก commit `40175f6a41852701813bd1ff0613f3c80d57140f` หลัง full PHP tests และ frontend build ผ่าน
- Production deploy อัปโหลด release, รัน deployment script, migration (คง current), clear/cache routes/views และ `erp:health`; database, migrations, backup freshness, sales-to-GL, pending stock count, storage และ queue ผ่าน
- ก่อน deploy รัน Production check [41068504714](https://github.com/jetsada-polsan/ERPPOP/actions/runs/41068504714): backup `erp-db-20261030-210355.sql.gz` ขนาด 800 KB; restore drill ผ่านและจำนวนแถว 8 ตารางตรงกัน; health ผ่าน, migrations pending 0
- Production check พบ `pos_receipts_today=22`, `offline_needs_review=0`, `desktop_rejects_logged_today=0`; deployment ไม่ได้สร้างบิลขายใหม่

## ความเสี่ยง/งานถัดไป
- ยังไม่ได้ตรวจเครื่องชั่ง/เครื่องสแกน/เครื่องพิมพ์จริง; ทดสอบอุปกรณ์จริงและเทียบยอดก่อนใช้ demo
- `apps/pos-central` ยังเป็น candidate; installer หลักยังชี้ `apps/pos-python`; deploy รอบนี้อัปเดต ERP/API ไม่ได้เปลี่ยนช่องทาง installer POS
- ส่วนลดที่ต้องลดเฉพาะรายการชั่ง/โปรที่ลงกับฉลากชั่งยังไม่รองรับ; ระบบบล็อกกรณีที่กระจายส่วนลดให้ ERP ตรวจราคาได้ไม่ตรง ต้องทดสอบป้ายชั่งจริงร่วมกับโปรก่อนเปิดใช้กรณีนั้น
- Production inspect พบไม่มี `rclone` และ `scripts/backup/production.env`; local backup กับ restore drill ผ่าน แต่การตั้งค่า offsite backup ยังเป็นงานค้าง

# Handoff — 2026-10-30 (ปรับ UI ทุก flow ของ POS Central)

## Commit
`cb4f0a8` — `Polish POS Central screens with shared theme`

## ทำอะไรไป
- เพิ่มธีมร่วมให้ทุกหน้าต่างหลักและ dialog ของ POS Central: หน้าขาย, เข้าระบบ/ผูกเครื่อง, เปิด-ปิดกะ, สแกน/ชั่งน้ำหนัก, ส่วนลด, พักบิล, ชำระเงิน, ใบเสร็จ, ซิงก์, คู่มือ, จอแสดงลูกค้า และรายงานกะ
- ปรับโทนสีหลักให้สอดคล้องกัน เพิ่มรูปแบบช่องกรอก ตาราง ปุ่ม โฟกัส และแถบเลื่อน พร้อมคงสีเขียว/แดงสำหรับสถานะสำเร็จและข้อผิดพลาดตามความหมาย
- ไม่เปลี่ยนกติกาขาย การคำนวณ หน่วยสินค้า การตัดสต็อก การชำระ หรือการส่ง API; เพิ่มการทดสอบสัญญาธีมส่วนกลาง

## ทดสอบ
- `git diff --check` ผ่าน
- `PYTHONPYCACHEPREFIX=/private/tmp/poscentral-theme-pycache python3 -m compileall -q apps/pos-central/ui apps/pos-central/tests` ผ่าน
- ตรวจ AST ครบ 17 window/dialog constructors และยืนยันว่าเรียกธีมส่วนกลางครบ
- รัน `python3 -m pytest -q apps/pos-central/tests` ในเครื่อง dev ไม่ได้ เพราะไม่มี `pytest`/PySide6/SQLAlchemy ติดตั้ง; หลัง push, workflow `POS Central Desktop UAT for Windows` run `41058958611` ผ่านครบทั้ง tests, Windows build, ZIP และ upload artifact
- Artifact `PopCentral-POS-Central-UAT-0.1.0-dev` (SHA-256 `b79e8e17afa816176cd22b1be68201a30deb37b63569ef005667a9f18ffce1f0`), ใช้ดาวน์โหลดจาก [GitHub Actions run](https://github.com/jetsada-polsan/ERPPOP/actions/runs/41058958611)

## ความเสี่ยง/งานถัดไป
- ยังไม่ได้ตรวจภาพจริงบนเครื่อง Windows POS; ให้ติดตั้ง artifact UAT แล้วลองขนาดตัวอักษร/หน้าจอและอุปกรณ์สัมผัสก่อน demo
- เป็น UI ของ candidate `apps/pos-central`; POS หลัก `apps/pos-python` และ ERP Web ไม่ได้เปลี่ยน
- ไม่ deploy production และไม่สร้าง/แก้บิล production จากงาน UI รอบนี้

# Handoff — 2026-10-30 (ผ่อนเงื่อนไขตั้งรหัสผ่าน ERP)

## Commit
91f92e6 — Simplify ERP password requirements

## ทำอะไรไป
- เปลี่ยนการสร้าง/แก้ผู้ใช้และหน้าเปลี่ยนรหัสให้ใช้รหัสอย่างน้อย 8 ตัว มีตัวอักษรและตัวเลข
- เอาข้อบังคับตัวพิมพ์ใหญ่และเล็กออก; ไม่ได้บังคับอักขระพิเศษอยู่แล้ว และปรับข้อความช่วยให้ระบุชัด
- ไม่ตั้งหรือส่งรหัสผ่านให้ผู้ใช้; ไม่ deploy

## ทดสอบ
- php -d memory_limit=1G vendor/bin/phpunit ผ่าน: 655 passed, 1 skipped, 6 incomplete (656 tests)
- vendor/bin/pint --test สำหรับไฟล์ PHP ที่แก้ผ่าน
- git diff --check ผ่าน

## งานถัดไป
- deploy เมื่อเจ้าของสั่ง และแจ้งผู้ใช้ให้ตั้งรหัสใหม่ที่มีตัวอักษรกับตัวเลขอย่างน้อย 8 ตัว

# Handoff — 2026-10-30 (Codex: แก้สแกนฉลากชั่งใน POS Central)

## Commit
`9b8361cbcf5c45e0dbdefe288fbcfe817c7eb490` — `Fix scale-label scanning in POS weight dialog`

## ทำอะไรไป
- หน้าต่าง “ใส่ กก.” ใช้ `/api/pos/scan` เหมือนช่องสแกนหลัก; ฉลากเครื่องชั่งใส่น้ำหนักจาก ERP ให้อัตโนมัติและล็อกค่าตามฉลาก ส่วนบาร์โค้ด/SKU สินค้าปกติเลือกสินค้าแล้วให้กรอกน้ำหนักเอง
- เมื่อ ERP ใช้งานไม่ได้ ใช้ parser และ ScaleProfile ที่ซิงก์ไว้ในเครื่อง; เปิดหน้าต่างมาไม่เลือกสินค้าตัวแรกเองแล้ว
- เพิ่ม UI regression tests สำหรับฉลากชั่งและบาร์โค้ดสินค้าปกติ

## ทดสอบไปแล้วแค่ไหน
- `git diff --check` ผ่าน
- `ast.parse` ผ่านสำหรับ dialog, main window และ regression tests; `compileall` ผ่านเมื่อกำหนด `PYTHONPYCACHEPREFIX=/private/tmp/poscentral-pycache`
- ยังรัน pytest/PySide UI tests ในเครื่องนี้ไม่ได้ เพราะไม่มี `pytest`, `PySide6` และ SQLAlchemy ติดตั้งไว้; push แล้วให้ `POS Central Windows UAT` workflow รัน tests และ build

## ความเสี่ยง/งานถัดไป
- ต้องทดสอบฉลากจริงจากเครื่องชั่งและทดสอบสแกนผ่านอุปกรณ์ POS; ยังไม่ได้สแกน/ขายเข้าฐาน Production จากรอบนี้
- `apps/pos-python` ยังเป็นแอปหลัก; การเปลี่ยนนี้ใช้กับ candidate `apps/pos-central` เท่านั้น

## Handoff - 2026-10-30 (Codex: รีเซ็ตล็อกอิน ERP+POS และ UAT ผ่าน API)
- Commit implementation: `991a497`
- ทำอะไร: รวมปุ่มผู้ดูแลให้รีเซ็ตรหัส ERP และ PIN POS ในครั้งเดียวสำหรับผู้ขาย; รหัสใหม่ใช้ได้ทันที ไม่บังคับเปลี่ยนซ้ำ, ถอนการยืนยันคนขายเดิมจากอุปกรณ์ทุกเครื่อง, แสดงข้อมูลให้คัดลอกครั้งเดียว; ผู้ใช้ที่ไม่ขาย POS รีเซ็ตเฉพาะ ERP. การเพิ่ม/แก้รหัสผ่านโดยแอดมินก็เข้าใช้ได้ทันที. อัปเดต POS Central README ตามคำสั่งเจ้าของให้ใช้ Production ERP API ในช่วง UAT และจดเลขเอกสารเพื่อเคลียร์ผ่าน ERP ก่อนวันขาย.
- ทดสอบ: `php -d memory_limit=1G vendor/bin/phpunit` ผ่าน 652 tests (651 passed, 1 skipped, 6 incomplete; 4,567 assertions); Pint, PHP lint และ `git diff --check` ผ่าน. ทดสอบ reset ครอบคลุม ERP login, PIN POS login, ถอน verified cashier และ audit. Windows workflow run `41026951380` ผ่าน POS Central tests, PyInstaller build, ZIP packaging และ upload artifact `PopCentral-POS-Central-UAT-0.1.0-dev` (`sha256:a6e123dcee3607fa4310af36e2678a66d8ed1e89d3c07a251632fc4568bb5b83`).
- ยังไม่ทดสอบ/ความเสี่ยง: `php artisan test` ติดข้อจำกัด child process 128 MB ใน `ReportExportTest` (full PHPUnit ตรงผ่าน); ยังไม่ได้ทดลองแพ็กเกจกับเครื่อง POS/เครื่องพิมพ์/เครื่องชั่งจริง และยังไม่มีรายการทดสอบส่งเข้า API production. ไม่มี migration และไม่ได้สร้าง/รีเซ็ตรหัสหรือบิล production.
- Deploy: Production preflight ผ่านแบบ read-only — health ทุกหัวข้อผ่าน, backup อายุ 4.3 ชั่วโมง, migrations current, `migrate --pretend` ไม่มีงานค้าง. ยังไม่ได้เรียก Deploy ERP production เพราะ GitHub Actions ใน Chrome ออกจากระบบและ GitHub CLI token ใช้ไม่ได้; รอให้เจ้าของลงชื่อเข้าใช้เพื่อ dispatch workflow ตาม `docs/OPERATIONS.md`.

## Handoff - 2026-10-30 (Codex: นำ POS Central มาเตรียมเป็น candidate)
- Commit: `12750a085f1ae223cf62f33fc2c66fa7e2024176`
- ทำอะไร: นำ upstream `nongpee321/posCentral` commit `000a924b` มาไว้ที่ `apps/pos-central` โดยคง `apps/pos-python` เป็นตัวหลัก; ปรับเชื่อม Laravel API ให้ไม่สร้างสินค้า/ไม่เดาสาขา แก้การยืนยัน QR/โอน การสแกนหน่วยบรรจุ และ worker ปิดสะอาด; แก้ฝั่ง ERP ให้ป้ายชั่งแบบกรัมแปลงเป็นกิโลกรัมก่อนคำนวณราคาและตรวจซ้ำก่อน checkout.
- ทดสอบ: candidate `pytest -q` ผ่าน 27 tests; `compileall` ผ่าน; POS หลักผ่าน 244 tests; `php -d memory_limit=1G vendor/bin/phpunit` ผ่าน 651 tests (650 passed, 1 skipped, 6 incomplete; 4,543 assertions); Pint และ `git diff --check` ผ่าน.
- ข้อจำกัดการตรวจ: `php -d memory_limit=1G artisan test` ยังล้มใน child process ที่จำกัด memory 128 MB ระหว่าง `ReportExportTest`; รัน PHPUnit ตรงด้วย memory 1 GB ผ่าน. `php artisan erp:health` รายงานฐานข้อมูล local มี migration ค้าง 25 รายการและยังไม่พบ backup; ไม่ได้รัน migration หรือเปลี่ยนข้อมูล.
- ความเสี่ยง/งานถัดไป: ยังไม่ใช่ตัวแทนพร้อมตัดสลับ — POS หลักมีคิว shift open, sale void, cash movement, shift close และ auth events ซึ่ง candidate ยังไม่ซิงก์ครบ; SQLite path/schema ต่างกันและยังไม่มี migration ของคิว/ตัวตนออฟไลน์; candidate ยังใช้ Float. ต้องทำ parity + migration และทดสอบ installer/เครื่อง Windows/เครื่องชั่งจริงก่อนเปลี่ยน release path.
- Deploy: ยังไม่ deploy.

# Handoff - 2026-10-28 (Codex: เจ้าของให้สคริปต์อ่าน Token เดิมเอง)
- Commit implementation: `ccb9873`; ต่อจาก smoke guards `0fff401`
- คำสั่งล่าสุดของเจ้าของเปลี่ยนเฉพาะขั้น Token: อนุญาตให้อ่าน Token ที่มีอยู่แล้วมาใช้แทนการให้เจ้าของกรอก; PIN ยังให้เจ้าของกรอกเองแบบซ่อนตัวอักษร ห้ามตั้ง/เดา PIN หรือออก/หมุน Token ใหม่
- เพิ่ม `--token-from-ssh` ใน `apps/pos-python/e2e/pos_smoke_production.py`: อ่าน Token เดิมที่เข้ารหัสผ่าน SSH alias `jeterp` จากเครื่อง `POS-B001-01` ที่ยังไม่ revoked เพียงเครื่องเดียว ตรวจ hash ตรงก่อนใช้; เก็บผล subprocess เฉพาะในหน่วยความจำ ไม่พิมพ์/ใส่ command arguments/env/file/log และไม่ส่ง stdout/stderr ของ SSH ออกเมื่อ error
- Token ที่อ่านอัตโนมัติส่งได้เฉพาะ `https://erp.popstarcenter.com`; หาก POS_SMOKE_URL ชี้ที่อื่นให้หยุดก่อนอ่าน credentials; SSH ใช้ BatchMode และการตรวจ host key ตาม config เดิม ไม่เปลี่ยนสิทธิ์หรือการเชื่อมต่อ
- ทดสอบ: Python unit tests ผ่าน **230 tests**, `--help` และ `git diff --check` ผ่าน; ตรวจ production แบบอ่านอย่างเดียวพบ device ตรง 1 เครื่องและมี Token เดิมอ่านคืนได้ จากนั้นลอง helper จริงสำเร็จ พิมพ์เฉพาะผล boolean ไม่แสดง Token; ไม่ได้แก้ backend จึงใช้ผล ERP suite ที่บันทึกใน handoff ก่อนหน้า
- Production smoke: **ยังไม่รันการขาย**, เลขบิล ERP และ server shift id **ยังไม่มีจากรอบนี้**; ทุกขั้น ping/catalog/login/open/sell/sync/payout/close ยังรอการรันเต็มจาก Terminal ของเจ้าของ
- คำสั่งใหม่สำหรับเจ้าของบน Mac เครื่องนี้ (แทนคำสั่งที่ต้องกรอก Token ด้วยมือด้านล่าง):
  ```bash
  cd '/Users/jetsadapolsan/Documents/ChatGPT/ERP POPCenter/apps/pos-python'
  set -o pipefail
  python3 -u e2e/pos_smoke_production.py --token-from-ssh | tee /private/tmp/popstar-pos-smoke-20261028.log
  ```
  จะถามเฉพาะ PIN ของ B001 (เปลี่ยนคนขายด้วย POS_SMOKE_CASHIER ได้) เครื่องมือควบคุมหน้าจอยังปฏิเสธ Terminal จึงต้องให้เจ้าของรันเองและกรอก PIN; ห้ามส่ง PIN กลับแชต
- งานถัดไป: เจ้าของรันแล้วแจ้ง จากนั้นอ่านผล log เฉพาะผลขั้น/เลขบิล/เลขกะและเติมผลจริง; ไม่ deploy หรือแก้ข้อมูล production ด้วยมือ และยังไม่ล้างบิลเอง ต้องแจ้งเจ้าของจัดการก่อน 5 พ.ย. 2569

# Handoff - 2026-10-28 (Codex: เตรียม POS production smoke — รอเจ้าของกรอก credentials)
- Commit implementation: `0fff401` (ต่อจาก `acdcd93`; สคริปต์ต้นฉบับ `1d09b21`)
- ทำอะไร: อ่านสคริปต์ `apps/pos-python/e2e/pos_smoke_production.py` และ flow API; แก้ให้ยืนยันเครื่อง `POS-B001-01`, ไม่เลือกคนขายคนแรกแทนรหัสที่ระบุ, หยุดหากต้องเปลี่ยน PIN หรือมีกะเดิมเปิดอยู่ และพิมพ์ server id ทันทีที่เปิดกะสำเร็จ เพื่อไม่ให้รอบทดสอบไปขาย/ปิดกะเดิมโดยไม่ตั้งใจ
- ทดสอบ: ก่อนแก้ Python `python3 -m unittest discover -s tests` ผ่าน 215 tests; หลังเพิ่ม regression tests ผ่าน 224 tests; `git diff --check` ผ่าน
- ERP tests: `php artisan test` หยุดที่ memory_limit 128 MB ใน ReportExportTest; รัน suite เดิมด้วย `php -d memory_limit=512M vendor/bin/phpunit` สำเร็จ (runner: tests 630, passed 629, skipped 1, incomplete 6, assertions 4,371)
- สถานะ production: **ยังไม่ได้รัน** เพราะเจ้าของต้องกรอก Device Token/PIN เอง และเครื่องมือควบคุมหน้าจอปฏิเสธการเปิดแอป Terminal จึงไม่ได้พยายามเปิด Terminal ด้วยช่องทางอื่น ไม่ได้ขอหรือรับ credentials ผ่านแชต/ไฟล์/log
- เลขบิล ERP: **ยังไม่มีจากรอบนี้**
- เลขกะ server id: **ยังไม่มีจากรอบนี้**
- ขั้น production ที่ยังรอ: ping/catalog, login PIN, เปิดกะ 1,000, ขายเงินสดหลายรายการ, ขายเงินสดรายการเดียว, ขายโอน, sync 3 บิล, นำเงินออก 20, ปิดกะ, sync เงินออก/ปิดกะ — ทุกขั้นยังไม่เริ่ม ไม่ใช่ผลทดสอบผ่าน
- ให้เจ้าของรันใน Terminal บน Mac จาก checkout นี้ (กรอก Token และ PIN เฉพาะ prompt ซ่อนตัวอักษร):
  ```bash
  cd '/Users/jetsadapolsan/Documents/ChatGPT/ERP POPCenter/apps/pos-python'
  set -o pipefail
  python3 -u e2e/pos_smoke_production.py | tee /private/tmp/popstar-pos-smoke-20261028.log
  ```
  `tee` เก็บเฉพาะ stdout ผลขั้นทดสอบ; getpass อ่านจาก terminal แบบไม่ echo และไม่พิมพ์ค่าลง stdout ห้ามใส่ Token/PIN ใน command/env หรือส่งกลับแชต หากเปลี่ยนคนขายใช้ `POS_SMOKE_CASHIER` ตามคำสั่งเจ้าของ
- งานถัดไป: เมื่อเจ้าของรันแล้ว อ่านเฉพาะผลขั้นทดสอบ/เลขบิล/เลขกะจาก log ด้านบน; หากมี ✘ ตรวจสาเหตุโดยไม่สร้างบิลซ้ำหรือแก้ production ด้วยมือ แล้วเติมผลจริงใน handoff นี้
- Deploy/ข้อมูลจริง: ไม่ deploy, ไม่แก้ฐาน production ด้วยมือ, ไม่ล้างบิลทดสอบ; หลังรันจริงต้องแจ้งเจ้าของให้จัดการชุดทดสอบก่อนเปิดขาย **5 พ.ย. 2569** ตามขอบเขตที่เจ้าของอนุมัติ
- ไม่แตะ `docs/servers.md` ซึ่งเป็นไฟล์ untracked เดิม

# Handoff - 2026-10-27 (นำประวัติและรายงาน BPlus ออกจาก ERP)
- Commit: `942befc` (implementation)
- ทำอะไร: เอาส่วนเอกสารเก่า BPlus ออกจากหน้าเอกสารย้อนหลัง ปิด URL รายละเอียดเก่า และถอดเมนู/หน้า/เส้นทางรายงาน BPlus เดิม
- ทดสอบ: `php artisan test` ผ่าน 569 tests, skipped 1, incomplete 6; `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ไม่ได้ลบข้อมูลในฐาน/ไฟล์ต้นฉบับ BPlus ตามนโยบาย read-only; ไม่ deploy production
- Deploy: ยังไม่ deploy
- งานถัดไป: deploy ตามคำสั่งเจ้าของหากต้องการให้เมนูและหน้า production หายไป

# Handoff — 2026-08-23 รอบที่ 4 (Claude) — **Deploy แล้ว**

## Handoff - 2026-10-03 (QR/Barcode คู่มือและตรวจระบบ)

- ทำอะไร: เพิ่มคู่มือใช้งาน/ติดตั้ง/ความปลอดภัย/UAT/แก้ปัญหาสำหรับ QR และ Barcode ครบทั้งสินค้า,
  POS, รับเข้า/นับสต็อก, กล้องมือถือ, keyboard-wedge และการพิมพ์ฉลาก A4 ที่ `docs/QR-BARCODE-POS-GUIDE.md`
- ทดสอบ: `npm run build` ผ่าน, PHP syntax ของไฟล์ที่แก้ผ่าน, `git diff --check` ผ่าน
- ทดสอบที่ยังทำไม่ได้: `php artisan test` และ migration integration เพราะ Composer download จาก Packagist/GitHub
  ใช้งานไม่ได้ในสภาพแวดล้อมนี้; Android/iPhone/กล้อง/เครื่องสแกน/เครื่องพิมพ์ต้องทำ UAT จริง
- Deploy: ยังไม่ deploy
- งานถัดไป: ติดตั้ง `vendor` บนเครื่องที่มี network หรือ production staging แล้วรัน full test, migrate แบบ backup ก่อน,
  จากนั้นทำ UAT ตามตารางในคู่มือ

## Handoff - 2026-10-03 (Production Deploy QR/Barcode)

- Commit ที่ deploy: `e535a05` (รวม `600ca96`, `e2ad911` และ lockfile dependency)
- ทำอะไร: สำรองฐานข้อมูล production, อัปโหลดโค้ดและ compiled assets โดยไม่ลบไฟล์ปลายทาง,
  รัน Composer production install, migration, Laravel cache และเปิดระบบกลับ
- ผล production: migration `2026_10_02_000001_create_product_scan_logs_table` ผ่านเป็น batch `[125]`
- Health: ฐานข้อมูล, migration, backup, ขาย-GL, storage และ queue ผ่านทั้งหมด
- Smoke test: `https://erp.popstarcenter.com/` ตอบ HTTP 200 และ `build/manifest.json` ตอบ HTTP 200
- Deploy: deploy แล้ว; ยังเหลือ UAT กล้อง Android/iPhone, เครื่องสแกนจริง และเครื่องพิมพ์ A4

## Handoff - 2026-10-03 (Post-deploy smoke check)

- ตรวจ `https://erp.popstarcenter.com/login`, `/pos` และ `/wh`: ทุก URL ตอบ HTTP 200 และ flow ที่ยังไม่ login
  กลับไปหน้าเข้าสู่ระบบตามปกติ
- ตรวจ production migration status: `product_scan_logs` เป็น `[125] Ran`
- ตรวจ asset manifest: `/build/manifest.json` ตอบ HTTP 200
- หมายเหตุ: browser automation timeout ก่อนเข้าหน้า authenticated จึงยังไม่อ้างผลกล้อง/เครื่องสแกนจริงผ่าน browser

## Handoff - 2026-10-02 (QR/Barcode Web POS)

- ทำอะไร: เพิ่มเครื่องสแกน html5-qrcode ในหน้า POS รองรับกล้อง, เปลี่ยนกล้อง, หยุด/ปิด, สแกนจากรูป,
  manual input และ keyboard-wedge จาก USB/Bluetooth พร้อม debounce 1 วินาที, สั่น/เสียงเมื่อพบสินค้า
  และปิดกล้องเมื่อออกจากหน้า; เพิ่ม `ProductScanLog` และ migration สำหรับ audit ที่ไม่ขวางการขาย
- เพิ่ม BarcodePolicy สำหรับ Code128, Code39 และ QR Code โดยคงข้อมูล barcode เดิมและ SKU เป็น fallback
- ปรับฉลาก A4 ให้สร้าง Code128/Code39/EAN-13 และ QR จาก barcode หรือ SKU ด้วย bwip-js พร้อมชื่อสินค้า,
  SKU, ราคา และข้อมูลหน่วยเดิม
- ทดสอบ: `git diff --check` ผ่าน, `npm run build` ผ่าน, PHP syntax ผ่าน; `php artisan test` ยังรันไม่ได้
  เพราะ checkout นี้ยังไม่มี `vendor/autoload.php` (ยังไม่ได้ติดตั้ง Composer dependencies)
- Deploy: ยังไม่ deploy; ต้องติดตั้ง dependency ตาม `package.json`, รัน migration และทดสอบกล้องจริงบน Android/iPhone
- งานถัดไป: UAT กล้อง/เครื่องสแกนจริง, ตรวจฉลากกับเครื่องพิมพ์ A4, และ deploy หลังเจ้าของอนุมัติ

## Handoff - 2026-09-23 (CRM Phase 0)

- ทำอะไร: ตรวจสถาปัตยกรรม CRM, Customer, Member, Point, POS และ LINE integration บน `main` และจัดทำ
  `docs/crm/EXISTING_SYSTEM_ANALYSIS.md`
- ผลวิเคราะห์: ระบบมี `customers` สำหรับลูกค้าธุรกิจ, `members` สำหรับสมาชิก POS, แต้มมี ledger เดิมแต่ยัง
  พึ่ง `members.points` และขาด idempotency; LINE ที่มีอยู่เป็น notification integration ยังไม่ใช่ customer linking
- ความเสี่ยงที่บันทึก: migration/model ของ `line_integrations` ไม่ตรงกันเรื่อง `target_id`, token ถูกจัดการผ่าน
  fillable/controller, point settlement ยังกัน replay ซ้ำไม่ได้
- ทดสอบ: `git diff --check`; ยังไม่รัน test suite เพราะรอบนี้เพิ่มเฉพาะเอกสารวิเคราะห์
- Deploy: ยังไม่ deploy และไม่มีการแก้ production
- งานถัดไป: ขอใช้ผลวิเคราะห์เป็นฐานออกแบบ Phase 1 โดยเริ่มจาก point ledger/idempotency และ reconciliation

## Handoff - 2026-09-23 (CRM Phase 1 foundation)

- ทำอะไร: เพิ่ม migration `2026_09_23_000001_harden_member_point_ledger.php` ให้ ledger มี `source`,
  `source_id`, `reversal_of_id`, index และ unique idempotency key ต่อสมาชิก/เหตุการณ์/ทิศทาง
- ปรับ `MemberPointService::settle()` ให้ replay เอกสารเดิมไม่สร้างรายการ earn/redeem ซ้ำ โดยยังใช้ row lock
- ปรับ `MemberController` ไม่รับ `points` จากฟอร์มอีกต่อไป เพื่อไม่ให้แก้ยอดแต้มข้าม ledger
- ปรับ `MemberPointTransaction` ให้รองรับข้อมูล source และ reversal
- ทดสอบ: `git diff --check`; `php artisan test --filter='Pos|Sales|Crm'` ผ่าน 152 tests / 683 assertions / incomplete 2
- Deploy: ยังไม่ deploy; migration ต้องตรวจข้อมูลซ้ำและ reconciliation บน production ก่อนรัน
- งานถัดไป: เพิ่ม reconciliation command/report, reversal service สำหรับ void/return และทดสอบ migration บน PostgreSQL

## Handoff - 2026-09-23 (CRM Phase 1 reconciliation)

- เพิ่มคำสั่ง `loyalty:reconcile-points` ตรวจ `members.points` เทียบผลรวม ledger แบบ read-only
- รองรับตรวจทั้งระบบหรือเฉพาะ `--member=<id>` และคืน exit code 1 เมื่อพบส่วนต่าง
- เพิ่ม feature tests กรณียอดตรง/ไม่ตรง โดยยืนยันว่า command ไม่แก้ข้อมูลเอง
- ทดสอบ: `php artisan test --filter='ReconcileMemberPoints|Pos|Sales|Crm'`
- Deploy: ยังไม่ deploy; ใช้คำสั่งนี้ตรวจ production หลัง backup และก่อน migration
- งานถัดไป: เพิ่ม reversal service ให้ void/return สร้าง ledger กลับรายการอย่าง idempotent

## Handoff - 2026-09-23 (CRM Phase 1 void reversal)

- เพิ่ม `MemberPointService::reverseDocument()` สำหรับย้อน earn/redeem ของเอกสารโดยเขียน `adjust` ledger
  และอ้าง `reversal_of_id`; ไม่ลบรายการเดิม และ retry ซ้ำได้
- เชื่อม reversal เข้ากับ `PosController::voidReceipt()` หลังเอกสารถูกยกเลิกและ reverse GL สำเร็จ
- ทดสอบ: `php artisan test --filter='Pos|Sales|ReconcileMemberPoints|Crm'` ผ่าน 154 tests / 686 assertions / incomplete 2
- Deploy: พร้อมเรียก workflow `Deploy ERP` production หลัง commit; workflow จะรัน full test, build, backup/migrate/cache/health ผ่าน `scripts/deploy.sh`
- หมายเหตุ: ใบรับคืนสินค้าหลังปิดกะยังเป็นงานต่อเนื่อง ต้องเชื่อม reversal กับ return service แยกจาก void

## Handoff - 2026-09-23 (Member registration API)

- เพิ่ม `MemberRegistrationService` สำหรับ normalize เบอร์ไทยและป้องกันสมาชิก active ใช้เบอร์ซ้ำ
- เพิ่ม POS device API สำหรับค้นหา/สร้าง/ดูสมาชิกที่ `/api/pos/members*`
- เพิ่ม `phone_normalized` พร้อม index โดยไม่ทำลายข้อมูลเบอร์เดิม
- เพิ่ม tests สำหรับเบอร์รูปแบบ `+66`, duplicate และเบอร์ไม่ถูกต้อง
- ยังไม่ deploy รอบนี้จนกว่า full test และ review flow POS registration จะผ่าน

## Handoff - 2026-09-23 (LINE points webhook)

- เพิ่ม `member_line_accounts` สำหรับความสัมพันธ์ member ↔ LINE user แบบแยกตาราง
- เพิ่ม `/api/line/webhook` ตรวจ `x-line-signature` ด้วย HMAC-SHA256 จาก raw body ก่อน parse/process
- เมื่อบัญชีถูกผูกแล้วและสมาชิกส่งข้อความที่มีคำว่า `แต้ม` ระบบตอบยอดแต้มผ่าน LINE Reply API
- เพิ่ม config ผ่าน `LINE_CHANNEL_ID`, `LINE_CHANNEL_SECRET`, `LINE_CHANNEL_ACCESS_TOKEN` ใน environment เท่านั้น
- เพิ่ม tests signature invalid/valid; test suite ที่เกี่ยวข้องผ่าน 158 tests / 692 assertions / incomplete 2
- ข้อจำกัด: ยังไม่มี LINE Login/LIFF + OTP linking จริง จึงยังไม่ควรเปิด webhook กับผู้ใช้ทั่วไปจนกว่าจะทำ flow ผูกบัญชี

## Commit

```
bf0bec5 Keep imported POS receipts out of the new sales figures
af47dbe Post stock adjustments and write-offs to the ledger
92dfcd1 Make erp:health notice sales that never reached the ledger
```

## Deploy: ขึ้น production แล้ว 2 รอบ

| รอบ | migration | ผล |
|---|---|---|
| 1 | `143`-`147` (ทะเบียนรายงาน, ใบจอง, เจ้าหนี้, สมุดเงินสด, นโยบายสิทธิ์) | batch [89] ผ่าน |
| 2 | `148`-`149` (flag บิลนำเข้าเก่า, บัญชีผลต่างสต๊อก) | batch [90]-[91] ผ่าน |

ขั้นตอนที่ใช้ทุกรอบ: `erp:backup` + ตรวจ sha256 → `rsync --dry-run --itemize-changes` ตรวจรายการ →
rsync จริง (**ไม่ใช้ `--delete`** ตามกติกา) → `scripts/deploy.sh` → `erp:health`

backup ที่ถ่ายไว้: `erp-db-20260823-062945.sql.gz` (ก่อนรอบ 1) และ `erp-db-20260823-063836.sql.gz` (ก่อนรอบ 2)

**ตรวจก่อน deploy ตามแผน**: `cash_books` = 0 แถวจริง migration จึงสร้างตารางใหม่ได้ปลอดภัย

## ผลที่ได้

### ยอด sales_postings เทียบ GL: 4.58 ล้าน → 2,383 บาท

| ก่อน | หลัง |
|---|---|
| sales_postings 4,599,908.50 · GL 23,479.77 · **ต่าง ~4.58 ล้าน** | sales_postings 22,183.50 · GL รายได้ 18,505.15 + ภาษีขาย 1,295.35 · **ต่าง 2,383.00** |

บิลนำเข้าเก่า 16,537 ใบถูก flag และกันออกจากยอด — **ข้อมูลยังอยู่ครบ 16,557 แถว ไม่ได้ลบ**
rollback migration `148` แล้วยอดกลับมาเหมือนเดิมทันที

### ปรับสต๊อก/ตัดชำรุด ลง GL แล้ว

เพิ่มบัญชี `5030 ผลต่างจากการปรับปรุงสินค้าคงเหลือ` แล้วต่อเข้า
`StockAdjustmentService::approve` และ `StockIssueService::approveDamage`
ของเกิน Dr สินค้าคงเหลือ ของขาด Cr — **มูลค่าคิดจาก Lot ที่ FIFO ตัดจริง** ไม่ใช่ต้นทุนเฉลี่ยปัจจุบัน

ยังไม่ลง GL: แปรรูป, รับผลิต, ค่าเสื่อมราคา (ค่าเสื่อมต้องมีบัญชีค่าเสื่อมสะสมก่อน ผังบัญชียังไม่มี)

## ผลต่าง 2,383 บาทที่เหลือ — หา root cause เจอแล้ว ยังไม่แก้ข้อมูล

**สาเหตุ**: เอกสารขาย 5 ใบแรก (`CS000720260706001` ถึง `CS000720260712001`, 6-12 ก.ค. 2026)
**ไม่มีรายการ GL เลย** เพราะขายก่อนที่ระบบจะต่อ GL — `gl_journals` แถวแรกคือ document 6
สร้าง 12 ก.ค. 09:52 ส่วน 5 ใบนั้นสร้างก่อนหน้านั้นทั้งหมด

| doc | เลขที่ | วันที่ | ยอด | cost_amount |
|---|---|---|---|---|
| 1 | CS000720260706001 | 2026-07-06 | 102.00 | 0 |
| 2 | CS000720260706002 | 2026-07-06 | 464.00 | 0 |
| 3 | CS000720260707001 | 2026-07-07 | 1,038.00 | 0 |
| 4 | CS000720260709001 | 2026-07-09 | 20.00 | 0 |
| 5 | CS000720260712001 | 2026-07-12 | 759.00 | 0 |

**ทำไมผมไม่แก้ให้เอง**

`GlPostingService::postCashSale()` เรียกซ้ำได้ (ลบของเดิมก่อนลงใหม่) และงวด ก.ค. ยัง `open`
ทางเทคนิคจึงกด repost ได้ทันที **แต่ทั้ง 5 ใบมี `cost_amount = 0`**
แปลว่าต้นทุนขายของบิลชุดนั้นไม่เคยถูกบันทึก และสร้างย้อนหลังไม่ได้เพราะไม่รู้ว่าตัด Lot ไหนไป

ถ้า repost ตรง ๆ จะได้เฉพาะขา เงินสด/รายได้/ภาษีขาย ส่วนต้นทุนขายกับสินค้าคงเหลือจะยังขาด
= แก้ตัวเลขหนึ่งให้ตรงแล้วทำอีกตัวเพี้ยนแทน ถ้าจะเดาต้นทุนจากราคาเฉลี่ยปัจจุบันก็คือ
**เขียนตัวเลขที่เดาเอาลงสมุดบัญชีจริง** ซึ่งเป็นการตัดสินใจของนักบัญชี ไม่ใช่ของผม

คำสั่งที่พร้อมรันเมื่อได้ข้อสรุปแล้ว (ทำใน tinker บน production):

```php
foreach (App\Models\Document::whereIn('id', [1,2,3,4,5])->get() as $d) {
    app(App\Services\Accounting\GlPostingService::class)->postCashSale($d);
}
```

## กันไม่ให้เกิดซ้ำ

`erp:health` ตรวจเพิ่มแล้วว่า **เอกสารขายที่ยืนยันแล้วต้องมีรายการ GL เสมอ**
ตอนนี้บน production ขึ้น `ไม่ผ่าน — เอกสารขาย 5 ใบไม่มีรายการ GL รวม 2,383.00 บาท`
ซึ่งจะเขียวเองทันทีที่แก้ข้อมูลเสร็จ

เดือน ก.ค. ไม่มีอะไรจับเรื่องนี้ได้เลย กว่าจะรู้ก็ตอนกระทบยอดสองเดือนถัดมา

## ทดสอบไปแล้วแค่ไหน

`php artisan test` → **178 passed / 2,110 assertions / incomplete 6** (เดิม 171)

เพิ่ม: `InventoryLedgerPostingTest` 4 เคส (ของขาด/ของเกิน/ตัดชำรุด/นับตรงไม่สร้างเอกสาร),
`ErpHealthSalesLedgerTest` 2 เคส, และเคสบิลนำเข้าเก่าใน `SalesReportChannelOverlapTest`

ระหว่างทางเจอว่า `StockAdjustmentService` ปฏิเสธการสร้างเอกสารตั้งแต่ต้นถ้านับตรงกับระบบ
(ดีกว่าที่ผมเดาไว้ เลยเขียนเทสต์ยืนยันพฤติกรรมนั้นแทน)

## ยังไม่ทดสอบ / ความเสี่ยง

- **ยังไม่ได้ทดสอบ migration `148`-`149` บน PostgreSQL แยกก่อน deploy** — รอบนี้ deploy ตรง
  (รันผ่านจริงบน production แล้วและ verify ผลถูกต้อง แต่ไม่ได้ dry-run บนฐานทดสอบเหมือนรอบก่อน)
- ปรับสต๊อก/ตัดชำรุดที่ลง GL ใหม่ **ยังไม่มีใครใช้จริงบน production** (`documents` มีใบปรับสต๊อก 1 ใบ
  ที่สร้างก่อนหน้านี้ ไม่ได้ลง GL ย้อนหลังให้)
- ผู้ใช้ MARKETING ยังเห็นรายงานว่างเปล่าตามนโยบายที่ตกลง ยังไม่ได้กำหนดสาขาให้
- รายงาน P1/P2 29 ตัวยังปิดอยู่ รวม VAT ซื้อ/ขาย — ถ้าบัญชีต้องใช้ปิดเดือนต้องเปิดกลับ

## งานถัดไป

1. **นักบัญชีตัดสินเรื่องต้นทุนของ 5 ใบนั้น** แล้วผมรัน repost ให้ — `erp:health` จะเขียวเอง
2. กำหนดสาขาให้ MARKETING หรือให้สิทธิ์ `reports.all_branches`
3. เปิดรายงาน VAT กลับถ้าบัญชีต้องใช้ปิดเดือน
4. เตรียม parallel run: เปิดบัญชีธนาคาร + ยอดยกมาต้นงวด + เลือกสาขา
5. เครื่อง SQL Server สำหรับวิเคราะห์ BPlus (ยังติดอยู่)

## Handoff - 2026-08-26 (Codex)
- ทำอะไร: แก้ Odoo navigation ให้กดหมวดบนแถบบนแล้วเปิด sidebar กลุ่มตรงกัน และเชื่อมกับตัวกรองหมวด Vue บนหน้า App Launcher
- ทดสอบ: `npm run build`, `php artisan test` (380 tests, 379 passed, 1 skipped, 6 incomplete), `php artisan view:cache`, `git diff --check`
- Deploy: deploy ขึ้น production แล้วหลัง `erp:backup`; rsync แบบ dry-run และจริงโดยไม่ใช้ `--delete`; production `erp:health` ผ่านครบ; manifest และ Launcher JS ตอบ HTTP 200
- หมายเหตุ: deploy เฉพาะ `layout.blade.php`, `AppLauncher.vue`, `manifest.json` และ asset App Launcher ไม่รวมงาน POS/คลังมือถือที่ยังไม่ commit

## Handoff - 2026-08-26 (Codex runtime fix)
- เปลี่ยนจากการจำลอง `.click()` ของปุ่ม Vue เป็น custom event `erp:select-section` ให้ AppLauncher รับคำสั่งโดยตรง; รอบนั้นยังไม่ได้พิสูจน์ root cause ด้วย Browser จึงไม่ควรสรุปว่า `.click()` เป็นเหตุหน้าว่าง
- ทดสอบ: `npm run build`, `php artisan test` (380 tests, 379 passed, 1 skipped, 6 incomplete), `php artisan view:cache`, `git diff --check`
- Deploy: backup `erp-db-20260826-212725.sql.gz`, rsync แบบ dry-run/จริงโดยไม่ใช้ `--delete`, ล้าง cache และ `erp:health` production ผ่านครบ; ตรวจ asset/manifest HTTP 200 และตรวจ source/asset บน Host แล้ว

## Handoff - 2026-10-20 (Codex — Backup memory fix)
- Commit: `a35bd59`
- ทำอะไร: แก้ `erp:backup` จากการอ่าน dump ทั้งไฟล์ด้วย `File::get()` เป็นการบีบอัดแบบ streaming ทีละ 1 MB และส่งสำเนา offsite ผ่าน stream เพื่อลดการใช้ RAM; เพิ่ม `ErpBackupTest`
- ทดสอบ: `php artisan test` ผ่าน 480 tests / 3,615 assertions (ผ่าน 479, skipped 1, incomplete 6); `php -l` และ `git diff --check` ผ่าน
- Production ที่ตรวจ: migration ครบถึง `2026_10_16_000002`, ดิสก์เหลือประมาณ 9.2 GB; 500 เกิดจาก PHP memory limit 128 MB ระหว่าง `erp:backup` และมีไฟล์ `.sql` ดิบค้าง 3 ไฟล์
- Deploy: ยังไม่ deploy; push ขึ้น `origin/main` แล้ว รอเจ้าของสั่ง Deploy ผ่าน GitHub Actions
- ความเสี่ยง: Production มี local modifications/untracked files จำนวนมากและ branch ต่างจาก origin จึงห้าม `git pull`, `reset` หรือ cleanup ทับโดยไม่ได้ทบทวน
- งานถัดไป: รัน workflow `Deploy ERP` environment `production`, ตรวจ backup ใหม่, `php artisan erp:health`, และค่อยพิจารณาลบไฟล์ `.sql` ดิบค้างหลังยืนยันว่าไม่ต้องเก็บ

## Handoff - 2026-10-22 (Codex — System overview for developer)
- Commit: `2c9399a`
- ทำอะไร: เพิ่ม `docs/POPSTAR_SYSTEM_HANDOFF.md` สรุป architecture, technology stack, ERP/POS/warehouse modules, API contract, normal/scale product flow, offline sync, database ownership, security rules และ developer checklist สำหรับส่งต่อให้นักพัฒนา
- ทดสอบ: `git diff --check` ผ่าน และตรวจเอกสารไม่พบ credential หรือ token จริง
- Deploy: ไม่เกี่ยวกับ runtime และยังไม่ deploy
- งานถัดไป: ให้นักพัฒนาอ่านเอกสารนี้ร่วมกับ `docs/POS_DEVELOPER_HANDOFF.md` ก่อนแก้ POS/API

## Handoff - 2026-08-26 (Codex verified navigation and warehouse)
- Commit โค้ด: `7db1cbf` บนฐาน `7b143e9` ของ Claude
- Launcher: เมนูบนส่งหมวดให้ Vue โดยตรง, การเลือกหมวดด้านในส่งสถานะกลับเมนูบน, "ทั้งหมด" ล้างสถานะหมวดบน, ตัวเลขนับเฉพาะการ์ดที่แสดง และเก็บการเลือกหมวดระหว่างรอโหลด module
- คลังมือถือ: ยืนยันว่า Blade render `tab: &#039;receive&#039;` ทำให้ JavaScript parse ไม่ผ่านและทุก panel ถูก x-cloak ซ่อน; เปลี่ยนเป็น `@js`, เพิ่ม regression tests ทั้งผู้รับสินค้าและผู้เช็คสต๊อกอย่างเดียว
- Stock endpoint: กรองตามสาขาที่เลือก และบังคับสาขาของผู้ใช้ที่ถูกผูกสาขาเสมอ; เพิ่ม tests ป้องกันขอดูข้ามสาขา
- ทดสอบ: `php artisan test --compact` ไม่มี failure (runner รายงาน 384 tests / 383 passed / 1 skipped / 6 incomplete / 2914 assertions); `npm run build`, `php artisan view:cache`, `git diff --check` ผ่าน
- Browser: ใช้ HTML ที่ Laravel render ด้วยบัญชี fixture ใน SQLite in-memory พร้อม asset build จริง บน local server; ทดสอบ Launcher เลือกหมวดทั้งสองทาง/ทั้งหมด/ค้นหาข้ามหมวด, sidebar ปกติและ Escape ล้างคำค้น, คลังมือถือสลับรับเข้า/รับตาม PO/เช็คสต๊อก; ตรวจ screenshot ที่ 1366x900 และ 390x844 ไม่พบหน้าว่างหรือ overflow แนวนอน; ไม่พบ JS error ใน flow เหล่านี้
- ขอบเขต Browser: PO endpoint เป็น fixture คืนรายการว่าง ไม่ใช่การรับสินค้าจริง; ยังไม่ทดสอบเขียนเอกสาร, กล้องมือถือจริง หรือ Windows POS จริง; production Browser ยังติดหน้า login จึงไม่ได้ยืนยันการคลิกบน production
- Assets: ตรวจ manifest 13 entries / 15 files รวม shared chunks ครบใน local build; รอบ deploy ต้องส่ง asset ทั้งชุดก่อนเปลี่ยน manifest ไม่ใช่เฉพาะ launcher JS/CSS; HTTP 200 ของ manifest อย่างเดียวไม่พอ
- Deploy: **รอบแก้ล่าสุดนี้ยังไม่ deploy**; production มีเพียง hotfix รอบก่อนข้างบน ห้ามถือว่า source บน main กับ host ตรงกันแล้ว
- POS: ไม่ได้เรียก `pos:web-mode` หรือเปลี่ยนค่า production; โค้ดปัจจุบันเป็น **global AppSetting** ไม่มี `--branch` จึงยัง cutover รายสาขาไม่ได้ ต้องเพิ่ม branch-scoped flag ก่อนหากต้องการ rollout ทีละสาขา; รอทดสอบ 0.4.0 บน Windows จริงก่อนตัด Web POS
- Deployment warning: `scripts/deploy-ssh.sh` ปัจจุบันยังมี `--delete` ซึ่งขัดกติกา WORKFLOW/PROJECT_MEMORY; ห้ามรันตามเดิม ให้ทำ backup + explicit dry-run/rsync ที่ไม่มี `--delete` ตาม OPERATIONS
- ไฟล์ `package-lock.json` ที่ไม่ tracked ไม่รวมใน commit; ไม่แตะงาน Python POS, DB migration, tokens หรือ PIN

## Handoff - 2026-08-26 (Codex production deploy)
- Commit deployed: `f4ca475`
- Deploy: ขึ้น production `/var/www/jeterp` แล้วด้วย rsync แบบ explicit และ **ไม่ใช้ `--delete`**; exclude `.env`, `storage`, `vendor`, `node_modules`, `.claude`, `.codex`, `package-lock.json` และไฟล์ runtime
- Backup: รันบน host ก่อน deploy สำเร็จที่ `storage/app/backups/erp-db-20260826-223203.sql.gz` พร้อม checksum ตาม `erp:backup`
- Build/assets: รัน `npm run build` ในเครื่อง dev ก่อน deploy; ส่ง `public/build` ทั้งชุดขึ้น host; ตรวจ production manifest ผ่าน `manifest ok 13` และ `/build/manifest.json` ตอบ HTTP 200
- Production commands: `php artisan optimize:clear`, `config:clear`, `route:clear`, `view:clear`, `chown -R www-data:www-data storage bootstrap/cache`, `php artisan migrate --force`
- Health: production `php artisan erp:health` ผ่านครบหลัง deploy: database, migrations, backup, sales-GL, storage, queue
- Routes checked: `/apps`, `/wh/*`, `/pos` และ POS API routes อยู่ครบบน production; `pos_web_mode` ยังเป็น `sell` จึงยังไม่ได้ตัด Web POS ไป redirect
- Browser: In-app Browser production redirect ไปหน้า login และ Chrome connector unavailable จึงยังไม่ได้คลิกยืนยัน `/apps` และ `/wh` ด้วย session จริงบน production; ตรวจได้เฉพาะ HTTP/route/asset/server health
- Local verification before deploy: `php artisan test --compact` ไม่มี failure (384 tests / 383 passed / 1 skipped / 6 incomplete / 2914 assertions), `npm run build` ผ่าน, manifest local ครบ 13 entries

## Handoff - 2026-08-26 (Codex POS menu split)
- Commit: `3ad2144`
- ทำอะไร: แยกหมวดเมนูใหม่ `POS / หน้าร้าน` ออกจาก `ขาย / เอกสาร`; ย้าย `เปิด POS ขาย`, `ศูนย์ควบคุม POS`, `เครื่องมือ POS`, `ส่งข้อมูลไป POS`, `QR รับเงิน / จอแสดงราคา` ไปหมวดใหม่
- รายละเอียด: `ขาย / เอกสาร` เหลือเอกสารขาย/CRM; `SystemSettingController::defaultMenuOrder()` รู้จักหมวดใหม่; `ErpMenu::forUser()` แทรกหมวดใหม่หลัง `งานประจำวัน` แม้ production จะมี `menu_section_order` เก่าที่ยังไม่มี label นี้
- ทดสอบ: `php artisan test tests/Feature/AppLauncherTest.php tests/Feature/ErpMenuIconTest.php` ผ่าน 10 tests / 48 assertions; `php artisan test --compact` ไม่มี failure (386 tests / 385 passed / 1 skipped / 6 incomplete / 2927 assertions); `npm run build` ผ่าน; `git diff --check` ผ่าน
- Deploy: deploy ขึ้น production แล้ว; backup `erp-db-20260826-224714.sql.gz`; rsync dry-run/จริงแบบไม่ใช้ `--delete`; `php artisan migrate --force` ไม่มี migration ค้าง; production `erp:health` ผ่านครบ; ตรวจ production menu order ได้ `ภาพรวม | งานประจำวัน | POS / หน้าร้าน | ...`; manifest production ผ่าน `manifest ok 13`
- หมายเหตุ: ไม่เปลี่ยนสิทธิ์ route เดิม; `QR รับเงิน / จอแสดงราคา` ยังใช้ `settings.manage` ตามเดิมเพราะเป็นงานตั้งค่าอุปกรณ์/PromptPay

## Handoff - 2026-08-26 (Codex Python POS workbench copy)
- Commit: `f81f828`
- ทำอะไร: แก้หน้า `เครื่องมือ POS` ไม่ให้โปรโมต Vue/Tauri รุ่น 0.1.7 แล้ว; ปุ่มหลักและปุ่มอัปเดตเปลี่ยนไป `python-pos.download`; copy ระบุ `PopCentral Python POS`, `Python + PySide6`, `Local SQLite`, และ sync เข้า PopCentral
- ทดสอบ: `php artisan test tests/Feature/PosWorkbenchTest.php` ผ่าน 1 test / 8 assertions; `php artisan test --compact` ไม่มี failure (387 tests / 386 passed / 1 skipped / 6 incomplete / 2934 assertions); `npm run build` ผ่าน; `git diff --check` ผ่าน
- Deploy: deploy ขึ้น production แล้ว; backup `erp-db-20260826-225337.sql.gz`; rsync dry-run/จริงแบบไม่ใช้ `--delete`; ล้าง Laravel cache; production `erp:health` ผ่านครบ; ตรวจ source บน host แล้วมี `Python + PySide6` และ `python-pos.download` โดยไม่มี `Vue + Tauri` หรือ `0.1.7`
- หมายเหตุ: ยังไม่เปลี่ยน `Web POS` route หรือ flag `pos_web_mode`; รอบนี้แก้เฉพาะหน้าเครื่องมือ POS ที่แสดงข้อความผิด

## Handoff - 2026-08-27 (Codex Python POS direction cleanup)
- Commit: `5c09e05`
- ทำอะไร: ให้ Python/PySide6 เป็น POS หลักในข้อความและเอกสารกลาง; เปลี่ยน `/download/pos` เดิมให้ redirect ไป `/download/python-pos`; ปรับหน้า Settings และคำอธิบายแอปไม่ให้ชี้ว่า Vue/Tauri เป็นช่องทางใช้งาน
- ทดสอบ: focused POS tests ผ่าน 7 tests / 25 assertions; `php artisan view:cache`; `git diff --check`
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ทดสอบ installer บน Windows จริง และยังไม่ได้เปิด `pos:web-mode redirect`; การเปิดขายจริงต้องผ่าน Windows/hardware UAT ก่อน
- Deploy: ยังไม่ deployรอบนี้ เพราะต้อง commit/push และตรวจการเชื่อมต่อ GitHub ก่อน
- งานถัดไป: push แล้ว deploy source + clear cache + `erp:health`; จากนั้นทดสอบดาวน์โหลดบน host และติดตั้ง Python POS บน Windows จริง

## Handoff - 2026-08-27 (Codex POS web visual parity)
- Commit: pending
- ทำอะไร: ปรับ `/pos` ให้เรียงและใช้โทนตาม Python/PySide6 build: บิล/ตะกร้าซ้าย, สินค้าขวา, แถบค้นหาและหมวดอยู่ด้านบน, แผงสีขาวบนพื้นเทาอ่อน, header และปุ่มใช้ชุดสีเดียวกับ build; มือถือยังเรียงสินค้าไว้ด้านบนเพื่อให้เลือกสินค้าได้สะดวก
- ทดสอบ: ยังรันทดสอบหลัง patch ไม่เสร็จ
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ตรวจ screenshot บน production เพราะ source รอบนี้ยังไม่ push/deploy
- Deploy: ยังไม่ deploy
- งานถัดไป: รันทดสอบ, push และ deploy เมื่อ GitHub เชื่อมต่อได้ แล้วตรวจ `/pos` ด้วย browser

## Handoff - 2026-08-27 (Codex enterprise login skin)
- Commit: pending
- ทำอะไร: ปรับ `resources/views/auth/login.blade.php` เป็น enterprise login แบบ SAP-inspired: พื้นหลัง neutral, split panel คม, สีหลักเดียว, ฟอร์มด้านขวา, ข้อความ UI ภาษาอังกฤษแบบสากล และ responsive สำหรับมือถือ โดยไม่แตะ authentication flow
- ทดสอบ: `php artisan test tests/Feature/ExampleTest.php tests/Feature/DashboardLayoutTest.php --compact` ผ่าน 12 tests / 41 assertions; `php artisan view:cache`; `git diff --check`
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ screenshot บน production เพราะ commit นี้ยังไม่ push/deploy
- Deploy: ยังไม่ deploy
- งานถัดไป: push และ deploy แล้วตรวจ `/login` บน host ที่ความกว้าง desktop/mobile

## Handoff - 2026-08-28 (Codex POS permission access)
- Commit: `5a7e362`
- ทำอะไร: ให้สิทธิ์ `pos.sell` ซึ่งเป็นสิทธิ์ขาย POS ที่สูงกว่า รวมสิทธิ์เปิดหน้า `pos.use` อัตโนมัติ แก้กรณีแคชเชียร์มีสิทธิ์ขายแต่เมนู POS ถูกซ่อนหรือเข้า `/pos` แล้วได้ 403; ผู้ใช้ที่มี `pos.use` อย่างเดียวยังดูได้แต่เปิดกะ/คิดเงินไม่ได้
- ทดสอบ: `php artisan test tests/Feature/PosWebModeTest.php tests/Feature/PosDeviceConnectionTest.php tests/Feature/UserManagementTest.php --compact` ผ่าน 14 tests / 63 assertions; `php artisan view:cache`; `git diff --check`
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ deploy; ต้องตรวจผู้ใช้จริงที่ติด `must_change_password` ให้เปลี่ยนรหัสชั่วคราวก่อนเข้าเมนูตามนโยบายความปลอดภัย
- Deploy: ยังไม่ deploy
- งานถัดไป: push แล้ว deploy พร้อมล้าง cache และตรวจ `/pos` ด้วยบัญชีแคชเชียร์จริง

## Handoff - 2026-08-28 (Codex POS sync production test)
- Commit: `8d6d9cb` (source ที่ push แล้ว; deploy ส่งไฟล์โดยไม่ใช้ `--delete`)
- ทำอะไร: นำ source ล่าสุดของ POS Python, Laravel POS API และ permission fix ขึ้น production เพื่อเตรียมทดสอบ sync จริง; ไม่เปลี่ยน `pos_web_mode` และไม่แตะข้อมูลขายเดิม
- ทดสอบ: Python POS unittest ผ่าน 128 tests; production backup สำเร็จที่ `storage/app/backups/erp-db-20260828-220108.sql.gz`; `php artisan migrate --force` ไม่มี migration ค้าง; `php artisan erp:health` ผ่าน database, migration, backup, sales-GL, storage และ queue
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ยิง checkout จริงจากเครื่อง POS เพราะต้องใช้ device token/PIN/terminal ที่ผูกสาขาจริง; production ยังเป็น HTTP จึงต้องตั้ง `allow_insecure=true` ในไฟล์ pair ของเครื่องตามที่ระบบบังคับให้ผู้ใช้ยืนยันเอง
- Deploy: deploy แล้ว พร้อมล้าง Laravel cache และตรวจ health ผ่าน
- งานถัดไป: บน Windows เปิดแอป → pair เครื่อง → ping → sync catalog/cashier → login PIN → เปิดกะ → ขาย 1 บิล → ตรวจเลข receipt และรายการบน `/bplus/pos-workbench`

## Handoff - 2026-08-28 (Codex web POS designer)
- ทำอะไร: เพิ่มหน้า `settings/pos-designer` แบบลากวาง, component whitelist, บันทึก draft และ Build & Publish พร้อม version; เพิ่ม `pos_layout` ใน POS ping เพื่อให้ Python POS แคช layout ใช้งานออฟไลน์ได้
- ทดสอบ: Blade cache, PHP syntax, route list และ Python provisioning 10 tests ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: การจัดวางยังเป็น schema สำหรับ renderer; ต้องทดสอบการแสดงผลบนเครื่อง Windows จริงก่อนเปิดใช้เป็น layout หลัก

## Handoff - 2026-08-28 (Codex profit, CRM and replenishment insights)
- Commit: `4255ca9`
- ทำอะไร: เพิ่ม Profit Intelligence เตือนสินค้าที่ margin ต่ำกว่า 10% ย้อนหลัง 30 วัน; CRM สรุป Pipeline ที่เปิดอยู่และงานติดตามเกินกำหนด; Replenishment แสดงวันคงเหลือและระดับเร่งด่วนพร้อมจำนวนที่ต้องสั่งด่วน
- ทดสอบ: Executive 4 tests / 22 assertions และ CRM + Replenishment 7 tests / 39 assertions ผ่าน; `view:cache`; `git diff --check`; production `erp:health` และ `erp:readiness` ผ่านครบ
- ยังไม่ทดสอบ/ความเสี่ยง: ตัวเลขกำไรขึ้นกับ cost_amount ที่ลงในเอกสารขาย; คำแนะนำเติมเต็มยังควรเทียบกับผู้จัดซื้อและฤดูกาลจริงก่อนอนุมัติ; CRM ยังไม่แทนการยืนยันเครดิตโดยฝ่ายบัญชี
- Deploy: deploy เฉพาะ 6 ไฟล์ที่แก้ขึ้น `/var/www/jeterp` หลัง backup `erp-db-20260828-222527.sql.gz`; clear cache สำเร็จ
- งานถัดไป: ทดลองเปิดหน้า Executive/CRM/Purchase Planning กับสิทธิ์ผู้ใช้จริง และทำ UAT 1 รอบโดยใช้ข้อมูลสาขาจริง

## Handoff - 2026-08-28 (Codex production-host isolated POS UAT)
- Commit: `79de91a`
- ทำอะไร: เพิ่มคำสั่ง `pos:uat-seed` ที่บังคับ `APP_ENV=staging/local/testing` และฐานข้อมูลลงท้าย `_uat`; ใช้เตรียม UAT database แยกบน production host โดยไม่แตะฐาน `jeterp`
- ทดสอบ: สร้าง `jeterp_uat` บน host, migrate 165 migrations, seed 50 สินค้า/50 lots/20 ลูกค้า, pair device, sync catalog 50 รายการและ cashier 19 ราย, login PIN, เปิดกะ, ยิงขายจาก Python ผ่าน Laravel server จริง ได้ receipt `CSUAT20260828001`; `uat:reconcile` ผ่าน 7/7 (GL, เลขซ้ำ, posting, COGS, revenue+VAT, stock)
- ยังไม่ทดสอบ/ความเสี่ยง: void ถูกปฏิเสธ 401 เพราะ fixture ไม่มีสิทธิ์ `pos.void` ซึ่งเป็น policy ที่ถูกต้อง; ยังไม่ได้ทดสอบเครื่องพิมพ์/ลิ้นชักเงินจริง
- Deploy: UAT server หยุดแล้วและ drop ฐาน `jeterp_uat` หลังทดสอบ; ฐาน production `jeterp` ไม่ถูกแก้โดยชุดทดสอบนี้
- งานถัดไป: ทดสอบ void ด้วยบัญชีที่ได้รับ `pos.void` บนฐาน UAT ใหม่เมื่อจำเป็น และทำ Windows hardware UAT

## Handoff - 2026-08-28 (Codex production readiness gate)
- Commit: `6bc534b`
- ทำอะไร: เพิ่มคำสั่งอ่านอย่างเดียว `php artisan erp:readiness` ตรวจฐานข้อมูล, migration, backup+checksum, เอกสารขายลง GL, POS device binding, idempotency ที่ค้าง และคำเตือน queue/E-Tax ก่อนเปิดใช้งานจริง
- ทดสอบ: focused Laravel 15 tests / 67 assertions ผ่าน; Python POS 128 tests ผ่าน; full Laravel 395 tests, 393 passed, 1 skipped, 6 incomplete, 1 unrelated quarantine failure; production `erp:health` และ `erp:readiness` ผ่านครบ
- ยังไม่ทดสอบ/ความเสี่ยง: คำสั่งนี้ตรวจโครงสร้างและความสมบูรณ์ ไม่แทนการขายจริงบน Windows, การตรวจโดยนักบัญชี หรือการตอบรับ E-Tax provider
- Deploy: deploy แล้วที่ `/var/www/jeterp`; backup ก่อน deploy สำเร็จที่ `storage/app/backups/erp-db-20260828-220843.sql.gz`; clear cache และ migrate สำเร็จ
- งานถัดไป: บน Windows เปิดแอป → pair เครื่อง → ping → sync catalog/cashier → login PIN → เปิดกะ → ขาย 1 บิล → ตรวจเลข receipt และรายการบน `/bplus/pos-workbench`

## Handoff - 2026-08-31 (Claude ต่อจาก Codex — User เป็นตัวตน POS/ERP)

- Commit: pending (ยังไม่ commit/push ตามคำสั่ง — รอทดสอบและรับอนุญาตก่อน)
- สถานะที่รับต่อ: Codex แก้ค้างอยู่ตอนย้าย identity POS จาก `salesmen` ไปเป็น `User` (ใช้ครบโควตาเมื่อ 2026-08-31)
  ไฟล์ที่แก้ไว้แล้วตอนรับงาน: migration `2026_08_31_000157_make_users_the_pos_identity.php`,
  `UserPosCredential.php` (ใหม่), `User.php`, `PosApiController.php`, `UserController.php`,
  `BookingController.php`, `BookingService.php`, `users/index.blade.php`, `bookings/index.blade.php`,
  `entry-modal.blade.php`, `entry-assets.blade.php`

### ตรวจแล้วและแก้เพิ่ม
1. **`resources/views/users/index.blade.php`** มีโค้ดตายที่ยังอ้าง `$posCashier` ที่ไม่มีตัวแปรแล้ว
   (ซ่อนอยู่ใต้ `@if(false)` เก่า) — ลบบล็อกนั้นออกแล้ว ไม่กระทบ runtime (เพราะเป็น false branch)
   แต่เป็นโค้ดค้างที่ไม่ควรทิ้งไว้ตามที่สั่ง
2. **`BookingController::index()`** รายการผู้รับผิดชอบใบจอง (`$salesUsers`) เดิมกรองเฉพาะคนที่มีสิทธิ์
   `sales.manage/sales.assign` แบบ role ระดับบริษัทเท่านั้น ไม่รวมคนที่ได้สิทธิ์ผ่าน `branchRoles`
   (สิทธิ์รายสาขา) และไม่รับประกันว่าจะมี current user อยู่ในลิสต์เสมอ (ตรงกับความเสี่ยงที่ระบุไว้) —
   แก้ให้รวมทั้งสอง scope และ `orWhere('id', $currentUser->id)` เสมอ
3. **`app/Console/Commands/SetPosPin.php`** (คำสั่ง `pos:pin` ที่เจ้าของโปรเจกต์รันเองเพื่อออก PIN) —
   พบว่าเขียน PIN ลงเฉพาะ `salesmen.pos_pin_hash` เท่านั้น แต่การล็อกอินตอนนี้อ่าน
   `user_pos_credentials` ก่อนเสมอ (`PosApiController::pinHash()`) ถ้า user มี credential แถวนี้อยู่แล้ว
   คำสั่งนี้จะรันสำเร็จแต่ PIN ที่ตั้งใหม่ใช้ล็อกอินจริงไม่ได้ — แก้ให้ dual-write เข้า
   `user_pos_credentials` ด้วยเมื่อ salesman ผูก user_id พร้อมเช็คชนกับ credential เดิมด้วย
4. **`tests/Feature/UserManagementTest.php`** เทสต์ reset PIN เดิมตรวจแค่ตาราง `salesmen` และ
   audit log ที่ `table_name = salesmen` — เพิ่มให้ตรวจ `user_pos_credentials` (pin_hash,
   force_pin_change, credential_version) และแก้ audit assertion เป็น `table_name = users`,
   `record_id = $user->id` ให้ตรงกับโค้ดใหม่
5. **`tests/Feature/BookingSalesAreaTest.php`** มี 2 เคสที่ยังคาดหวังพฤติกรรมเดิม (ตามที่สั่งให้แก้):
   - เคสที่เคย assert ว่าลูกค้าไม่มีเจ้าของจะถูก "claim" เป็นของผู้สร้างใบจอง — พฤติกรรมนี้ถูกถอดออกแล้ว
     ตามกติกาใหม่ (ไม่ผูก user กับลูกค้าทีละราย) เปลี่ยนเป็นยืนยันว่าลูกค้า**ไม่ถูก claim**
   - เคสที่เคย reject การจองเพราะ "ลูกค้ามีเจ้าของเป็นคนอื่น" — เปลี่ยนเป็นยืนยันว่าตอนนี้จองได้ปกติ
     (เพราะไม่เช็คเจ้าของลูกค้าอีกต่อไป) และเพิ่มเทสต์ใหม่แทนที่คุมกติกาจริงคือ
     "มอบใบจองให้คนอื่นโดยไม่มีสิทธิ์ sales.assign ต้องถูกปฏิเสธ"

### ยังไม่ได้ทำ / ยังไม่ตรวจ (สำคัญ)
- **ยังไม่ได้รัน `php artisan test`, `npm run build`, หรือเทสต์ Python POS เลย** — เครื่องมือที่ผมมีในรอบนี้
  เป็น sandbox แยกที่ไม่มี PHP/Composer/Node ติดตั้ง และไม่มีสิทธิ์ apt/root เพื่อติดตั้งเอง
  (Codex เดิมรันบนเครื่อง Mac จริงโดยตรงจึงมีเครื่องมือครบ) **ต้องรันชุดทดสอบก่อน commit/push จริง**
  ตามกติกา `docs/ai/WORKFLOW.md`
- Python POS (`apps/pos-python`) ยังไม่ถูกแตะเลยในรอบนี้ (ยังอิง flow เดิม) — ตามแผนเดิมต้อง sync ด้วย
  `user_id` แต่ backward-compat ใน API (ส่ง `code`/`legacy_cashier_id` คู่กับ `user_id`/`user_name`)
  ทำให้ POS รุ่นที่ติดตั้งอยู่ยังใช้งานต่อได้ระหว่างนี้
- ยังมี `cashier_code` (ค่า legacy) หลงเหลือใน audit log payload อีก 2 จุด
  (`UserController::alignPosBranch`, `PosApiController::changeCashierPin`) — เป็นแค่ metadata ใน
  audit_logs ไม่ใช่ UI ที่ผู้ใช้เห็น จึงไม่ได้แก้ในรอบนี้ (ไม่กระทบฟังก์ชัน)
- ยังไม่ได้ตรวจ migration `148`-อื่นๆ บน PostgreSQL แยกก่อน — เช็คเฉพาะไวยากรณ์ (Schema builder,
  insertOrIgnore/updateOrInsert) ที่ใช้เป็นแบบเดียวกับ migration เก่าที่ผ่าน production มาแล้ว

### งานถัดไป (ก่อน commit/push)
1. รัน `php artisan test` ให้ผ่านทั้งหมด (โดยเฉพาะ `UserManagementTest`, `BookingSalesAreaTest`,
   `PosPinChangeTest`, และเทสต์ POS อื่นๆ ที่แตะ `PosApiController`)
2. รัน `git diff --check` และ `npm run build` ถ้าแตะ asset
3. ถ้าเทสต์ผ่านครบค่อย commit เป็นชุดเล็กที่มีความหมาย แล้วอัปเดต handoff นี้ด้วย commit hash + ผลทดสอบจริง
4. ห้าม deploy จนกว่าเจ้าของโปรเจกต์สั่ง

## Handoff - 2026-09-06 (Codex ตรวจและแก้ HTTP 500 production)
- Commit: `6dfbfdc` (source ตรงกับ `origin/main`; ไม่มีโค้ดใหม่ในรอบตรวจนี้)
- ทำอะไร: ตรวจ PHP syntax ทั้งโปรเจกต์, route/view cache, Laravel tests, production health และ HTTP smoke test; พบว่า production ยังใช้ source เก่ากว่า `main` จึง deploy source/assets ปัจจุบันแบบ explicit โดยไม่ใช้ `rsync --delete`
- จุดที่แก้หายจาก production source เก่า: product detail ใช้ตัวแปรผิด, sale/print รองรับ stock document หรือ product ที่หายไป, price-table รองรับ orphaned product price และ Vite manifest ของ POS shared CSS
- ทดสอบ: `php artisan test --compact` ผ่าน 420 tests / 419 passed / 1 skipped / 6 incomplete / 3165 assertions; `php artisan view:cache` ผ่าน; `npm run build` ผ่าน; regression route/POS/users ผ่าน 122 tests / 1276 assertions; production `erp:health` ผ่านครบ; production HTTP smoke ทุกหน้าหลัก 200/302 และไม่มี 500; error count หลัง deploy window = 0
- Deploy: สำรองก่อน deploy ที่ `storage/app/backups/erp-db-20260906-110031.sql.gz`; production migration เป็นปัจจุบัน, route/view/config cache ผ่าน, health ผ่าน
- ยังไม่ทดสอบ: POST workflow ที่ต้องใช้ session/สิทธิ์จริงและเครื่อง POS Windows/Printer จริง; รอบนี้ตรวจเฉพาะเส้นทาง GET/HEAD และชุด test ที่ไม่ทำลายข้อมูล

## Handoff - 2026-09-06 (POS transfer identity and reconciliation)
- Commit: pending (ยังไม่ได้ commit/push/deploy รอบนี้)
- ทำอะไร: ให้ข้อมูลการรับชำระแบบโอน/QR เก็บเลขท้ายบัญชีผู้โอน 4 หลักใน ERP และ SQLite โดยไม่เก็บเลขบัญชีเต็ม; เพิ่มช่องกรอกและ validation ใน POS Web/Python; ส่งต่อข้อมูลใน sync payload และพิมพ์ลงใบเสร็จ
- ERP: หน้า POS Control, Monthly Accounting, รายงานใบเสร็จ POS และหน้าเอกสารขาย/ขายสดแสดงวันที่เวลาเต็มและเลขท้ายบัญชีผู้โอนเพื่อเทียบ Statement; เพิ่ม migration `2026_09_06_000158_add_transfer_account_last4_to_pos_payments.php`
- การผูกเครื่อง: ใช้ `pos_devices.user_id` เป็นผู้ใช้ประจำเครื่องอยู่แล้ว; API จะดึง cashier ที่ผูกกับ device user เท่านั้น ขณะที่สิทธิ์สาขา/โมดูลยังคุมการทำงานแต่ละส่วนตามเดิม จึงไม่ต้องสร้าง user POS แยกซ้ำ
- UI: คงหน้าขายเดิม แต่ขยายพื้นที่บิล/รายการทางขวาและบีบพื้นที่สินค้าให้เหมาะกับจอ POS; ปรับ placeholder ให้แยกเลขอ้างอิงการโอนออกจากเลขท้ายบัญชีอย่างชัดเจน
- ทดสอบ: Laravel `php artisan test --compact` ผ่าน 420 tests / 419 passed / 1 skipped / 6 incomplete / 3167 assertions; Python POS unittest ผ่าน 174 tests; POS control regression ผ่าน 7 tests / 33 assertions; `php artisan view:cache` ผ่าน; `git diff --check` ผ่าน
- ยังไม่ทดสอบ: migration และการขายจริงบน production, statement matching จริง, เครื่องพิมพ์ Windows/XPrinter จริง; ต้องทำ backup และขออนุมัติก่อน deploy
- งานถัดไป: review diff แล้ว commit; เมื่อต้องการขึ้น production ให้ migrate แบบมี backup ก่อน แล้วทดสอบบิลโอน 1 ใบและเปิดรายงานเทียบ Statement

## Handoff - 2026-09-06 (Booking stock availability before reservation)
- Commit: pending (ยังไม่ได้ commit/push/deploy รอบนี้)
- ทำอะไร: หน้าใบจองส่ง `branch_id` ไปค้นหาสต๊อกและแสดง `มีอยู่ / จองแล้ว / พร้อมจอง` ต่อสินค้า; เมื่อเปลี่ยนสาขาจะ refresh ยอดให้ตรงคลังสาขานั้น และเตือนก่อน submit หากจำนวนที่กรอกเกินยอดพร้อมจอง
- Server guard: `BookingService` รวมรายการซ้ำเป็นยอดต่อสินค้า, lock `stock_balances` ใน transaction กันการจองชนกัน, ปฏิเสธเมื่อยอดพร้อมจองไม่พอ และไม่สร้างเอกสาร/ไม่เพิ่ม `reserved_qty` เมื่อปฏิเสธ; ไม่ลบหรือแก้ข้อมูลเดิม
- Compatibility: product search ใช้ `LOWER(...) LIKE` แทน `ILIKE` ในส่วนค้นหาสินค้า เพื่อให้ endpoint ที่เพิ่มการแสดงสต๊อกทำงานได้ทั้ง PostgreSQL และ SQLite tests; หากสาขายังไม่มี balance จะแสดงพร้อมจองเป็น 0 และ server จะไม่อนุญาตให้จองเกินจริง
- ทดสอบ: `BookingSalesAreaTest` + `BookingDeliveryDueTest` ผ่าน 10 tests / 38 assertions; `BookingDeliveryAndCashTransferTest` + `CashTransferScreenTest` ผ่าน 13 tests / 50 assertions; PHP lint และ Blade view cache ผ่าน; `git diff --check` ผ่าน
- Full suite: 421 tests, 417 passed, 3 failures อยู่ใน `ErpResetTransactionsTest` เดิม (คำสั่ง reset/UAT ไม่เกี่ยวกับงานนี้), 1 skipped, 6 incomplete; ไม่มี failure จาก booking stock guard
- ยังไม่ได้ทำ: ยังไม่ได้ commit/push/deploy รอบนี้

## Handoff - 2026-09-06 (Claude — เติมสต๊อกข้ามสาขา + ตรวจรับสแกน + แก้ 500 เพิ่มเติม)
- Commit: `a6fafa6`, `92ccf73`, `51d6e6e` (ผู้ใช้ push ขึ้น `origin/main` แล้วตามที่แจ้ง — sandbox นี้ไม่มีเน็ต ตรวจ push จริงที่ origin ไม่ได้ด้วยตัวเอง)
- ทำอะไร:
  1. `a6fafa6` เพิ่มเครื่องมือ "แนะนำเติมสินค้าระหว่างสาขา" — ตาราง `branch_stock_policies` (Min/Max/จุดสั่งเติมแยกต่อสาขา ต่างจาก `products.minimum_stock/maximum_stock` ที่เป็นค่ากลางของ `ReplenishmentService` เดิมซึ่งใช้สั่งซื้อจาก supplier เท่านั้น), `BranchReplenishmentService` คำนวณจากสต๊อกปลายทาง/ยอดขายย้อนหลัง(รวม POS)/ใบขอโอนที่รออยู่แล้ว/เพดานของจริงต้นทาง, หน้าจอเลือกรายการแล้วสร้างเป็น "ใบขอโอน" ผ่าน `StockTransferService::createRequest()` เดิม (ไม่ตัดสต๊อกเอง รออนุมัติปกติ)
  2. `92ccf73` เพิ่ม "ตรวจรับสินค้าโอนย้ายด้วยสแกนบาร์โค้ด" — ตาราง `stock_transfer_receipts`/`stock_transfer_receipt_items` ใหม่ทั้งหมด (แยกจาก stock_documents เดิม), หน้าจอสแกนสไตล์เดียวกับใบตรวจนับสต๊อก, ปิดใบต้องสแกนครบทุกรายการ พบยอดไม่ตรงจะบันทึกไว้เป็นหลักฐานเท่านั้น **ไม่แก้ stock_balances เอง** (ต้องไปสร้างใบปรับสต๊อกแยกถ้ายืนยันว่าจริง)
  3. `51d6e6e` ไล่หา service methods ที่ throw RuntimeException แล้วเช็คว่า controller ที่เรียกครอบ try/catch หรือยัง (สืบเนื่องจากอีก session ที่ทำ security/error-handling audit ค้างไว้ก่อนหมดโควต้า) แก้ 4 จุดที่เงื่อนไขทางธุรกิจปกติหลุดไปเป็น 500: `ProductController::store()`, `MasterDataSetupController::apply()`, `TaxComplianceController::prepare()/prepareEtax()`, `Api\OcrDocumentController` (process/review/approve/reject/postToGoodsReceipt) — แพทเทิร์นเดียวกับที่แก้ `SystemSettingController::issuePosToken()` ไปก่อนหน้านี้ (try/catch RuntimeException → `back()->withErrors()` หรือ JSON 422 แทน 500)
- ทดสอบ: **ยังไม่ได้รัน `php artisan test`/`migrate`/`npm run build` เลย** — เครื่องมือรอบนี้เป็น sandbox ที่ไม่มี PHP/Composer ติดตั้ง (เหมือนที่เคยบันทึกไว้ในรอบ 2026-08-31) ตรวจได้แค่ static analysis: อ่านโค้ดจริงทุกจุดที่แก้, เช็ค brace/paren สมดุลด้วย python, เช็ค `git status --short` ก่อนแตะทุกไฟล์กันชนกับที่ Codex แก้ค้างอยู่ (ไม่มีจุดชนกันเลยในรอบนี้)
- ยังไม่ทดสอบ/ความเสี่ยง:
  - Migration ใหม่ 2 ตัว (`2026_09_06_000200_create_branch_stock_policies_table`, `2026_09_06_000300_create_stock_transfer_receipts_table`) ยังไม่เคยรัน `php artisan migrate` เลยแม้แต่ครั้งเดียว — ต้อง migrate ก่อนถึงจะเปิดหน้าใหม่ทั้งสองได้จริง
  - พบช่องโหว่สิทธิ์เพิ่มเติมที่**ยังไม่ได้แก้**: route `search.customers`/`search.products`/`search.stock-balance` ไม่ผูกสิทธิ์โมดูลใน `RoutePermissions.php` เลย ผู้ใช้ที่ login แล้วทุกคนเรียกตรงได้ เห็น `credit_limit` ลูกค้า/`average_cost` สินค้า/สต๊อกข้ามสาขาได้หมด — ตั้งใจไม่แก้เองเพราะ endpoint ถูกใช้ร่วมหลายหน้าคนละสิทธิ์ (ขาย/จัดซื้อ/คลัง/POS) เสี่ยงตัดสิทธิ์คนอื่นถ้าใส่ผิด ต้องไล่เช็คทีละหน้าก่อน
  - ไม่มี account lockout ถาวรหลังเดารหัสผ่านผิดครบจำนวนครั้ง (มีแค่ rate limit 5 ครั้ง/นาทีต่อ username+IP ใน `AuthController` ซึ่งดูเพียงพอ) — เป็นทางเลือกเชิงออกแบบที่รอเจ้าของโปรเจกต์ตัดสินใจ ไม่ใช่บั๊ก
  - GitHub Actions workflow "Deploy ERP" ยัง fail 100% ค้างจาก session ก่อนหน้า (secrets/vars ระดับ Environment ว่างอยู่) — ยังไม่มีคำตอบว่า production deploy จริงทำผ่านช่องทางไหน
- Deploy: **ยังไม่ deploy ขึ้น production** ต้อง `php artisan migrate` (2 ตารางใหม่) ก่อน แล้วค่อย deploy ตามขั้นตอนปกติของโปรเจกต์ (backup ก่อนเสมอ)
- งานถัดไป: รัน `php artisan test` ยืนยันว่าของเดิมไม่พัง (โดยเฉพาะ 4 controller ที่แก้ 500) และ `php artisan migrate` บนเครื่องที่มี toolchain จริง; ทดสอบ UI ฟีเจอร์เติมสต๊อก/ตรวจรับสแกนกับผู้ใช้จริงก่อนพึ่งพา; ตัดสินใจเรื่อง `search.*` permission gap และ account lockout ก่อนดำเนินการต่อ; ฟีเจอร์ที่ 3-4 ตามแผน (ปิดต้นทุนผลิต+รายงานประสิทธิภาพ, ปรับต้นทุนซื้อย้อนหลัง) ยังไม่เริ่ม

## Handoff - 2026-09-06 (Claude — ปิดช่องโหว่สิทธิ์ search.* + ปิดต้นทุนผลิต/รายงานประสิทธิภาพ)
- Commit: `016f265`, `9587d7c`, `22eeff2` (ยังไม่ได้ push — sandbox นี้ไม่มีเน็ต ผู้ใช้ต้อง `git push origin main` เอง)
- ทำอะไร:
  1. `016f265` เอา `credit_limit` ลูกค้าออกจากผลลัพธ์ `/search/customers` — ตรวจแล้วว่าไม่มีหน้าไหนใช้ค่านี้จาก endpoint นี้เลย (ทุกหน้าที่ต้องใช้ credit_limit จริงดึงจาก `Customer` model ตรงๆ) ตัดออกได้โดยไม่กระทบของเดิม
  2. `9587d7c` ผูกสิทธิ์ให้ `SearchController::customers()/products()/stockBalance()` ที่เดิมเปิดให้ผู้ใช้ login แล้วทุกคนเรียกได้โดยไม่เช็คสิทธิ์เลย (ต้นเหตุจาก audit ค้างของอีก session ก่อนหน้า) — ใช้ `authorizeAny()` (OR permission ในตัว controller เอง) เพราะ endpoint พวกนี้ถูกเรียกร่วมจากหลายหน้าคนละสิทธิ์ (ขาย/จัดซื้อ/คลัง/POS) ระบบ `RoutePermissions` เดิมรองรับได้แค่ 1 permission ต่อ 1 route เท่านั้น ไล่เช็ค consumer ทุกจุด (grep ทั้ง view/controller/route('search.xxx')) ก่อนกำหนด permission set ให้แต่ละ endpoint กันตัดสิทธิ์คนที่ใช้งานจริงอยู่โดยไม่ตั้งใจ; `suppliers()/salesmen()/branches()` ปล่อยเปิดต่อ (โค้ด+ชื่อเท่านั้น ความเสี่ยงต่ำ)
  3. `22eeff2` เพิ่ม "ปิดใบสั่งผลิตด้วยมือ" (`ProductionOrder.closed_at/closed_by/close_note` + `ProductionController::closeOrder()`) แก้ปัญหาที่ใบสั่งผลิตค้างสถานะ `in_progress` ถาวรถ้าผลิตได้น้อยกว่าแผน (เดิมมีแค่ปิดอัตโนมัติตอนผลิตครบ/เกินแผนใน `ProductionReceiptService::receive()` เท่านั้น ไม่เคยมีทางปิดงานที่ขาดแผนมาก่อน) และเพิ่มหน้า "รายงานประสิทธิภาพการผลิต" (`production.efficiency`) เทียบแผน vs จริงของระบบผลิต 2 ระบบที่แยกกันและไม่เคยมีรายงานมาก่อนทั้งคู่: (ก) ใบสั่งผลิตตามสูตร - ต้นทุนตามแผนคำนวณจากสูตร x ต้นทุนเฉลี่ยวัตถุดิบปัจจุบัน (ไม่ใช่ต้นทุน ณ วันผลิตจริงเพราะระบบไม่ snapshot ต้นทุนไว้ในสูตร) เทียบต้นทุนจริงจากเอกสาร `PRODUCTION_RECEIPT` ที่ตัด FIFO จริงอยู่แล้ว (ข) งานแปรรูปชั่งน้ำหนัก - แค่รวมยอด yield/margin/ต้นทุน/มูลค่าสูญเสียที่ `StockTransformService` บันทึกไว้ต่อรอบอยู่แล้ว ไม่ได้คำนวณใหม่; ใช้สิทธิ์ `stock.manage` เดิมของ prefix `production.` ผ่าน `RoutePermissions` (ไม่ต้องเพิ่ม mapping ใหม่)
- ทดสอบ: **ยังไม่ได้รัน `php artisan test`/`migrate`/`npm run build`** — sandbox นี้ไม่มี PHP/Composer เหมือนทุกรอบก่อนหน้า ตรวจได้แค่ static analysis: อ่านโค้ด/relation/field ทุกจุดที่อ้างอิงเทียบกับ model จริงด้วย grep, เช็ค brace ทุกไฟล์ PHP สมดุลด้วย python, เช็คคู่ `@if/@endif`/`@foreach/@endforeach`/`@forelse/@endforelse` ในไฟล์ blade ใหม่/ที่แก้สมดุลครบ, เช็ค `git status --short` ก่อนแตะทุกไฟล์กันชนกับที่ Codex แก้ค้างอยู่ (ไม่มีจุดชนกันเลยในรอบนี้)
- ยังไม่ทดสอบ/ความเสี่ยง:
  - Migration ใหม่ `2026_09_06_000400_add_closure_to_production_orders_table` ยังไม่เคยรัน `php artisan migrate` เลย — ต้อง migrate ก่อนถึงจะปิดใบสั่งผลิตด้วยมือหรือเปิดหน้ารายงานประสิทธิภาพได้จริง (หน้ารายงานอ้างคอลัมน์/relation `closed_at`/`closedBy` ที่ยังไม่มีจริงในฐานข้อมูลจนกว่าจะ migrate)
  - ยังไม่เคยทดสอบกับข้อมูลจริงว่าปุ่ม "ปิดงาน" ทำงานถูกต้องกับใบสั่งผลิตที่มีอยู่จริง หรือรายงานประสิทธิภาพคำนวณตัวเลขตรงกับที่คาดไว้หรือไม่ (โดยเฉพาะเคสสูตรผลิตถูกลบ/ไม่ผูกไว้ ที่ควรขึ้น "ไม่มีสูตร" แทนต้นทุนผิด)
  - ผลกระทบจากการผูกสิทธิ์ `search.products`/`search.stock-balance`/`search.customers` ใหม่ยังไม่ได้ทดสอบกับผู้ใช้จริงทุก role — ถ้ามีหน้าจอที่ไม่อยู่ใน permission set ที่กำหนดไว้ (`sales.manage`/`purchasing.manage`/`pos.use`/`stock.manage`/`stock.request`/`masterdata.manage`) จะเจอ 403 ทันที ต้องให้ผู้ใช้ทดสอบทุกบทบาทก่อนเชื่อมั่น 100%
  - GitHub Actions "Deploy ERP" ยัง fail 100% ค้างจาก session ก่อนหน้า (secrets/vars ระดับ Environment ว่างอยู่) — ยังไม่มีคำตอบว่า production deploy จริงทำผ่านช่องทางไหน
  - Feature 4 ตามแผนเดิม (ปรับต้นทุนซื้อย้อนหลัง) ยังไม่เริ่ม
- Deploy: **ยังไม่ push/deploy ขึ้น production** ผู้ใช้ต้อง `git push origin main` เอง แล้ว `php artisan migrate` (คอลัมน์ใหม่ 3 คอลัมน์ใน `production_orders`) ก่อน ค่อย deploy ตามขั้นตอนปกติของโปรเจกต์ (backup ก่อนเสมอ)
- งานถัดไป: `git push origin main`; รัน `php artisan test` + `php artisan migrate` บนเครื่องที่มี toolchain จริง; ทดสอบปุ่มปิดงาน/หน้ารายงานประสิทธิภาพ/สิทธิ์ search.* ใหม่กับผู้ใช้จริงทุกบทบาทก่อนพึ่งพา; ตัดสินใจเรื่อง GitHub Actions deploy pipeline; Feature 4 (ปรับต้นทุนซื้อย้อนหลัง) ยังไม่เริ่ม รอคำสั่งต่อไป


## Handoff - 2026-09-06 (Claude — ปรับต้นทุนซื้อย้อนหลัง Feature 4)
- Commit: `8da5cae` (ยังไม่ได้ push — sandbox นี้ไม่มีเน็ต/SSH key เข้า GitHub ผู้ใช้ต้อง `git push origin main` เอง)
- ทำอะไร: เพิ่มฟีเจอร์ที่ 4 ตามแผนเดิม "ปรับต้นทุนซื้อย้อนหลัง" — หน้าจอเข้าจากปุ่มในใบซื้อ (purchases.show) ให้แก้ต้นทุนต่อหน่วยของ Lot ที่มาจากใบซื้อนั้น (เช่น กรอกราคาผิดตอนรับของ/ใบแจ้งหนี้จริงราคาต่างจากที่บันทึกไว้)
  - จับคู่รายการในใบซื้อกับ Lot แบบตำแหน่งต่อตำแหน่ง (seq/id ตามลำดับที่สร้างตอนบันทึกใบซื้อ) เพราะ `stock_document_items` ไม่ได้เก็บ `stock_lot_id` ย้อนกลับไว้
  - แก้เฉพาะ `stock_lots.unit_cost` (ผลกับ FIFO ตัดสต๊อกครั้งถัดไป) และ nudge `products.average_cost` ตามสัดส่วนจำนวนคงเหลือของ Lot ต่อยอดคงเหลือทั้งหมด — เป็นค่าประมาณสอดคล้องวิธี moving average เดิมของระบบ ไม่ใช่การคำนวณใหม่ทั้งหมดจาก Lot ทุกใบ (ระบบไม่มีโครงสร้างรองรับการ replay ธุรกรรมทั้งหมด)
  - **ตั้งใจไม่แตะ** `documents.total_amount`/`stock_document_items.unit_cost`/`supplier_ledger`/GL ที่โพสต์ไปแล้วของใบซื้อเดิมเลย และ**ไม่ย้อนแก้ต้นทุนขาย/COGS**ของจำนวนที่ตัดสต๊อกออกไปแล้ว (ตามกฎห้ามแก้ยอดขายย้อนหลัง) — เก็บมูลค่าผลต่างส่วนที่ตัดไปแล้วไว้ในตารางใหม่ `purchase_cost_adjustments` (audit trail แบบ immutable ไม่มี updated_at) ให้บัญชีพิจารณาปรับผ่าน journal แยกเอง
  - เคารพงวดที่ปิดต้นทุนแล้วผ่าน `InventoryCostCloseGuard::assertOpen()` (เดิมมีแค่ observer ผูกอัตโนมัติกับการสร้าง `StockMovement` เท่านั้น ฟีเจอร์นี้ไม่สร้าง movement เพราะไม่มีการเคลื่อนไหวจำนวน จึงต้องเรียก guard เองตรงๆ ในเซอร์วิส)
  - จำกัดสิทธิ์ด้วย `inventory.cost.close` (อยู่ใน `NON_BYPASS_PERMISSIONS` อยู่แล้ว บล็อกแม้ admin ต้องได้รับมอบสิทธิ์ชัดเจนก่อน) เพิ่ม route permission mapping `purchases.cost-adjustments.` แยกจาก `purchases.` เดิมที่ใช้แค่ `purchasing.manage`
- ทดสอบ: **ยังไม่ได้รัน `php artisan test`/`migrate`** — sandbox นี้ไม่มี PHP/Composer เหมือนทุกรอบก่อนหน้า ตรวจได้แค่ static analysis: grep เทียบ field/relation ทุกจุดกับ model จริง (`StockLot`, `Product`, `StockBalance`, `Document`, `DecimalMath`), เช็ค brace ทุกไฟล์ PHP สมดุลด้วย python, เช็คคู่ `@if/@endif`/`@foreach/@endforeach` ในไฟล์ blade สมดุลครบ, เช็ค `git status --short` ก่อนแตะทุกไฟล์กันชนกับที่ Codex แก้ค้างอยู่ (ไม่มีจุดชนกันเลยในรอบนี้)
- ยังไม่ทดสอบ/ความเสี่ยง (สำคัญมาก เพราะฟีเจอร์นี้กระทบมูลค่าสต๊อกโดยตรง):
  - Migration ใหม่ `2026_09_06_000500_create_purchase_cost_adjustments_table` ยังไม่เคยรัน `php artisan migrate` เลย
  - สูตร nudge `average_cost` เป็น**ค่าประมาณ**ไม่ใช่การคำนวณที่แม่นยำ 100% สำหรับระบบ moving average (อธิบายเหตุผล/ข้อจำกัดไว้ละเอียดในคอมเมนต์ `PurchaseCostAdjustmentService`) — ควรให้ผู้มีความรู้บัญชี/ต้นทุนตรวจทานตรรกะนี้กับเคสจริงก่อนใช้งานจริงกับใบซื้อที่มีมูลค่าสูง โดยเฉพาะเคสที่มีการซื้อ/ขายสินค้าตัวเดียวกันหลายครั้งหลังใบซื้อที่จะปรับ
  - ยังไม่เคยทดสอบเคสที่จำนวนรายการในใบซื้อกับจำนวน Lot ไม่ตรงกัน (โค้ดโยน RuntimeException บล็อกไว้ แต่ยังไม่เคยเจอเคสจริงว่าเกิดขึ้นได้ในระบบหรือไม่)
  - ยังไม่ได้ทดสอบร่วมกับ `InventoryCostCloseGuard` จริง (ต้องมีข้อมูล `inventory_cost_close_periods` ที่ปิดจริงถึงจะยืนยันว่าบล็อกได้ถูกต้อง)
  - GitHub Actions "Deploy ERP" ยัง fail 100% ค้างจาก session ก่อนหน้า — ยังไม่มีคำตอบว่า production deploy จริงทำผ่านช่องทางไหน
- Deploy: **ยังไม่ push/deploy ขึ้น production** ผู้ใช้ต้อง `git push origin main` เอง แล้ว `php artisan migrate` ก่อน ค่อย deploy ตามขั้นตอนปกติ (backup ก่อนเสมอ) — แนะนำให้ทดสอบฟีเจอร์นี้กับใบซื้อทดสอบในสภาพแวดล้อม staging ก่อนใช้กับข้อมูลจริงเป็นพิเศษ เพราะกระทบมูลค่าสต๊อก
- งานถัดไป: `git push origin main`; รัน `php artisan migrate` + `php artisan test` บนเครื่องที่มี toolchain จริง; ให้ผู้มีความรู้บัญชีตรวจทานตรรกะ nudge average_cost กับเคสจริงก่อนเปิดใช้งานกับผู้ใช้ทั่วไป; Feature 1-4 ตามแผนเดิมครบแล้วทั้งหมด รอผู้ใช้ตรวจสอบและทดสอบก่อนใช้งานจริง

## Handoff - 2026-09-06 (Claude — คู่มือใช้งานแต่ละหน้าจอ ทำหน้าที่อะไร กดอะไรได้บ้าง)
- Commit: `8ca7772` (ยังไม่ได้ push — sandbox นี้ไม่มีเน็ต/SSH key เข้า GitHub ผู้ใช้ต้อง `git push origin main` เอง)
- ทำอะไร: ผู้ใช้ขอ "คู่มือแต่ละ module ทำหน้าที่อะไร กดอะไรบ้าง" — พบว่าระบบมีหน้าคู่มือ "คู่มือ PopStar 4M" (`core-modules.index` ผ่าน `ManualController`) อยู่แล้ว แต่เนื้อหาเดิม (`pillars()`) เน้นสายข้อมูลเข้า-ออกระหว่างโปรแกรม ไม่ใช่ระดับปุ่ม/การกระทำ จึงเพิ่มเมธอดใหม่ `moduleGuide()` เสริมเข้าไปแทนที่จะสร้างหน้าคู่มือแยกใหม่ (ตามหลักใช้ของเดิมที่มีอยู่แล้ว)
  - ไล่อ่าน `routes/web.php` ทุก route group + Controller ที่เกี่ยวข้องจริง (เขียนสคริปต์ python แกะ `Route::prefix()->name()->group()` ทั้งไฟล์เพื่อให้ได้ mapping เมนู → controller → action ที่แม่นยำ ไม่เดาเอาจากชื่อเมนู)
  - จัดกลุ่มตามเมนูจริงใน `App\Support\ErpMenu` ทั้ง 9 หมวด ครบทุกเมนู (~65 รายการ) — แต่ละเมนูมี "ทำหน้าที่อะไร" (1 ประโยคสั้น) และ "กดอะไรได้บ้าง" (bullet list อ้างอิงจากชื่อ method จริงในคอนโทรลเลอร์ เช่น store=เพิ่ม, approve/reject=อนุมัติ/ไม่อนุมัติ)
  - รวมฟีเจอร์ใหม่ที่เพิ่งเพิ่มเข้าระบบในรอบนี้ไว้ในคู่มือด้วย: แนะนำเติมสินค้าสาขา, ตรวจรับสินค้าโอนย้ายด้วยสแกน, ปิดใบสั่งผลิตด้วยมือ, รายงานประสิทธิภาพผลิต, ปรับต้นทุนซื้อย้อนหลัง
  - เพิ่ม accordion ใหม่ในหน้า `core-modules/index.blade.php` (ใช้ pattern เดียวกับ "คู่มือควบคุมระบบ" เดิมที่มีอยู่แล้วในหน้านี้ - CSS class เดิมทั้งหมด ไม่เพิ่มไลบรารีใหม่) แต่ละเมนูมีปุ่ม "เปิด" ที่ซ่อน/แสดงตามสิทธิ์จริงของผู้ใช้ (ใช้ `$routeAccess` closure เดิมของหน้านี้ที่มีอยู่แล้ว)
- ทดสอบ: **ยังไม่ได้เปิดดูจริงในเบราว์เซอร์** เพราะ sandbox นี้ไม่มี PHP ให้รัน server ทดสอบเหมือนทุกรอบก่อนหน้า ตรวจได้แค่ static analysis: เช็ค brace/bracket/paren สมดุลทั้งไฟล์ PHP ด้วย python, เช็คคู่ `@if/@endif`/`@foreach/@endforeach`/`<div>/</div>` ในไฟล์ blade สมดุลครบ, ตรวจว่าทุก route name ที่ใช้ใน `moduleGuide()` เป็น route ที่ไม่ต้องมี parameter (ตรงกับที่ `ErpMenu.php` ใช้อยู่แล้วทุกตัว จึงไม่น่าเกิด `route()` error เวลาสร้างลิงก์)
- ยังไม่ทดสอบ/ความเสี่ยง:
  - ยังไม่เคยเห็นหน้าตาจริงว่า accordion ใหม่ render สวยงามถูกต้องหรือไม่ (โดยเฉพาะบนมือถือ/จอเล็ก) ก่อนใช้งานจริงควรเปิดหน้า "คู่มือ PopStar 4M" ดูด้วยตาเปล่าก่อน
  - เนื้อหา "ทำหน้าที่อะไร/กดอะไรได้บ้าง" เขียนจากการอ่านโค้ด (route/controller method names) ไม่ใช่จากการนั่งดูหน้าจอจริงทีละหน้า อาจมีบางจุดที่ปุ่มจริงบนหน้าเว็บใช้คำเรียกไม่ตรงกับที่เขียนในคู่มือเป๊ะๆ ควรให้ผู้ใช้งานจริงช่วยตรวจทานเทียบกับหน้าจอจริงอย่างน้อยรอบแรก
  - เมนูบางตัว (เช่น settings.index ที่รวมหลาย sub-controller) เขียนสรุปรวมเป็นก้อนเดียว อาจไม่ละเอียดเท่าที่ผู้ใช้บางคนต้องการ
- Deploy: **ยังไม่ push/deploy** ผู้ใช้ต้อง `git push origin main` เอง (ไม่ต้อง migrate เพิ่มเพราะรอบนี้ไม่มีการแก้ฐานข้อมูล เป็นแค่เนื้อหาคู่มือ)
- งานถัดไป: `git push origin main`; เปิดหน้า "คู่มือ PopStar 4M" ดูจริงแล้วให้ feedback ว่าอยากได้ระดับความละเอียดเพิ่ม/ลดตรงไหน; ยังค้างจากรอบก่อนหน้าเหมือนเดิม: `php artisan migrate` (migration สะสม 5 ตัวจาก Feature 1-4), `php artisan test`, ตัดสินใจเรื่อง GitHub Actions deploy pipeline และ account lockout

## Handoff - 2026-09-06 (Claude — แก้ 2 บั๊กที่ผลเทสจริงจับได้หลัง push+migrate)
- Commit: `763070f` (ยังไม่ได้ push — ผู้ใช้รัน push-migrate-test.sh ไปแล้วรอบหนึ่งก่อนหน้านี้ commit นี้เป็นของใหม่ที่ commit ทีหลัง ต้อง `git push origin main` เพิ่มอีกครั้ง)
- บริบท: ผู้ใช้รัน `push-migrate-test.sh` บนเครื่องจริงสำเร็จ (push ขึ้น origin/main แล้ว, migrate 5 migration ผ่านแล้ว) แล้วส่งผล `php artisan test` จริงมาให้ดู: **4 failed, 6 incomplete, 1 skipped, 410 passed** — นี่คือรอบแรกที่ได้เห็นผลเทสจริงของงาน Feature 1-4 ทั้งหมดในรอบนี้ (ก่อนหน้านี้ตรวจได้แค่ static analysis เพราะ sandbox ไม่มี PHP)
- ทำอะไร: ไล่ดู 3 ใน 4 เคสที่ fail (ผู้ใช้ส่งภาพมาไม่ครบทั้ง 4) พบว่าเป็นบั๊กจริงที่เกิดจากงานของ session นี้เอง 2 จุด:
  1. `ErpResetTransactionsTest` fail ทั้ง 3 เคส (clears transactions / dry run / rollback message) — สาเหตุ: ตาราง `purchase_cost_adjustments` ใหม่จาก Feature 4 มี FK ไปที่ `documents`/`stock_lots` (อยู่ใน whitelist ของ `ErpResetTransactions::TRANSACTIONAL`) แต่ตัวมันเองไม่ได้อยู่ใน whitelist ทำให้ `foreignKeysFromOutsideWhitelist()` มองว่าเป็น "ตารางนอก whitelist ที่อ้างถึงตารางที่จะล้าง" แล้วบล็อกคำสั่งทั้งหมด (exit 1) ทุกโหมดรวม `--dry-run` ด้วย — แก้โดยเพิ่ม `purchase_cost_adjustments` เข้า `TRANSACTIONAL` (เป็นข้อมูลธุรกรรมที่ควรล้างตอน reset UAT เหมือนตารางอื่น)
  2. `BookingSalesAreaTest::test_booking_shows_and_enforces_available_stock_after_reservations` — สาเหตุ: สร้าง user ทดสอบโดยไม่มีสิทธิ์เลยแล้วเรียก `search.products` ตรง ๆ, `withoutMiddleware(ErpAuthorize::class)` ปิดแค่ route middleware แต่ `authorizeAny()` ที่ผูกเข้า `SearchController::products()` ใน commit `9587d7c` ของรอบก่อนหน้าเช็คสิทธิ์เองในตัว controller แยกต่างหาก จึงได้ 403 — แก้เทสให้สร้าง Role+Permission `sales.manage` ให้ user ก่อนเรียก (จำลองสิทธิ์จริงที่ผู้ใช้หน้าจองต้องมีอยู่แล้วตาม `RoutePermissions: 'bookings.' => 'sales.manage'`) แทนที่จะลดสิทธิ์การเช็คหรือเปิดกว้างขึ้น
- ทดสอบ: เช็ค brace/paren/bracket สมดุลด้วย python (sandbox นี้ไม่มี PHP เหมือนเดิม) และ `git status --short` ก่อนแตะไฟล์ (ไม่มีจุดชนกับ Codex) — **ยังไม่ได้รัน `php artisan test` ซ้ำเพื่อยืนยันว่าแก้ผ่านจริง** ต้องรอผู้ใช้รันอีกรอบ
- ยังไม่ทดสอบ/ความเสี่ยง:
  - เคสที่ 4 ใน "4 failed" (ภาพแรกที่ผู้ใช้ส่งมา ตัดที่ `BookingSalesAreaTest.php:220` แต่ไม่เห็นหัว `FAILED` เพราะภาพตัดตอนบน) ยังไม่ยืนยันว่าเป็นเคสเดียวกับข้อ 2 ที่แก้ไปแล้วหรือเป็นคนละเคส — ต้องรอผลเทสรอบใหม่
  - ยังไม่ได้ดู 6 เคส incomplete และ 1 เคส skipped ว่าเกี่ยวข้องกับงานรอบนี้หรือเป็นของเดิมอยู่แล้ว (ผู้ใช้ไม่ได้ส่งรายละเอียดมาให้)
  - ยังไม่ได้ตรวจว่ามีจุดอื่นที่ใช้ pattern เดียวกับ `ErpResetTransactions::TRANSACTIONAL` (เช่น สคริปต์ backup/restore อื่น ๆ) ที่อาจลืมเพิ่ม `purchase_cost_adjustments` เหมือนกัน
- Deploy: ต้อง `git push origin main` เพิ่มอีกครั้ง (commit `763070f` ใหม่กว่ารอบที่ push ไปแล้ว) ไม่ต้อง migrate เพิ่ม (ไม่มี migration ใหม่ในรอบนี้)
- งานถัดไป: `git push origin main`; รัน `php artisan test` ซ้ำอีกรอบแล้วส่งผลมาดูว่าเหลือ fail กี่เคสและเป็นเคสไหนบ้าง (โดยเฉพาะเคสที่ 4 ที่ยังไม่ชัดเจน + 6 incomplete + 1 skipped); ที่เหลือค้างเหมือนเดิม: ตัดสินใจเรื่อง GitHub Actions deploy pipeline และ account lockout

## Handoff - 2026-09-06 (Claude — ปิดรอบ Feature 1-4: test ผ่านครบหลังแก้ 3 บั๊ก whitelist)
- Commit: `6f7a8d9` (push แล้ว — ผู้ใช้ยืนยันผลเทสหลัง push+migrate+test บนเครื่องจริงครบ 3 รอบ)
- ผลเทสสุดท้ายจากผู้ใช้: **0 failed, 414 passed (3196 assertions), 6 incomplete, 1 skipped** — ผ่านครบ ไม่มี fail เหลือ
  - incomplete 6 เคสเป็นของ `ErpStructuralGapsTest` (ตั้งใจเขียนไว้บันทึกช่องว่างที่ยังไม่แก้ ไม่ใช่บั๊ก) และ skipped 1 เคสเป็นของ `ReportSmokeTest` (รันได้เฉพาะ PostgreSQL เครื่องผู้ใช้ทดสอบด้วย SQLite) — ทั้งสองกลุ่มไม่เกี่ยวกับงานรอบนี้เลย ตรวจแล้วว่าเป็นของเดิม
- สรุปที่แก้เพิ่มจากผลเทสจริงรอบนี้ (ต่อจาก `763070f`): พบว่า `stock_transfer_receipts`/`stock_transfer_receipt_items` (จาก migration `2026_09_06_000300` ของรอบก่อนหน้า Feature 3-4) ก็ไม่ได้อยู่ใน `ErpResetTransactions::TRANSACTIONAL` เหมือนกัน (มี FK ไปที่ `documents`) ทำให้เทส "a failure midway rolls everything back" fail ด้วยสาเหตุเดียวกับ `purchase_cost_adjustments` ก่อนหน้า — เพิ่มเข้า whitelist แล้ว (`6f7a8d9`) พร้อมไล่ตรวจ FK ทุกจุดในทุก migration ของทั้งโปรเจกต์ด้วยสคริปต์ python ยืนยันว่าไม่มีตารางไหนหลุด whitelist แบบนี้อีก
- สถานะ Feature 1-4 ทั้งหมด: **เสร็จ + push + migrate + test ผ่านครบแล้ว** พร้อมใช้งาน แต่ยังต้องการการตรวจทานเพิ่มก่อนใช้งานจริงเต็มรูปแบบ (ดูหัวข้อถัดไป)
- ยังไม่ทดสอบ/ความเสี่ยง (ของเดิมที่ยังค้างอยู่ ไม่เกี่ยวกับบั๊กที่เพิ่งแก้):
  - ยังไม่เคยทดสอบ UI จริงของปุ่ม "ปิดงาน", หน้ารายงานประสิทธิภาพผลิต, หน้าปรับต้นทุนซื้อย้อนหลัง, หน้าคู่มือ PopStar 4M (accordion ใหม่) กับข้อมูลจริงในเบราว์เซอร์
  - สูตร nudge `average_cost` ใน Feature 4 เป็นค่าประมาณ ควรให้ผู้มีความรู้บัญชีตรวจทานก่อนใช้กับใบซื้อมูลค่าสูง
  - สิทธิ์ `search.*` ใหม่ (`authorizeAny` ใน `SearchController`) ผ่านเทสแล้วแต่ยังไม่ได้ทดสอบกับผู้ใช้จริงทุก role ในระบบ (เทสอัตโนมัติครอบคลุมแค่เคสที่เขียนไว้)
  - GitHub Actions "Deploy ERP" ยัง fail ค้างจาก session ก่อนหน้า (secrets/vars ว่าง) — ยังไม่มีคำตอบเรื่อง production deploy
  - ยังไม่มี account lockout ถาวร (ทางเลือกเชิงออกแบบ รอเจ้าของโปรเจกต์ตัดสินใจ)
- งานถัดไป: `git push origin main` (commit เอกสารนี้), ทดสอบ UI ฟีเจอร์ใหม่ทั้งหมดกับผู้ใช้จริงก่อนพึ่งพา 100%, ให้ผู้มีความรู้บัญชีตรวจทาน Feature 4, ทดสอบสิทธิ์ search.* กับผู้ใช้ทุก role, ตัดสินใจเรื่อง deploy pipeline และ account lockout — ไม่มีงานเขียนโค้ดใหม่ค้างจากแผนเดิมแล้ว รอคำสั่งต่อไป
## Handoff - 2026-09-09
- Commit: `497f213` และงานเปลี่ยนตัวสแกนรอ commit
- ทำอะไร: เพิ่ม flow คลังมือถือสำหรับนับสต๊อกแบบ draft: เปิดรอบนับ partial, สแกน QR/บาร์โค้ด, แสดงยอดระบบ, กรอกยอดจริงและบันทึกทีละรายการ; เพิ่ม endpoint ที่ใช้ StockCountService เดิม และเปลี่ยนกล้องเป็น ZXing Browser (`@zxing/browser`, MIT) รองรับ QR/EAN และ 1D/2D หลายแบบ
- ทดสอบ: `php artisan route:list --name=wh.stock-counts --no-ansi` ผ่าน; `php artisan test --compact` ผ่าน 419/422 โดยมี 2 เคสเดิมใน `PosPaymentValidatorTest` ล้มเหลวเพราะข้อความ error ถูกเปลี่ยนจากงานก่อนหน้า
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ทดสอบกล้องจริงบนมือถือและยังใช้ CDN ของ html5-qrcode; ยังไม่ได้ deploy production
- Deploy: ยังไม่ deploy
- งานถัดไป: ติดตั้ง bundle ของ html5-qrcode ภายในโปรเจกต์แทน CDN หากต้องรองรับ offline/PWA และทดสอบ flow ด้วยข้อมูลสาขาจริง
## Handoff - 2026-09-10
- Commit: `a70aaf5`
- ทำอะไร: เพิ่มโมดูลบอร์ดขนส่ง `/fleet/board` ผูกใบจอง delivery กับรถและสถานะใบจอง/ขึ้นรถ/กำลังขนส่ง/ส่งแล้ว/รับเงินแล้ว/ยกเลิก พร้อมบันทึกเงินสดหรือโอน จำนวนเงิน และเลขบัญชีท้าย 4 หลัก; เก็บ `transport_jobs` เป็นประวัติถาวรและไม่รวมใน reset transactions
- ทดสอบ: `php artisan test --compact` ผ่าน 421/422, `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ deploy production; งานขนส่งเก่าที่ไม่มี job จะถูกสร้างเมื่อเปิดบอร์ดครั้งแรก
- Deploy: ยังไม่ deploy
- งานถัดไป: deploy migration และทดสอบบอร์ดกับใบจองจริง
# Handoff — 2026-09-10 (Codex Fleet report)

## Commit
`d4e9a31`

## ทำอะไรไป
- เพิ่ม route และหน้า `fleet/report` สำหรับกรองช่วงวันที่/รถ และสรุประยะทาง น้ำมัน ค่าน้ำมัน ค่าซ่อม และค่าใช้จ่ายต่อกิโลเมตรแยกรถ
- ปรับการบันทึกการวิ่งและซ่อมให้ปฏิเสธเลขไมล์ที่น้อยกว่าเลขไมล์ปัจจุบันของรถ
- เพิ่มลิงก์เข้ารายงานจากหน้าจัดการรถ

## ทดสอบไปแล้วแค่ไหน
- `php artisan test --compact` ผ่าน 421 tests, 1 skipped, 6 incomplete, 3,217 assertions
- `php artisan view:cache` ผ่าน
- `git diff --check` ผ่าน

## ความเสี่ยง/งานถัดไป
- deploy production แล้วผ่าน GitHub Actions run `34447570421`
- รายงานคำนวณจากข้อมูลการวิ่ง/ซ่อมที่มีอยู่ ยังไม่ลงบัญชีค่าใช้จ่ายอัตโนมัติ
# Handoff — 2026-09-10 (Codex Transport driver flow)

## Commit
`2ca6ec7`

## ทำอะไรไป
- เพิ่มหน้าคนขับมือถือที่ `/fleet/driver` สำหรับเลือกงานและเปลี่ยนสถานะ ขึ้นรถแล้ว → เริ่มส่งของ → ส่งสำเร็จ
- เพิ่มลิงก์จากบอร์ดขนส่งไปหน้าคนขับ
- ป้องกันปุ่มสถานะที่ไม่ถูกลำดับและปิดการแก้ไขเมื่อส่งสำเร็จ

## ทดสอบไปแล้วแค่ไหน
- `php artisan test --compact` ผ่าน 421 tests, 1 skipped, 6 incomplete, 3,221 assertions
- `php artisan view:cache` ผ่าน
- `git diff --check` ผ่าน

## ความเสี่ยง/งานถัดไป
- deploy production แล้วผ่าน GitHub Actions run `34450358978`
- ใบขึ้นของแบบตรวจมี/ไม่มีและแก้จำนวนยังเป็นงานถัดไป
# Handoff — 2026-09-10 (Codex transport load sheet)

## Commit
pending

## ทำอะไรไป
- เพิ่ม `transport_load_items` สำหรับใบขึ้นของ ผูกกับรายการสินค้าในใบจอง พร้อมสถานะมีครบ/มีบางส่วน/ไม่มี และจำนวนขึ้นรถจริง
- เพิ่มหน้า `/fleet/load-sheet/{booking}` สำหรับตรวจ แก้จำนวน และพิมพ์ใบส่งของ
- เพิ่ม endpoint บันทึกใบขึ้นของและป้องกันจำนวนส่งเกินจำนวนในใบจอง
- เพิ่ม migration table ใบขึ้นของในรายการ reset transactions

## ทดสอบไปแล้วแค่ไหน
- `php artisan test --compact` ผ่าน 421 tests, 1 skipped, 6 incomplete, 3,226 assertions
- `php artisan test tests/Feature/ErpResetTransactionsTest.php --compact` ผ่าน 8 tests / 40 assertions
- `php artisan view:cache` ผ่าน
- `git diff --check` ผ่าน

## ความเสี่ยง/งานถัดไป
- ยังไม่ได้ deploy production รอบนี้
- ยังไม่ได้บังคับให้สถานะขึ้นรถต้องผ่านการบันทึกใบขึ้นของครบทุกบรรทัด; ควรเพิ่มเป็น policy ก่อนเปิดใช้งานจริง
# Handoff — 2026-09-10 (Codex transport flow guard)

## Commit
pending

## ทำอะไรไป
- เพิ่มการตรวจลำดับสถานะขนส่ง ไม่ให้ข้ามจากใบจองไปส่งสำเร็จโดยตรง
- บังคับให้บันทึกใบขึ้นของครบก่อนเปลี่ยนเป็นขึ้นรถ
- บังคับสินค้าที่ระบุว่าไม่มีต้องมีจำนวนขึ้นรถเป็นศูนย์
- ป้องกันจำนวนขึ้นรถเกินจำนวนในใบจอง

## ทดสอบไปแล้วแค่ไหน
- `php artisan test --compact` ผ่าน 421 tests, 1 skipped, 6 incomplete, 3,226 assertions

## ความเสี่ยง/งานถัดไป
- ยังไม่ได้ deploy production รอบนี้
- การตัดสต๊อกเกิดตอนแปลงใบจองเป็นใบขายตาม flow เดิม จึงไม่ตัดซ้ำตอนใบขึ้นของ
# Handoff — 2026-09-10 (Codex access revocation)

## Commit
pending

## ทำอะไรไป
- แก้การแก้ไขผู้ใช้ให้ถอนสิทธิ์สาขาที่เอาออกจากฟอร์มจริง โดยปิด `user_branch_roles` และกำหนดวันสิ้นสุด
- ปรับคำอธิบายหน้า Users ให้ชัดว่าสาขาที่เอาออกจะถูกปิดสิทธิ์
- เพิ่มเทสต์ยืนยันการถอนสิทธิ์สาขาเสริม

## ทดสอบไปแล้วแค่ไหน
- `php artisan test --compact` ผ่าน 422 tests, 1 skipped, 6 incomplete, 3,230 assertions
- UserManagementTest ผ่าน 9 tests / 59 assertions
- `php artisan view:cache` และ `git diff --check` ผ่าน

## ความเสี่ยง/งานถัดไป
- ยังไม่ได้ deploy production รอบนี้
- สิทธิ์ระดับ role และ branch access มีอยู่แล้ว; ควรทำ UAT ด้วยบัญชีจริงของแต่ละแผนกก่อนเปิดใช้งานเต็มรูปแบบ
# Handoff — 2026-09-10 (Codex reset transport dependency)

## Commit
pending

## ทำอะไรไป
- แก้คำสั่ง reset ให้ลบ `transport_jobs` พร้อมเอกสารที่อ้างถึง ไม่ทิ้ง foreign key ข้าม whitelist
- ยืนยันว่าคำสั่ง production รอบแรก rollback และไม่มีข้อมูลถูกลบ

## ทดสอบไปแล้วแค่ไหน
- `ErpResetTransactionsTest` ผ่าน 8 tests / 40 assertions
- `git diff --check` ผ่าน

## ความเสี่ยง/งานถัดไป
- ต้อง deploy commit นี้ก่อนจึงจะรันล้าง production จริงได้
- backup production เดิม `erp-db-20260910-152534.sql.gz` ยังพร้อมกู้คืน
# Handoff — 2026-09-10 (Production transaction reset)

## Commit
`2704379`

## ทำอะไรไป
- แก้และ deploy คำสั่ง reset ให้ลบ `transport_jobs` พร้อมเอกสารที่อ้างถึง
- สำรอง production ก่อนล้าง: `erp-db-20260910-152534.sql.gz` checksum ผ่าน
- ล้างธุรกรรม production ตามที่ยืนยัน: 977 แถวจาก 74 ตาราง และตั้ง `stock_balances` 2,527 แถวเป็นศูนย์

## ทดสอบไปแล้วแค่ไหน
- Production `erp:health` ผ่าน database, migration, backup, sales-GL, storage และ queue
- หลังล้าง: products 5,280, users 114, employees 107, documents 0, receipts 0, transport_jobs 0

## ความเสี่ยง/งานถัดไป
- ข้อมูลธุรกรรมเดิมกู้ได้จาก backup ที่ระบุเท่านั้น
- ลูกค้า สาขา สิทธิ์ และข้อมูลระบบยังคงอยู่เพื่อให้ระบบทำงานได้
# Handoff - 2026-09-19 (document approval lifecycle foundation)
- Commit: `038118d`
- ทำอะไร: เพิ่มคอลัมน์และ model relations สำหรับส่งตรวจ/อนุมัติเอกสาร, เพิ่ม `DocumentLifecycleService` และ endpoint submit/approve/reject พร้อมกันผู้ส่งอนุมัติเอกสารตัวเองไม่ได้; migration รองรับ SQLite view `sales_postings` และ PostgreSQL
- ทดสอบ: `php artisan test tests/Feature/ErpStructuralGapsTest.php` ผ่าน 7 tests / 26 assertions (ยังมี incomplete 6 รายการตามช่องว่างที่ประกาศไว้); `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่มี UI ปุ่มในแต่ละหน้าเอกสาร และ service การสร้างใบขายเดิมยังสร้างเป็น `active` ทันที จึงยังไม่ถือว่า approval flow เสร็จสมบูรณ์; ยังไม่ deploy production
- งานถัดไป: ผูกปุ่ม/หน้าจอเอกสารกับ lifecycle และกำหนดกติกาการตัด stock/ลง GL ก่อนเปิดใช้จริง
# Handoff - 2026-09-19 (document lifecycle UI)
- Commit: `82df0a4`
- ทำอะไร: เพิ่มปุ่มส่งตรวจ/อนุมัติ/ตีกลับในหน้ารายละเอียดใบขายและใบขายสด เชื่อม endpoint lifecycle และแสดงสถานะ/เหตุผลการอนุมัติ
- ทดสอบ: `php artisan view:cache` ผ่าน; `php artisan test tests/Feature/ErpStructuralGapsTest.php` ผ่าน 7 tests / 26 assertions; `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่เปิดให้ service สร้างใบขายเป็น draft เพราะต้องกำหนดกติกาตัดสต๊อก/GL และทดสอบกับ flow POS ก่อน; ยังไม่ deploy รอบนี้
- งานถัดไป: เพิ่ม feature tests ของ lifecycle และกำหนด transition สำหรับเอกสารแต่ละประเภทก่อนเปิดใช้งานจริง

# Handoff - 2026-09-19 (POS seller selection without PIN)
- Commit: `9e2a511`
- ทำอะไร: เปลี่ยน POS Python จากหน้าล็อกอิน/PIN เป็นเลือกชื่อคนขายอย่างเดียว แล้วเปิดกะ → ขาย → ปิดกะได้ตาม flow เดิม; online ผูกชื่อกับ Device Token และ offline ใช้รายชื่อที่ sync ไว้; ตั้ง audit passwordless เป็น `cashier_selected`; คง Local IT PIN ไว้เฉพาะการตั้งค่าเครื่อง
- ทดสอบ: Python POS `python3 -m unittest discover -s tests` ผ่าน 174 tests; Laravel `php artisan test --compact` ผ่าน 422 tests, 1 skipped, 6 incomplete, 3,241 assertions; focused POS identity/device 19 tests / 51 assertions; Python AST syntax check และ `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: Mac เครื่องนี้ไม่มี PySide6/PyInstaller จึงสร้าง Windows `.exe` ในเครื่องไม่ได้; GitHub Actions run `35429997668` ผ่านครบและสร้าง artifact `PopCentral-POS-UAT-0.1.0-dev` แล้ว; production ต้องเปิดโหมดด้วย `php artisan pos:passwordless enable` ก่อน และต้องทำ Windows/hardware UAT จริง
- Deploy: ยังไม่ deploy ERP และยังไม่เปลี่ยนค่า production
- งานถัดไป: push commit นี้และ commit handoff, รอ/ตรวจ GitHub Actions Windows installer, จากนั้นติดตั้ง UAT แล้วทดสอบเลือกชื่อคนขาย เปิดกะ ขายเงินสด 1 ใบ ปิดกะ และตรวจ receipt/ยอด ERP

# Handoff - 2026-09-19 (POS passwordless production deploy)
- Commit: `63d37eb`
- ทำอะไร: deploy POS รุ่นเลือกชื่อคนขายโดยไม่ใช้ login/PIN ไป production ผ่าน GitHub Actions run `35430375079`; อัปโหลด release, ติดตั้ง dependencies, migrate, clear/cache Laravel และนำระบบกลับ live
- ผลตรวจ production: `erp:health` ผ่าน database, migration, backup, sales-GL, storage และ queue; migration เป็นปัจจุบัน และ workflow test/build ผ่าน
- ยังไม่เปลี่ยนค่า production setting `pos_passwordless_login`; ต้องรัน `php artisan pos:passwordless enable` บน production ก่อนใช้งาน flow เลือกชื่อแบบไม่ใช้ PIN
- งานถัดไป: เปิด setting ตามคำสั่งเจ้าของระบบ แล้วทำ Windows/hardware UAT: เลือกชื่อคนขาย → เปิดกะ → ขายเงินสด 1 ใบ → ปิดกะ → ตรวจ receipt/ยอด ERP

# Handoff - 2026-09-19 (POS installer published on web)
- Commit/source: `main` หลัง `ab439c7`
- ทำอะไร: สร้างและอัปโหลด Windows POS รุ่นใหม่ผ่าน GitHub Actions run `35431977569`; เว็บเลือกไฟล์ `PopCentral-POS-UAT-0.6.17-setup.exe` รุ่นใหม่แล้ว ขนาด 174,653,534 ไบต์
- ตรวจจริง: controller production เลือกไฟล์ `0.6.17`; `https://popstarcenter.com/download/python-pos` ตอบ HTTP 200 และ Content-Type เป็น Windows executable
- หมายเหตุ: เดิม publish job รายงานล้มเหลวเฉพาะ health-check เพราะใช้ HTTP raw IP ซึ่ง Nginx ปฏิเสธ; ปรับ workflow ให้ตรวจโดเมน HTTPS จริงแล้ว
- งานถัดไป: ทดสอบดาวน์โหลด/ติดตั้งบน Windows และเปิด `pos_passwordless_login` ด้วย `php artisan pos:passwordless enable` ก่อน UAT flow เลือกชื่อคนขาย

# Handoff - 2026-09-19 (portal and POS entry-point)
- Commit: pending
- ทำอะไร: ปรับหน้า `popstarcenter.com` ให้เป็นศูนย์รวมระบบมาตรฐาน และเปลี่ยนการ์ด POS เป็นลิงก์ดาวน์โหลด PopCentral POS Python สำหรับเครื่องแคชเชียร์ แทนการอ้างถึง Web POS ที่ไม่มีใช้งานจริง; เมื่อเข้า root ผ่าน `pos.popstarcenter.com` ให้ redirect ไปหน้าดาวน์โหลด POS
- ทดสอบ: `php artisan view:cache` ผ่าน; route list ยืนยัน root route; `php artisan test tests/Feature/AppLauncherTest.php --compact` ผ่าน 8 tests / 38 assertions; `git diff --check` ผ่าน
- Deploy: ยังไม่ deploy production รอบนี้ รอคำสั่ง deploy โดยตรง
- งานถัดไป: หลังเจ้าของยืนยัน deploy ให้ backup production แล้ว deployเฉพาะ `routes/web.php` และ `resources/views/portal.blade.php`, clear/cache และรัน `erp:health`; ตรวจลิงก์ดาวน์โหลดบนโดเมนจริง

# Handoff - 2026-09-20 (public portal and passwordless POS live)
- Commit: `af876fc`
- ทำอะไร: deploy public portal/POS download entry และแก้ smoke test ให้ root public portal ไม่คาดหวัง redirect ไป login; หน้าเว็บแยก POS download ออกจากการ์ดและลบ JetTime HR แล้ว
- Deploy: GitHub Actions run `35434385619` ผ่านครบ; production migration/cache/health ผ่าน
- Production setting: รัน `php artisan pos:passwordless enable` แล้ว ตรวจค่า `pos_passwordless_login` ได้ `1`; การเลือกชื่อคนขายไม่ต้องใช้ PIN และยังจำกัดตาม Device Token กับสาขา
- ทดสอบ: Laravel `php artisan test --compact` ผ่าน 422 tests, 1 skipped, 6 incomplete, 3,241 assertions; ตรวจ live `popstarcenter.com` พบ POS download bar และไม่พบ JetTime HR; download endpoint ตอบ HTTP 200
- ความเสี่ยง/งานถัดไป: ต้องทำ Windows/hardware UAT จริง: เลือกชื่อคนขาย → เปิดกะ → ขายเงินสด 1 ใบ → พิมพ์ใบเสร็จ 80 มม. → ปิดกะ → ตรวจ receipt/ยอด ERP

# Handoff - 2026-09-20 (browser POS prototype)
- Commit: `6e25698`
- ทำอะไร: เปลี่ยน `pos.popstarcenter.com` จากหน้าดาวน์โหลดเป็นหน้า Web POS สาธารณะสำหรับเครื่องที่มี Device Token; ใช้ API POS เดิมสำหรับ ping, เลือกคนขายแบบไม่ใช้ login/PIN, เปิดกะ, โหลดสินค้า, ขายเงินสดหรือโอน, ออกใบเสร็จ และปิดกะ; เพิ่มปุ่มตั้งค่า Token และพิมพ์ใบเสร็จ thermal 80 มม.
- ทดสอบ: `php artisan test --compact` ผ่าน 423 tests, 1 skipped, 6 incomplete, 3,248 assertions; `php artisan view:cache`; `php artisan test tests/Feature/PosBrowserTest.php tests/Feature/PrintablePageSizeTest.php`; `git diff --check`; `php artisan erp:health` เชื่อมฐานข้อมูล/ขาย-GL/storage/queue ผ่าน แต่รายงาน migration ค้าง 6 รายการและยังไม่พบ backup ตามสถานะเดิมของเครื่องพัฒนา
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ยิงขายจริงจากเบราว์เซอร์ด้วย Device Token จริง; ต้องใช้ Token ที่ผูกเครื่อง/สาขาและมีสิทธิ์ POS, หน้าเว็บเป็น online-only ไม่มี offline queue; ยังไม่ได้ deploy production
- Deploy: ยังไม่ deploy รอคำสั่งเจ้าของโดยตรง
- งานถัดไป: push commit แล้ว หากสั่ง deploy ให้ backup production, ส่ง `routes/web.php`, `resources/views/pos/browser.blade.php`, test และ handoff, clear/cache, รัน health และตรวจ `pos.popstarcenter.com`

# Handoff - 2026-09-20 (browser POS production deploy)
- Commit/source: `bc2c5fb`
- Deploy: สำรอง production ที่ `storage/app/backups/erp-db-20260920-144250.sql.gz`; ส่ง `routes/web.php` และ `resources/views/pos/browser.blade.php` ขึ้น `/var/www/jeterp` แบบ dry-run ก่อนและไม่ใช้ `--delete`; clear/cache Laravel และตรวจ `erp:health` ผ่านครบ
- ตรวจ live: `https://pos.popstarcenter.com/` ตอบ HTTP 200 และแสดง `PopCentral Web POS`; `/api/pos/ping` เมื่อไม่ส่ง Device Token ตอบ HTTP 401 ตามที่ควรเป็น
- หมายเหตุ: Web POS พร้อมใช้งานแบบ online-only; ต้องใส่ Device Token ที่ผูกเครื่อง/สาขาเพื่อเลือกคนขายและทำรายการจริง; ยังไม่ได้ยิงขายจริงด้วย token ของสาขา

# Handoff - 2026-09-20 (activate Warin web POS user)
- ทำอะไร: สำรอง production ที่ `storage/app/backups/erp-db-20260920-145810.sql.gz`; เปิดใช้งาน User POS เดิมของสาขาวาริน (user id 4) และเปลี่ยน username จาก `pos0002` เป็น `warin`; คง role `CASHIER`, สิทธิ์ `pos.sell`, โปรไฟล์คนขาย และ Device Token/อุปกรณ์เดิมไว้ ไม่ตั้งรหัสผ่าน/PIN และไม่ออก Token ใหม่
- ตรวจผล: อุปกรณ์ POS id 3 และ 9 ยัง active และผูกกับ `warin`; `erp:health` production ผ่านครบ
- งานถัดไป: refresh `https://pos.popstarcenter.com`, ใช้ Device Token เดิมของเครื่องวาริน แล้วเลือกชื่อคนขายเพื่อเปิดกะ; ยังต้องทดสอบขายจริง 1 บิลก่อนใช้งานเต็มรูปแบบ

# Handoff - 2026-09-20 (compact Web POS context bar)
- ทำอะไร: ลดพื้นที่แถบสาขา/เครื่อง/กะ/คนขายและทำรายชื่อคนขายเป็นแถบสั้น; ขยายพื้นที่ catalog และรายการขายให้เน้นข้อมูลการขายมากขึ้น; คงปุ่มตั้งค่าไว้เล็กที่มุมขวาและคงใบเสร็จ 80 มม.
- ทดสอบ: `php artisan view:cache`; `php artisan test --compact tests/Feature/PosBrowserTest.php tests/Feature/PrintablePageSizeTest.php` ผ่าน 4 tests / 10 assertions; `git diff --check`
- Deploy: ยังไม่ deploy รอบนี้
- งานถัดไป: หากสั่ง deploy ให้ส่ง `resources/views/pos/browser.blade.php`, clear/cache และตรวจภาพจริงบน `pos.popstarcenter.com`

# Handoff - 2026-09-20 (SeniorSoft-inspired selling UI)
- ทำอะไร: ปรับ Web POS และ Python POS ให้ใช้ภาษาหน้าขายเดียวกันแบบเครื่องแคชเชียร์: แถบหัวสีน้ำเงินเข้ม, พื้นที่สินค้า/ปุ่มสัมผัสเด่น, บิลแยกด้านขวา, ยอดรวมและปุ่มรับชำระเด่น, ข้อมูลคนขาย/ตั้งค่าเป็นข้อมูลรองขนาดเล็ก; เพิ่มหัวข้อสินค้าและคำแนะนำการแตะสินค้าใน Python POS โดยไม่เปลี่ยน business logic
- ทดสอบ: `PYTHONPATH=apps/pos-python python3 -m unittest discover -s apps/pos-python/tests` ผ่าน 174 tests; `php artisan test --compact` ผ่าน 423 tests, 1 skipped, 6 incomplete, 3,248 assertions; `php artisan view:cache`; `git diff --check`
- ขอบเขต: ใช้แบรนด์ PopCentral และโค้ดของโครงการเอง โดยนำแนวทาง workflow/การจัดวางของ POS เครื่องแคชเชียร์มาเป็นแรงบันดาลใจ ไม่ได้นำโลโก้หรือ asset ของ SeniorSoft มาใช้
- Deploy: ยังไม่ deploy รอบนี้ รอคำสั่ง `deploy` โดยตรง
- งานถัดไป: push commit นี้; หากสั่ง deploy ให้ส่ง view Web POS และ source/build Python POS ตาม workflow แล้วตรวจภาพจริงบน `pos.popstarcenter.com` และเครื่อง POS

# Handoff - 2026-09-20 (SeniorSoft-inspired POS UI production deploy)
- Commit/source: `6056832`
- Deploy: GitHub Actions run `35501050060` production ผ่านครบทั้ง Laravel test, frontend build, upload release และ `scripts/deploy.sh`
- ตรวจ live: `https://pos.popstarcenter.com/` ตอบ HTTP 200 และมี Web POS รุ่นใหม่; `/api/pos/ping` เมื่อไม่ส่ง Device Token ตอบ HTTP 401 ตามที่ควรเป็น
- Python build: GitHub Actions run `35500582391` สร้าง Windows executable/installer และ upload artifact สำเร็จ; ยังไม่ publish installer ทับลิงก์ดาวน์โหลด production
- งานถัดไป: ใช้ Device Token ของเครื่องจริงเพื่อทดสอบเลือกคนขาย → เปิดกะ → ขาย → พิมพ์ใบเสร็จ 80 มม. → ปิดกะ; ตรวจภาพบนจอ POS จริง

# Handoff - 2026-09-20 (bounded Web POS product loading)
- ทำอะไร: เอา `all=1` ออกจาก Web POS; หน้าแรกโหลดสินค้าไม่เกิน 100 รายการตาม API limit และช่องค้นหาจะเรียกค้น server-side ตามชื่อ/SKU/บาร์โค้ด พร้อม debounce และกันผลลัพธ์จาก request เก่าทับผลใหม่
- ทดสอบ: `php artisan view:cache`; `php artisan test --compact tests/Feature/PosBrowserTest.php tests/Feature/PrintablePageSizeTest.php` ผ่าน 4 tests / 13 assertions; `git diff --check`
- Deploy: ยังไม่ deploy รอบนี้ รอคำสั่งเจ้าของระบบ
- งานถัดไป: push commit นี้แล้ว deploy view Web POS เมื่อได้รับคำสั่ง; ตรวจว่าหน้า live โหลดไม่เกิน 100 รายการแรกและค้นหาสินค้ารหัสที่อยู่นอกชุดแรกได้

# Handoff - 2026-09-20 (bounded Web POS product loading production deploy)
- Commit/source: `1f064bd`
- Deploy: GitHub Actions run `35502019720` production ผ่านครบทั้ง Laravel test, frontend build, upload release และ `scripts/deploy.sh`
- ตรวจ live: `https://pos.popstarcenter.com/` มีข้อความ `โหลดทีละ 100 รายการ · ค้นหาเพิ่มได้`, มี `URLSearchParams` สำหรับค้นหา server-side และไม่พบ `all=1`; `/api/pos/ping` ไม่ส่ง token ตอบ HTTP 401
- งานถัดไป: ทดสอบด้วย Device Token จริง โดยค้นหาสินค้าที่อยู่นอก 100 รายการแรก แล้วเลือกขาย 1 รายการเพื่อยืนยันผลลัพธ์จาก API และ flow ขายจริง

# Handoff - 2026-09-20 (single-screen POS selling layout)
- ทำอะไร: ปรับ Web POS ให้หน้าขายอยู่ใน viewport เดียวบน desktop; ย่อ summary สาขา/เครื่อง/คนขาย/กะและ seller bar, ล็อกความสูง workspace, ให้ scroll เฉพาะ product grid/cart list และตรึงยอดรวมกับปุ่มรับชำระไว้ด้านล่างของบิล; ปรับ Python POS ให้ header/ยอดรวม/numpad/ปุ่มตั้งค่าใช้พื้นที่กะทัดรัดแบบเดียวกัน
- ทดสอบ: Python UI style 23 tests; Python ทั้งชุด 174 tests; `php artisan view:cache`; `php artisan test --compact tests/Feature/PosBrowserTest.php tests/Feature/PrintablePageSizeTest.php` ผ่าน 4 tests / 13 assertions; `git diff --check`
- Deploy: ยังไม่ deploy รอบนี้ รอคำสั่งเจ้าของระบบ
- งานถัดไป: push และ build Python installer; เมื่อสั่ง deploy ให้ตรวจภาพ live บนจอ 1366x768/1440x900 ว่าปุ่มรับชำระอยู่ในจอโดยไม่ต้องเลื่อนหน้า

# Handoff - 2026-09-20 (single-screen POS selling layout production deploy)
- Commit/source: `9bec2d3`
- Deploy: GitHub Actions run `35511324665` production ผ่านครบทั้ง Laravel test, frontend build, upload release และ `scripts/deploy.sh`
- ตรวจภาพ live: เปิด Web POS ใน Chrome viewport 1440x810 หลังเลือกคนขายแล้ว เห็นแถบข้อมูลย่อ, grid สินค้า, รายการขาย, ยอดรวม และปุ่ม `รับชำระ`/`ปิดกะ` ครบในจอเดียว; scroll อยู่เฉพาะรายการด้านใน
- หมายเหตุ: ภาพแคปจากหน้า live อยู่ในผลการตรวจของงานนี้; Python source อยู่ใน commit ก่อนหน้าและ Windows installer build ผ่านแล้ว แต่ยังไม่ได้ publish installer ใหม่ทับลิงก์ดาวน์โหลด production
- งานถัดไป: UAT ด้วย Device Token/เครื่อง POS จริง โดยเพิ่มสินค้า 1 รายการ ตรวจปุ่มรับชำระและพิมพ์ใบเสร็จ 80 มม.; ตรวจ Python installer บน Windows จริง

# Handoff - 2026-09-20 (POS QR, discount scan, and touch layout)
- Commit/source: `97b9874`
- ทำอะไร: เพิ่ม API ตรวจสอบบัตรส่วนลดสำหรับ Web POS ผ่าน Device Token; เพิ่มช่องสแกน/พิมพ์รหัสบัตรส่วนลดในตะกร้าและส่งส่วนลดไป checkout อย่างปลอดภัย; เพิ่ม QR PromptPay แบบยอดตามบิลในหน้ารับชำระ; ขยายฝั่งสินค้าและทำการ์ด/ปุ่มสินค้าและปุ่มปรับจำนวนให้เหมาะกับการแตะ; ปรับ Python POS ให้ฝั่งสินค้าใหญ่ขึ้น การ์ดสูง/กดง่ายขึ้น และคง QR ในหน้าชำระเงินเดิม
- ทดสอบ: `php artisan test` ผ่าน 424 tests (423 passed, 1 skipped, 6 incomplete); `PYTHONPATH=apps/pos-python python3 -m unittest discover -s apps/pos-python/tests -p 'test_*.py'` ผ่าน 174 tests; `php artisan view:cache`; ตรวจ syntax JavaScript และ `git diff --check` ผ่าน
- Deploy: ยังไม่ deploy production; push `main` แล้ว รอคำสั่ง `deploy` โดยตรง
- ความเสี่ยง/งานถัดไป: ต้องตรวจ QR กับบัญชี PromptPay ที่ตั้งค่าไว้จริง และยิงบัตรส่วนลด/ขาย 1 บิลบน Web POS ด้วย Device Token จริง; Python installer ยังต้อง build/publish รุ่นที่มี layout นี้ก่อนใช้งานบน Windows

# Handoff - 2026-09-20 (POS QR, discount scan, and touch layout production deploy)
- Commit/source: `a1d21af` (รวมโค้ด POS จาก `97b9874`)
- Deploy: GitHub Actions run `35520214673` ผ่านครบใน production; test 56 วินาที, deploy 29 วินาที, รวม 1 นาที 32 วินาที; workflow แสดงสถานะ `Success`
- ตรวจ live: `https://pos.popstarcenter.com/` โหลดได้หลังเลือกคนขายสาขาวาริน; พบช่อง `ส่วนลดบัตร / สแกนบาร์โค้ด` และปุ่ม `ใช้บัตร`; เปิดหน้ารับชำระและเลือก `โอนเงิน / QR` พบ QR PromptPay พร้อมยอด ฿15.00 และชื่อบัญชี `เจษฎา พลแสน`
- ความปลอดภัยการตรวจ: ไม่ได้กดออกใบเสร็จและไม่เกิดการขายจริง; ปิดหน้าทดสอบและล้างรายการกลับเป็นว่างแล้ว
- งานถัดไป: UAT ด้วยบัตรส่วนลดจริงและขายจริง 1 บิลบนเครื่องสาขาวาริน; Python installer รุ่น layout นี้ยังต้อง build/publish ทับลิงก์ดาวน์โหลด production หากต้องการแจกเวอร์ชันใหม่

# Handoff - 2026-09-20 (three-row product catalog and wider sale list)
- ทำอะไร: ปรับ Web POS desktop ให้ catalog แสดงสินค้าเป็น 3 แถวใหญ่ในพื้นที่ที่เห็นทันที และให้สินค้านอก 3 แถวเลื่อนภายในกรอบเดิม; ขยายฝั่ง `รายการขาย` เป็นสัดส่วนประมาณ 40% ของพื้นที่ พร้อมเพิ่มขนาดการ์ดสินค้า/ตัวอักษรราคา; responsive mobile ยังคงไหลตามหน้าเดิม
- ทดสอบ: `php artisan test` ผ่าน 424 tests (423 passed, 1 skipped, 6 incomplete); `php artisan view:cache`; `git diff --check`
- Deploy: ยังไม่ deploy production; ต้องรอคำสั่ง `deploy` โดยตรง
- งานถัดไป: ตรวจภาพจริงที่ viewport POS 1366x768/1440x900 แล้ว deploy เมื่อเจ้าของยืนยัน

# Handoff - 2026-09-20 (three-row product catalog and wider sale list production deploy)
- Commit/source: `e67f320`
- Deploy: GitHub Actions run `35522011991` production ผ่านครบ; test 55 วินาที, deploy 29 วินาที, รวม 1 นาที 30 วินาที; สถานะ `Success`
- ตรวจ live: รีโหลด `https://pos.popstarcenter.com/` และเลือกคนขายสาขาวารินแล้ว พบ catalog ใช้กรอบ 3 แถวพร้อม scroll ภายใน และฝั่ง `รายการขาย` กว้างขึ้นประมาณ 40% ของพื้นที่ขาย; ช่องส่วนลดและปุ่มรับชำระยังอยู่ครบ
- หมายเหตุ: ใน viewport ความสูงต่ำมาก แถวที่สามอาจถูกตัดบางส่วนตามพื้นที่จริง แต่จะเลื่อนต่อได้ภายในกรอบสินค้า; layout desktop เป้าหมายคือ 3 แถวในจอ POS มาตรฐาน
- งานถัดไป: ตรวจบนจอเครื่อง POS จริงที่ความละเอียดใช้งานประจำ และพิจารณาปรับ breakpoint หากมีเครื่องจอเตี้ยกว่ามาตรฐาน

# Handoff - 2026-09-20 (compact POS context and settings)
- ทำอะไร: ย้ายข้อมูลสาขา/หมายเลขเครื่อง/คนขาย/กะไปไว้ในแถบด้านบนแบบกะทัดรัด; ย้ายการเลือกคนขายและปุ่มเปิด/ปิดกะเข้าเมนูฟันเฟือง; ปรับพื้นที่ขายเป็น 55% สินค้า / 45% รายการขาย; บีบการ์ดสินค้าและจำกัดชื่อสินค้า 2 บรรทัดเพื่อป้องกันการทับซ้อน โดยยังคง 3 แถวบน desktop
- ทดสอบ: `php artisan test` ผ่าน 424 tests (423 passed, 1 skipped, 6 incomplete); `php artisan view:cache`; `git diff --check`
- Deploy: ยังไม่ deploy production; push source แล้ว รอคำสั่ง `deploy` โดยตรง
- งานถัดไป: ตรวจภาพ live หลัง deploy ที่ viewport POS จริง และยืนยันว่าการเลือกคนขาย/เปิด-ปิดกะจากเมนูฟันเฟืองทำงานครบ

# Handoff - 2026-09-20 (compact POS context and settings production deploy)
- Commit/source: `56f401f`
- Deploy: GitHub Actions run `35522602336` production ผ่านครบทั้ง Laravel test, frontend build, upload release และ `scripts/deploy.sh`; สถานะ `Success`
- ตรวจ live: `https://pos.popstarcenter.com/` ตอบ HTTP 200 และ HTML production มี `topContext`, `settingsShiftButton`, สัดส่วน `55fr`/`45fr` และ product grid 3 แถวตาม release ใหม่; ไม่พบ `sellerPanel` เดิม
- งานถัดไป: ทดสอบด้วย Device Token จริงบนจอ POS โดยเลือกคนขาย/เปิดกะจากเมนูฟันเฟือง และตรวจภาพว่าการ์ดสินค้าไม่ทับซ้อน

# Handoff - 2026-09-21 (fix collapsed Web POS product cards)
- ทำอะไร: แก้ CSS product grid ที่ทำให้แถวสินค้าจำนวนมากถูกย่อจนข้อความซ้อน/อ่านไม่ได้; ล็อก 3 แถวแรกให้มีความสูงขั้นต่ำ 124px, แถวถัดไปไม่น้อยกว่า 124px และให้เลื่อนภายในกรอบสินค้าแทนการบีบการ์ด
- ทดสอบ: `php artisan test` ผ่าน 424 tests (423 passed, 1 skipped, 6 incomplete); `php artisan view:cache`; `git diff --check`
- Deploy: ยังไม่ deploy production; push source แล้ว รอคำสั่ง `deploy`
- งานถัดไป: ตรวจภาพ live หลัง deploy ที่จอ POS จริงว่าชื่อสินค้า/ราคา/สต๊อกแสดงครบโดยไม่ซ้อนกัน

# Handoff - 2026-09-21 (fix collapsed Web POS product cards production deploy)
- Commit/source: `6e8dffa`
- Deploy: GitHub Actions run `35555496230` production ผ่านครบทั้ง Laravel test, frontend build, upload release และ `scripts/deploy.sh`; สถานะ `Success`
- ตรวจ live: `https://pos.popstarcenter.com/` ตอบ HTTP 200 และ HTML production มี `repeat(3, minmax(124px, 1fr))` กับ `grid-auto-rows: minmax(124px, auto)`; ไม่พบ CSS เดิมที่ทำให้แถวสินค้ายุบเป็นแถบบาง
- งานถัดไป: รีเฟรชหน้า POS แล้วเลือกคนขายเพื่อยืนยันภาพการ์ดสินค้า 3 แถวบนเครื่องจริง

# Handoff - 2026-09-21 (manual weight entry for Web POS)
- ทำอะไร: เพิ่มหน้ากรอกน้ำหนักสำหรับสินค้าที่ API ระบุ `is_scale`; กดการ์ดสินค้าชั่งจะกรอกน้ำหนักเป็นกิโลกรัมและเห็นยอดคำนวณทันที, เพิ่มน้ำหนักเข้าบิลได้, แก้ไขน้ำหนักจากรายการขายได้ และสินค้าปกติยังใช้ปุ่มจำนวนเดิม
- ความถูกต้อง: checkout เดิมรองรับ `qty` แบบทศนิยมและ server pricing guard คำนวณยอดจากราคาต่อหน่วยฐาน; เพิ่มเทสต์ยืนยันน้ำหนัก `0.25` ที่ราคา 100 บาทคำนวณเป็น 25 บาท
- ทดสอบ: `php artisan test` ผ่าน 425 tests (424 passed, 1 skipped, 6 incomplete); `php artisan view:cache`; `node --check` inline Web POS JavaScript; `git diff --check`
- Deploy: ยังไม่ deploy production; push source แล้ว รอคำสั่ง `deploy`
- งานถัดไป: ตรวจบนหน้า live ด้วยสินค้าชั่งจริง เช่นค้นหา SKU/PLU แล้วกรอก 0.250 กก. ก่อนขายจริง

# Handoff - 2026-09-21 (manual weight entry for Web POS production deploy)
- Commit/source: `c616a7c`
- Deploy: GitHub Actions run `35558291161` production ผ่านครบทั้ง Laravel test, frontend build, upload release และ `scripts/deploy.sh`; สถานะ `Success`
- ตรวจ live: `https://pos.popstarcenter.com/` ตอบ HTTP 200 และ HTML production มี `weightModal`, `weightInput`, `confirmWeight`, `แก้น้ำหนัก` และ `is_scale`
- งานถัดไป: ค้นหาสินค้าชั่งจริงบนหน้า POS กดการ์ด กรอก `0.250` กก. ตรวจยอด แล้วทดสอบขายจริงตามขั้นตอน UAT

# Handoff - 2026-09-21 (scan versus manual quantity/weight flow)
- ทำอะไร: แยก Web POS เป็น 2 โฟลว์ชัดเจน — สแกนบาร์โค้ดแล้วเพิ่มเข้าบิลอัตโนมัติ, ฉลากเครื่องชั่งคำนวณน้ำหนักจากยอดที่ฝังในฉลาก, ส่วนการกดการ์ดสินค้าเองเปิดกรอกจำนวนหรือกรอกน้ำหนักตามชนิดสินค้า; เพิ่ม endpoint `POST /api/pos/scan` และปรับ Python POS ให้แตะการ์ดแล้วถามจำนวน/น้ำหนักเหมือนกัน
- ทดสอบ: `php artisan test --compact` ผ่าน 427 tests (426 passed, 1 skipped, 6 incomplete); `php artisan view:cache`; `node --check` inline Web POS JavaScript; Python POS `python3 -m unittest discover -s tests` ผ่าน 174 tests; ตรวจ AST Python และ `git diff --check`
- Deploy: ยังไม่ deploy production; รอคำสั่ง `deploy` โดยตรง
- ความเสี่ยง/งานถัดไป: ต้องทดสอบสแกนบาร์โค้ดมาตรฐานและฉลากเครื่องชั่งจริงด้วย Device Token/เครื่อง POS จริงก่อนเปิดขายจริง

# Handoff - 2026-09-21 (scan versus manual quantity/weight flow production deploy)
- Commit/source: `4658660`
- Deploy: GitHub Actions run `35561210225` production ผ่านครบทั้ง Laravel test, frontend build, upload release และ `scripts/deploy.sh`; สถานะ `Success`
- ตรวจ live: `https://pos.popstarcenter.com/` ตอบ HTTP 200 และ HTML production มี `quantityModal`, `weightModal`, `scanProduct`, `/scan`, `กรอกจำนวน` และ `กรอกน้ำหนัก`; เรียก `POST /api/pos/scan` โดยไม่มี Device Token ได้ HTTP 401 ตามการป้องกันที่คาดไว้
- งานถัดไป: ทดสอบสแกนบาร์โค้ดมาตรฐาน/ฉลากเครื่องชั่งจริงด้วย Device Token และตรวจขาย 1 บิลบนเครื่อง POS จริง

# Handoff - 2026-09-21 (harden Python POS installer build)
- ทำอะไร: เพิ่ม `--self-check` ให้ executable เปิดตรวจ import และ build metadata โดยไม่เปิดหน้าต่าง, เพิ่ม CI smoke test หลัง PyInstaller, ตรวจว่า Inno Setup สร้าง installer เพียง 1 ไฟล์ที่เป็น Windows executable ขนาดสมเหตุผล และเขียน `build_info.py` เป็น UTF-8 แบบไม่มี BOM อย่างแน่นอน
- แก้สาเหตุ publish ที่ทำให้ดูเหมือน build พัง: ค่าเริ่มต้นของ `scripts/publish-pos-python.sh` เปลี่ยนจาก raw IP/HTTP เป็น `https://popstarcenter.com`; log เดิมพบว่าไฟล์ถูกอัปโหลดแล้ว แต่ health-check ไปโฮสต์ผิดจึงได้ 404
- ทดสอบ: Python POS `python3 -m unittest discover -s apps/pos-python/tests` ผ่าน 175 tests; packaged-entrypoint self-check ผ่านในเครื่อง dev; `git diff --check`
- Deploy: ยังไม่ publish installer รุ่นใหม่และยังไม่ deploy production; เปลี่ยนเฉพาะ pipeline/สคริปต์ build รอคำสั่งเจ้าของระบบ
- งานถัดไป: สั่ง workflow `Build PopCentral POS UAT for Windows` แบบ `workflow_dispatch` ด้วย version ใหม่เพื่อสร้าง artifact และค่อยเลือก `publish=true` เมื่อต้องการเปลี่ยนลิงก์ดาวน์โหลดจริง

# Handoff - 2026-09-21 (POS Layout Designer ใช้งานจริง — Claude, branch `claude/pos-layout-ui`)

> **ไม่ได้แตะ `main` และไม่ได้ push** งานอยู่บน branch `claude/pos-layout-ui` รอ Codex review/merge
> ไม่ได้ deploy อะไรทั้งสิ้น

## ทำอะไร

- เพิ่ม `app/Support/PosLayout.php` เป็นแหล่งความจริงเดียวของ POS layout
  เดิมค่า default ถูกคัดลอกไว้ 3 ที่ (`SystemSettingController`, `PosController`, `Api\PosApiController`)
  แล้ว clamp ไม่เหมือนกัน — `savePosLayout` clamp `w` เป็น 1-12 แต่ `preview()` clamp เป็น `13 - x`
  หน้า Designer จึงบันทึกค่าหนึ่งแต่หน้าพรีวิวแสดงอีกค่าหนึ่งได้ ตอนนี้ normalize ที่เดียว
- เพิ่มชุดค่า `runtime` ที่ปลอดภัยต่อการรันจริง: `product_width`, `cart_width`, `product_rows`,
  `product_columns`, `density`, `button_size` และ `show_branch/terminal/seller/shift`
  รายละเอียดสัญญาอยู่ที่ `docs/pos-layout-contract.md`
- หน้า POS Designer ใช้งานได้จริง ไม่ใช่แค่พรีวิว: มีแผงตั้งค่า runtime, preview สัดส่วน/จำนวนช่อง/
  ขนาดปุ่มแบบสด, validation ทั้งฝั่งเบราว์เซอร์และเซิร์ฟเวอร์, ปุ่ม **คืนค่าเริ่มต้น** และบอกรุ่นที่ publish อยู่
- Web POS (`pos/browser.blade.php`) เลิก hard-code `55fr/45fr` และ `repeat(3, minmax(124px,1fr))`
  เปลี่ยนเป็นตัวแปร CSS ที่ server render มาจาก layout ที่ publish และทับอีกรอบด้วย
  `config.pos_layout.css` หลัง `/api/pos/ping` สำเร็จ (ฟังก์ชัน `applyPosLayout`)
  ชิปสาขา/เครื่อง/คนขาย/กะ ถูกซ่อนตั้งแต่ HTML ก้อนแรกเมื่อปิดไว้ ไม่ต้องรอ JavaScript
- `/pos/preview` ใช้ตัวแปรชุดเดียวกัน ทำให้ "ดูหน้าที่ Build แล้ว" ตรงกับของจริง
- `/api/pos/ping` ส่ง `runtime`, `layout_version` และ `css` เพิ่มเข้ามา โดย `schema/version/canvas/components`
  เหมือนเดิมทุกประการ — POS Python รุ่นที่ติดตั้งไปแล้วจะข้ามคีย์ใหม่ไปเอง ไม่พัง
- `PosLayout::published()` ถอยไปใช้ค่าเริ่มต้นเมื่ออ่าน AppSetting ไม่ได้ เพื่อไม่ให้หน้าขาย
  บนโดเมน `pos.*` กลายเป็น 500 ตอนฐานข้อมูลมีปัญหา

## ไฟล์ที่แก้

| ไฟล์ | สถานะ |
|---|---|
| `app/Support/PosLayout.php` | ใหม่ |
| `app/Http/Controllers/SystemSettingController.php` | แก้ (validation + reset, เลิกถือ default เอง) |
| `app/Http/Controllers/PosController.php` | แก้ (`preview()` ใช้ PosLayout, เพิ่ม `browser()`) |
| `app/Http/Controllers/Api/PosApiController.php` | แก้ (`publishedPosLayout()` ใช้ PosLayout + css) |
| `routes/web.php` | แก้ (โดเมน `pos.*` เรียก `PosController::browser()`) |
| `resources/views/settings/pos-designer.blade.php` | แก้ (แผง runtime + preview + reset) |
| `resources/views/pos/browser.blade.php` | แก้ (CSS variables + `applyPosLayout`) |
| `resources/views/pos/preview.blade.php` | แก้ (ใช้ตัวแปรเดียวกัน) |
| `tests/Feature/PosBrowserTest.php` | แก้ (assert ตัวแปรแทนค่าที่ฝังไว้) |
| `tests/Feature/PosLayoutDesignerTest.php` | ใหม่ (9 เทสต์) |
| `docs/pos-layout-contract.md` | ใหม่ |

## ทดสอบไปแล้วแค่ไหน

- `php artisan test` → **438 tests, 436 passed, 1 failed, 3357 assertions**
  ตัวที่แดงคือ `FinanceSecurityControlTest::test_operations_page_stays_available_when_a_backup_checksum_is_unreadable`
  ซึ่ง **แดงอยู่ก่อนแล้วตั้งแต่ commit ฐาน `6ab2775`** (รัน baseline ก่อนแก้: 427 tests, 425 passed, 1 failed ตัวเดียวกัน)
  สาเหตุคือสภาพแวดล้อมที่รันเทสต์เป็น root จึงอ่านไฟล์ที่ `chmod 0000` ได้ ข้อความ "อ่าน checksum ไม่ได้" เลยไม่ขึ้น
  ไม่เกี่ยวกับงานรอบนี้ และบนเครื่อง dev ปกติ (ไม่ใช่ root) ควรผ่าน
- `php artisan view:cache` → `Blade templates cached successfully.`
- `git diff --check` → ไม่มี whitespace error
- **ข้อจำกัดที่ต้องรู้**: เครื่องที่รันงานรอบนี้ไม่มี PHP ติดตั้ง จึงรันเทสต์บน PHP 8.4 ในคอนเทนเนอร์แยก
  ไม่ได้รันบน PHP 8.3 (production) หรือ 8.5 (เครื่อง dev) — ไม่ได้ใช้ไวยากรณ์ 8.4+ แต่ควรรันซ้ำก่อน merge

## ยังไม่ได้ทดสอบ / ความเสี่ยง

- ยังไม่ได้เปิดหน้าจริงบนเบราว์เซอร์ ทดสอบเป็น assertion บน HTML ที่ render เท่านั้น
- `repeat(var(--pos-product-columns), ...)` ใช้ได้ในเบราว์เซอร์ปัจจุบันทั้งหมด แต่ควรดูด้วยตาบนเครื่อง POS จริง
- ยังไม่ได้ทดสอบกับ Device Token จริง — เทสต์ ping ใช้ token ที่ออกในเทสต์
- layout เดิมที่ publish ไว้แล้วจะถูก normalize ตอนอ่าน ถ้ามีบล็อกที่ล้นขอบ canvas (`x + w > 13`)
  จะถูกหดให้พอดีตั้งแต่ตอนแสดงผล — ตรงกับที่ `preview()` ทำอยู่เดิม แต่ต่างจากที่ `savePosLayout` เคยเก็บ

## งานฝั่ง Python ที่ยกให้ Codex ทำต่อ (รอบนี้ไม่แตะตามที่สั่ง)

ข้อมูลเดินทางถึงเครื่องแล้ว — `provisioning.py` เก็บ `pos_layout` ทั้งก้อนลง `device_settings`
และ `_cached_layout()` ตรวจแค่ `schema` กับ `components` จึงปล่อย `runtime`/`css` ผ่านมาอยู่แล้ว
**ไม่ต้องแก้ sync** เหลือแค่เอาไปใช้ใน `apps/pos-python/pos_python/ui.py`:

1. `runtime.product_width` / `cart_width` → `QSplitter` ใน `PosWindow.__init__`
   ตอนนี้ล็อก `setMinimumWidth(620/460)` ตายตัว ควรใช้ `splitter.setSizes()` ตามสัดส่วนที่ตั้งไว้
   (`_layout_x()` ยังใช้ตัดสินลำดับซ้าย/ขวาเหมือนเดิมได้)
2. `runtime.product_columns` → `_product_columns_for_width()` ปัจจุบันคำนวณจากความกว้างล้วน
   ควรใช้ค่าที่ตั้งไว้เป็นหลัก แล้วลดลงเฉพาะตอนที่กว้างไม่พอจริงๆ
3. `runtime.product_rows` → ใช้คุม `tile_height` / จำนวนที่โหลดต่อหน้าใน `refresh_products()`
4. `runtime.density` → `contentsMargins`/`setSpacing`/`gap` ที่ตอนนี้ฝังเป็น 14/12/10
5. `runtime.button_size` → ความสูงปุ่มใน `STYLE`
6. `runtime.show_branch/terminal/seller/shift` → ชิปบนแถบบนของหน้าต่าง POS
7. `layout_version` → ตอนนี้ title ใช้ `layout_config.get("version", 1)` เปลี่ยนไปอ่าน
   `layout_version` ก่อนแล้วค่อย fallback เป็น `version` จะตรงกับเลขรุ่นที่แอดมินเห็นในหน้า Designer

ค่าที่ควรใช้แปลงเป็นหน่วย px คือชุดเดียวกับฝั่งเว็บ อยู่ใน `PosLayout::DENSITY_METRICS`
และ `PosLayout::BUTTON_METRICS` (ping ส่งมาให้แล้วใน `pos_layout.css`)

## งานถัดไปฝั่ง ERP

- ยังไม่ได้อัปเดต `docs/ai/PROJECT_MEMORY.md` เพื่อเลี่ยง conflict ตอน merge
  ถ้ารับงานนี้แล้วควรเพิ่มบรรทัดเรื่อง `pos_layout` runtime contract เข้าไป
- ยังไม่ push และยังไม่ deploy รอคำสั่งเจ้าของโปรเจกต์

# Handoff - 2026-09-21 (Codex ต่อ runtime layout ให้ POS Python)

> งานต่อจาก Claude commit `c0898d8` บน branch `codex/pos-layout-runtime`
> ยังไม่ได้ push และยังไม่ได้ deploy production

## ทำอะไร

- POS Python อ่าน `pos_layout.runtime` จาก `device_settings` หลัง sync แล้ว normalize ค่าให้ปลอดภัย
- ใช้ `product_width` / `cart_width` คุมสัดส่วน QSplitter ให้ตรงกับ Web POS
- ใช้ `product_rows` / `product_columns` คุมจำนวนแถวที่เห็นและคอลัมน์ปุ่มสินค้า
- ใช้ `density` คุมระยะห่าง ขนาดการ์ด และตัวอักษรสินค้า
- ใช้ `button_size` คุมความสูง/ฟอนต์/ระยะ padding ของปุ่มและปุ่มคิดเงิน
- ใช้ `pos_layout.css` ที่ Laravel คำนวณมาเป็น source ของ metric ฝั่ง Python ก่อน fallback ค่าเดิม
  จึงปรับ density/button จากเว็บแล้วไม่ต้อง build installer ใหม่
- เพิ่มแถบบนแบบ Web POS พร้อมชิปสาขา เครื่อง คนขาย และกะ ซึ่งเปิด/ซ่อนได้จาก Designer
- แสดง `layout_version` ใน title/แถบบน เพื่อยืนยันว่าเครื่องอ่าน layout รุ่นใด
- เพิ่ม unit tests กัน cache layout ผิดรูปและยืนยันว่า runtime เปลี่ยน QSS ได้โดยไม่ต้อง build ใหม่

## ทดสอบ

- Python POS: `python3 -m unittest discover -s apps/pos-python/tests -p 'test_*.py'` → **178 tests ผ่าน**
- Laravel POS tests: `php artisan test --filter='Pos(Browser|Layout|Controller)'` → **12 tests ผ่าน**
- Python AST parse และ `git diff --check` ผ่าน

## งานถัดไป

- review diff แล้ว push branch นี้เพื่อให้ owner/Claude ตรวจร่วมกัน
- เมื่อ owner สั่ง deploy ให้รัน production workflow ตาม `docs/OPERATIONS.md`
- หากต้องส่ง installer ใหม่ ต้องรัน Windows UAT build workflow แยก ไม่ใช่ production deploy

## Handoff - 2026-09-21 (Codex ปรับหน้าตา POS Python ให้ตรง Web POS)

- Branch: `codex/pos-python-web-layout`
- ทำอะไร: ปรับแถบบนเป็น `PopCentral Web POS` พร้อมชิปสาขา/เครื่อง/คนขาย/กะ, เพิ่มปุ่มเลือกคนขายและเมนูฟันเฟืองสำหรับตั้งค่า/ยอดวันนี้/เงินสด/ปิดกะ, ย้ายปุ่มล้างรายการไว้หัวบิล, ซ่อนแถบหมวดที่กินพื้นที่, เพิ่มข้อความช่วยสแกน/กรอกจำนวนหรือน้ำหนัก และเพิ่มปุ่มรีเฟรชสินค้า
- ทำอะไรเพิ่มเติม: คง logic ขาย/ชำระเงิน/ออฟไลน์/num pad เดิมไว้ เปลี่ยนเฉพาะการจัดวางและข้อความให้ใกล้ Web POS ที่สุด
- ทดสอบ: `PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=apps/pos-python python3 -m unittest discover -s apps/pos-python/tests -p 'test_*.py'` → **178 tests ผ่าน**; Python AST parse ผ่าน; `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้เปิด Qt บนเครื่องจริงเพื่อถ่ายภาพยืนยัน
- Deploy: production workflow `35578390373` ผ่านครบ และเผยแพร่ installer `0.6.19` ผ่าน Windows workflow `35578559621`; ตรวจ `pos.popstarcenter.com` และ `/download/python-pos` ได้ HTTP 200 แล้ว
- งานถัดไป: ติดตั้ง `PopCentral-POS-UAT-0.6.19-setup.exe` บนเครื่อง POS จริงแล้วตรวจภาพ/การใช้งานหน้างาน

## Handoff - 2026-09-24 (Codex ลดความซับซ้อนหน้า POS)

- Branch: `codex/simplify-pos-customer-flow`
- ทำอะไร: แก้ Vue cart ให้มีพื้นที่ scroll ของตัวเอง ป้องกันรายการขายหลายบรรทัดดันทับส่วนลด/ยอดรวม/ปุ่มชำระเงิน
- ทำอะไรเพิ่มเติม: ย้ายการค้นหาลูกค้าและสมาชิกไปไว้ในปุ่มพับ `ลูกค้า / สมาชิก (ถ้ามี)` เพื่อให้การขายปกติเริ่มจากสินค้าและรายการขายได้ทันที
- ทำอะไรเพิ่มเติม: จัด desktop layout เป็นสินค้า 55% / รายการขาย 45% และคงการทำงาน customer/member เดิมไว้เมื่อผู้ใช้เปิดส่วนเสริม
- ทำอะไรเพิ่มเติม: ล็อกพื้นที่สินค้าให้เห็น 3 แถวที่อ่านง่าย การ์ดไม่ยุบจนข้อความซ้อน และให้สินค้าที่เหลือเลื่อนใน panel สินค้า
- ทำอะไรเพิ่มเติม: แก้ Web POS ที่ `pos.popstarcenter.com` โดยตรงให้การ์ดสินค้ารักษาความสูงตาม runtime ไม่ยุบเป็นแถบ แม้มีค่า layout เก่าหรือผิดพลาด และคงหน้าหลักให้ไม่มีฟอร์มสมาชิก
- ทดสอบ: Laravel POS regression 21 tests / 147 assertions ผ่าน; Python POS 178 tests ผ่าน; `pnpm build` ผ่าน; `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ deploy production และยังไม่ได้ถ่ายภาพจาก browser หลัง build; production download URL เดิมยังตอบ HTTP 200 และไฟล์ installer ปัจจุบันคือ `0.6.19`
- งานถัดไป: review diff แล้ว commit/push branch นี้; ก่อน deploy ให้เปิดตรวจบน browser/เครื่อง POS จริงอีกครั้ง

## Handoff - 2026-09-25 (ERP control modules and POS hardware path)

- Branch: `codex/simplify-pos-customer-flow`
- ทำอะไร: เพิ่ม flow ใบรับคืนแบบรออนุมัติก่อนคืนสต๊อก/ปรับลูกหนี้/ลง GL, Supplier Credit/Debit Note แบบ maker-checker, WHT ฝั่งเจ้าหนี้พร้อมใบรับรองและรายการภาษี, Approval Matrix กลาง, และเชื่อมรายงาน/รีเซ็ตข้อมูลกับตารางใหม่
- ทำอะไรเพิ่มเติม: เพิ่มการล็อกสต๊อกก่อนออกขายเชื่อเพื่อไม่ให้ reservation ของเอกสารตัวเองทำให้ขายไม่ได้, เพิ่ม POS Windows printer queue และ raw ESC/POS cash-drawer pulse ที่เปิดได้เฉพาะการรับเงินสด, คง Void แบบไม่ลบบิลและส่ง event ขึ้น ERP
- ทดสอบ: `php artisan test --compact` → 450 tests, 449 passed, 1 skipped, 6 incomplete; `PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s apps/pos-python/tests -p 'test_*.py' -q` → 178 passed; `php artisan migrate --pretend --database=sqlite` ผ่าน; `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่มีเครื่องพิมพ์/ลิ้นชักจริงในเครื่องพัฒนา จึงต้องทำ hardware UAT บน Windows POS; `compileall` บน macOS เขียน bytecode ไป cache ของระบบไม่ได้ แต่ unittest/import ผ่าน
- Deploy: รอ commit/push และรัน production workflow หลังตรวจสถานะ GitHub; ไม่ล้างข้อมูล production
- งานถัดไป: ตรวจ production health หลัง deploy และทดสอบ UAT ซื้อ → รับของ → ขาย → ลูกหนี้ → รับเงิน → GL/รายงาน รวมถึงเครื่องพิมพ์/ลิ้นชักจริง

## Handoff - 2026-09-26 (Codex แก้กล้อง EAN-13 คลังมือถือ)

- Branch: `codex/simplify-pos-customer-flow`
- Commit: `a201622`
- ทำอะไร: แก้ปัญหา `element width` ตอนเปิดกล้อง โดยรอให้ `#qr-reader` มีความกว้างจริงก่อนเริ่มอ่าน, จำกัด `qrbox` ตามพื้นที่หน้าจอ, เพิ่ม `min-width: 0` ให้กล่องกล้อง และคง fallback ไป ZXing
- ทดสอบ: `php artisan test tests/Feature/WarehouseMobileTest.php` ผ่าน 4 tests / 22 assertions; `git diff --check` ผ่าน
- Deploy: workflow `36226683076` deploy production สำเร็จแล้ว; รวม commit แก้ `a201622` และ handoff `977e4e1`
- งานถัดไป: เปิด `/wh/` บนมือถือทดสอบกล้องจริงกับฉลาก EAN-13 และรีโหลดหน้าให้ได้ asset ล่าสุด

## Handoff - 2026-09-26 (Codex เพิ่ม fallback เมื่อสิทธิ์กล้องถูกปฏิเสธ)

- Branch: `codex/simplify-pos-customer-flow`
- Commits: `66667d2`, `cff7972`
- ทำอะไร: รองรับ `Permission dismissed` ด้วยการถ่ายภาพบาร์โค้ดผ่าน input `capture="environment"` แล้วอ่านภาพด้วย Html5Qrcode; แสดงปุ่ม `ถ่ายภาพบาร์โค้ดแทน` ตั้งแต่เปิด overlay เพื่อไม่ให้ค้างแม้คำขอสิทธิ์กล้องยังไม่ตอบกลับ; เพิ่มข้อความแนะนำให้อนุญาตกล้องใน Chrome
- ทดสอบ: `php artisan test tests/Feature/WarehouseMobileTest.php` ผ่าน 4 tests / 25 assertions; `git diff --check` ผ่าน
- Deploy: workflow `36227161895` ผ่านครบและเผยแพร่ production แล้ว
- ข้อจำกัด: การกดอนุญาตกล้องใน Chrome/ระบบปฏิบัติการยังต้องทำโดยผู้ใช้; หากไม่อนุญาต ให้ใช้ปุ่มถ่ายภาพแทนบนมือถือ

## Handoff - 2026-09-26 (Codex แก้ ZXing ต้องใช้ video element)

- Branch: `codex/simplify-pos-customer-flow`
- Commit: `4e4fbfc`
- ทำอะไร: แยก `<video id="qr-video">` สำหรับ `ZXingBrowser.decodeFromConstraints()` และไม่ส่ง `<div id="qr-reader">` ให้ ZXing อีกต่อไป; สลับแสดง/ซ่อน element ตามตัวอ่านที่กำลังทำงาน และคืน layout เมื่อปิดกล้อง
- ทดสอบ: `php artisan test tests/Feature/WarehouseMobileTest.php` ผ่าน 4 tests / 28 assertions; `git diff --check` ผ่าน
- Deploy: workflow `36227490051` ผ่านครบและเผยแพร่ production แล้ว; ตรวจหน้า `/wh` หลัง reload พบ element `qr-video` แสดงใน overlay และไม่มี error `must be a video element`

## Handoff - 2026-09-26 (Codex เติมเลขศูนย์หน้า EAN-13 จากกล้อง)

- Branch: `codex/simplify-pos-customer-flow`
- Commit: `4262409`
- ทำอะไร: `whApp.scan()` ลองค้นหาเลขที่อ่านได้ก่อน และถ้าเป็นตัวเลข 12 หลักจะลองเติม `0` เป็นเลข EAN-13 13 หลักอัตโนมัติ; รองรับช่องว่างที่ตัวอ่านอาจส่งมา
- ทดสอบ: `php artisan test tests/Feature/WarehouseMobileTest.php` ผ่าน 4 tests / 30 assertions; `git diff --check` ผ่าน
- Deploy: workflow `36227925305` ผ่านครบและเผยแพร่ production แล้ว; ตรวจ source หน้า production พบ `barcodeCandidates`, fallback เติม `0` และ `qr-video` ครบ

## Handoff - 2026-09-26 (Codex Python POS API/layout fix)
- Commit: `daa7af9`
- ทำอะไร: แก้ POS Python ให้แปลง URL เก่า `http://erp.popstarcenter.com` เป็น HTTPS อัตโนมัติก่อน ping/API โดยยังคงรองรับ IP ภายในแบบ HTTP; ลด layout เป็นสินค้า 45% / รายการขาย 55%, compact/small, การ์ดสินค้าไม่ยืดเต็ม viewport และลด numpad เพื่อให้รายการที่ยิงกับยอดรวมเห็นเด่นขึ้น; เครื่องที่ยัง cache layout รุ่น 1 แบบเดิมจะ migrate เป็น layout ใหม่อัตโนมัติ
- ทดสอบ: Python POS `183 tests` ผ่าน; Laravel full test ผ่าน; Laravel POS/layout focused `23 tests / 152 assertions` ผ่าน; `py_compile` และ `git diff --check` ผ่าน
- Deploy: ยังไม่ deploy ERP; รอ Windows workflow build/publish installer รุ่นใหม่
- งานถัดไป: push branch, build installer รุ่นใหม่, ตรวจ artifact/self-check และตรวจลิงก์ดาวน์โหลดก่อนส่งให้ติดตั้งบนเครื่อง POS จริง

## Handoff - 2026-09-26 (Codex Python POS 0.6.21 build)
- Commit: `f8768c2` (source `daa7af9`)
- Build: GitHub Actions `36230896987` ผ่าน `test-and-package` และ `publish` ครบ; Windows executable self-check และ Inno Setup artifact verification ผ่าน
- Publish: `https://popstarcenter.com/download/python-pos` และ `https://erp.popstarcenter.com/download/python-pos` ตอบ HTTP 200, Content-Disposition เป็น `PopCentral-POS-UAT-0.6.21-setup.exe`, ขนาด 174,671,155 bytes
- Deploy: publish เฉพาะ installer แล้ว; ยังไม่ได้ deploy source Laravel/layout default ขึ้น production
- งานถัดไป: ติดตั้งรุ่น 0.6.21 บนเครื่อง POS Windows, ตรวจ ping/API, เลือกคนขาย → เปิดกะ, ยิงสินค้า 1 รายการ และตรวจบิล/คิว sync กับ ERP

## Handoff - 2026-09-26 (Codex แก้หน้าโหลดและ HTTPS startup)
- Commit: `91f652d`
- ทำอะไร: รวมปุ่มดาวน์โหลด Python POS ในหน้า Workbench ให้เหลือปุ่มหลักเดียวพร้อมเลขรุ่น/ชื่อไฟล์/ขนาด/เวลาที่อัปเดต, ปรับการ์ดดาวน์โหลดและข้อความ HTTPS ให้ทันสมัยขึ้น, และแสดงเลขรุ่นใน Settings/Build Center
- ทำอะไรเพิ่มเติม: แยกตัวค้นหา installer กลางเพื่อเลือกไฟล์รุ่นสูงสุดจากทั้งโฟลเดอร์ใหม่และ legacy, บันทึกกติกาใน `PROJECT_MEMORY.md` ว่าหน้าดาวน์โหลดต้องบอกรุ่นและห้ามมีปุ่มซ้ำ
- ทำอะไรเพิ่มเติม: POS Python ล้าง BOM/ช่องว่างและ canonicalize `HTTPS://ERP.POPSTARCENTER.COM`, ยอมรับ scheme ตัวพิมพ์ต่างกัน, อัปเกรด public HTTP เดิมเป็น HTTPS และไม่ re-raise traceback ซ้อนใน Windows windowed build
- ทดสอบ: `python3 -m unittest discover -s apps/pos-python/tests -p 'test_*.py'` → **184 tests ผ่าน**; `php artisan test --compact` → **450 tests, 449 passed, 1 skipped, 6 incomplete**; PHP lint 4 ไฟล์ผ่าน; `git diff --check` ผ่าน
- Deploy: ยังไม่ deploy source Laravel; commit พร้อม push และรอ Windows UAT build/publish รุ่นถัดไป
- งานถัดไป: build/publish installer รุ่นใหม่, ตรวจลิงก์หน้าโหลดบน production และติดตั้งทดสอบบน Windows POS จริง

## Handoff - 2026-09-26 (Codex Python POS 0.6.22 publish)
- Source: `91f652d` และ handoff `e05aa28` บน branch `codex/simplify-pos-customer-flow`
- Build: GitHub Actions `36231997932` ผ่านครบทั้ง Python tests, executable self-check, Inno Setup และ artifact verification
- Publish: workflow publish สำเร็จ; `https://erp.popstarcenter.com/download/python-pos` และ `https://popstarcenter.com/download/python-pos` ตอบ HTTP 200 และส่งไฟล์ `PopCentral-POS-UAT-0.6.22-setup.exe` ขนาด 174,722,646 bytes
- Deploy: เผยแพร่ installer แล้ว; **ยังไม่ได้ deploy source Laravel** เพราะรอคำสั่ง deploy ของเจ้าของโปรเจกต์
- งานถัดไป: ติดตั้งรุ่น 0.6.22 บน Windows POS จริง; หลัง owner สั่ง deploy ให้ deployหน้า Workbench/Settings แล้วตรวจว่ามีปุ่มเดียวพร้อมเลขรุ่นบน production

## Handoff - 2026-09-26 (Codex Python POS 0.6.23 legacy URL repair)
- Commit: `cd833bb` บน branch `codex/simplify-pos-customer-flow`
- สาเหตุ: installer รุ่นก่อนหน้าพบ config ที่เก็บ public endpoint เป็น `https.erp.popstarcenter.com` หรือ hostname โดยไม่มี `://` ทำให้ตัวตรวจ HTTPS ปฏิเสธก่อนเริ่มเรียก `/api/pos/ping`
- ทำอะไร: normalize เฉพาะโดเมน public ของ PopCentral ให้ซ่อมรูปแบบ URL เก่ากลับเป็น `https://erp.popstarcenter.com` อัตโนมัติ และลบปุ่มดาวน์โหลดซ้ำจากรายการประวัติ Build
- ทดสอบ: Python POS **185 tests ผ่าน**; focused Laravel POS download tests **10 tests / 39 assertions ผ่าน**; `git diff --check` ผ่าน
- Build/Publish: GitHub Actions `36232801519` ผ่านครบ; `https://erp.popstarcenter.com/download/python-pos` ตอบ HTTP 200 และส่ง `PopCentral-POS-UAT-0.6.23-setup.exe`
- Deploy: เผยแพร่ installer แล้ว; source Laravel หน้า UI ยังรอคำสั่ง deploy ของเจ้าของโปรเจกต์
- งานถัดไป: ติดตั้ง 0.6.23 ทับบนเครื่อง Windows แล้วทดสอบ ping → ดึงสินค้า → เลือกแคชเชียร์ → เปิดกะ → ขาย/ซิงก์

## Handoff - 2026-09-26 (Codex แยกผล API health ออกจากคิว sync)
- Commit: `79e447d` บน branch `codex/simplify-pos-customer-flow`
- สาเหตุที่รายงานผ่านผิด: `SyncWorker` ตั้งสถานะออนไลน์ตั้งแต่เริ่มและใช้ `pending == 0` เป็นหลัก จึงดูเหมือน sync ผ่านทั้งที่ไม่มี request ยืนยัน Device Token
- ทำอะไร: ให้สถานะออนไลน์เป็นจริงได้หลัง `/api/pos/ping` ผ่านเท่านั้น, แสดง `ยืนยัน Device Token ผ่าน` แยกจากสถานะคิว, และให้ปุ่มทดสอบรายงานผล ping/API โดยตรงพร้อม error ที่อ่านได้
- ทดสอบ: Python POS **186 tests ผ่าน**; `git diff --check` ผ่าน
- Build/Publish: GitHub Actions `36233746539` ผ่านครบ; URL ดาวน์โหลดตอบ HTTP 200 และส่ง `PopCentral-POS-UAT-0.6.24-setup.exe`
- Deploy: เผยแพร่ installer แล้ว; source Laravel หน้าโหลดเดิมยังรอคำสั่ง deploy ของเจ้าของโปรเจกต์
- งานถัดไป: ติดตั้ง 0.6.24 แล้วเปิดเมนูตั้งค่า → การ Sync และ API → กด “ทดสอบการเชื่อมต่อและ sync แคชเชียร์”; ต้องเห็น `Device Token ยืนยันกับ ERP ผ่านแล้ว` ก่อนถือว่าออนไลน์

## Handoff - 2026-09-27 (Codex แก้ token binding และกติกาสินค้าชั่ง)

- Branch: `codex/simplify-pos-customer-flow`
- ทำอะไรฝั่ง ERP: เพิ่มการ์ดขั้นตอนบนหน้าเครื่องมือ POS ให้เห็นชัดว่าแอดมินต้องเลือกสาขา + ผู้ใช้ POS → สร้าง Device Token → วาง token ใน POS; ปุ่มพาไปหน้า `ตั้งค่า?tab=pos-download` โดยตรง และบัญชีที่ไม่มี `settings.manage` จะเห็นข้อความให้ผู้ดูแลระบบดำเนินการ
- ทำอะไรฝั่ง ERP: เปลี่ยนชื่อแท็บเป็น `ผูกเครื่อง / Device Token`, เปิดแท็บจาก query string ได้, และหลังสร้าง/หมุน/ลบ token จะกลับมาที่แท็บผูกเครื่อง ไม่หลุดไปหน้าตั้งค่าทั่วไป; token ยังคงถูกเก็บกับ `branch_id`, `user_id` และ `terminal_code` ของ `PosDevice`
- ทำอะไรฝั่ง Python POS: การเลือกการ์ดสินค้าปกติไม่ถามจำนวน/น้ำหนักแล้ว และเพิ่มเข้าบิล 1 หน่วยทันที; สินค้าชั่งเท่านั้นที่เปิด dialog น้ำหนัก; การยิงบาร์โค้ดปกติยังเพิ่ม 1 หน่วย และป้ายชั่ง 800/801 ยังคำนวณน้ำหนักจากฉลากตาม profile
- ทดสอบ: `python3 -m unittest discover -s apps/pos-python/tests -v` → **187 tests ผ่าน**; `php artisan test --compact` → **450 tests, 449 passed, 1 skipped, 6 incomplete, 3474 assertions**; `php artisan view:cache` และ `git diff --check` ผ่าน
- Build/Publish: GitHub Actions `36294124278` ผ่านครบทั้ง Python tests, executable self-check, Inno Setup และ publish; `https://erp.popstarcenter.com/download/python-pos` ตอบ HTTP 200 และส่งไฟล์ `PopCentral-POS-UAT-0.6.25-setup.exe` ขนาด 174,709,764 bytes
- Deploy: owner สั่ง `deploy` แล้ว; production workflow `36294643809` ผ่านทั้ง full test, frontend build, upload release และ `scripts/deploy.sh` จาก commit `0e53294`
- ตรวจหลัง deploy: `/bplus/pos-workbench` ตอบ `302` ไป login ตามสิทธิ์, `/api/pos/ping` ตอบ `401 Unauthorized` ตามปกติเมื่อไม่ส่ง Device Token (ไม่ใช่ 404), และ `/download/python-pos` ตอบ `200` พร้อม `PopCentral-POS-UAT-0.6.25-setup.exe`
- งานถัดไป: เปิดหน้าเครื่องมือ POS ด้วยบัญชีที่มี `settings.manage` เพื่อกด `ผูกสาขา / ออก Device Token`; จากนั้นติดตั้ง 0.6.25 บน Windows POS จริงและทดสอบ ping → sync → เลือกคนขาย → เปิดกะ → ยิงสินค้าปกติ/สินค้าชั่ง

## Handoff - 2026-10-16 (Claude: แก้สแกน QR/Barcode)
- Branch: `claude/fix-pos-scanner` (จาก `origin/main` 8eaf600)
- ทำอะไร:
  - แก้ bug สแกนกล้อง/รูปบน Web POS แล้วสินค้าไม่เข้าตะกร้า (dedup ของ `acceptScannerCode` ชนกับ `scanSearch`)
  - เลิกกันซ้ำ 1 วินาทีกับเครื่องสแกน keyboard (ยิงสินค้าเดิมติดกันได้); กล้องกันซ้ำ 1.5 วินาทีแยกเอง
  - Web POS และคลังมือถือบันทึก `product_scan_logs` ผ่าน `POST /pos/scan-logs`, `POST /wh/scan-logs` (`ProductScanLogController`)
  - ปุ่มไฟฉาย (ถ้ากล้องรองรับ), ปุ่ม "ถ่ายรูปบาร์โค้ด" (`capture=environment`) ถูกเน้นเมื่อเปิดกล้องสดไม่ได้
  - ป้ายราคา: เลือกบาร์โค้ด+QR / บาร์โค้ด / QR, EAN-13 ผิด check digit fallback เป็น Code128, แสดง error บนป้าย
- ทดสอบ: `php artisan test` (PHP 8.3, SQLite) 470 รายการ ผ่าน 469; ที่ไม่ผ่านคือ
  `FinanceSecurityControlTest::test_operations_page_stays_available_when_a_backup_checksum_is_unreadable` เพราะรันเป็น root (chmod 0000 ไม่มีผล) ไม่เกี่ยวกับงานนี้;
  `ProductScanLogTest` 8/8 ผ่าน; `node --check` สคริปต์ inline ของ `/pos` และ `/wh` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้รัน `pnpm run build`; UAT กล้อง Android/iPhone, ไฟฉาย, เครื่องสแกนจริง, พิมพ์ A4;
  library ยังโหลดจาก unpkg (ยังไม่ bundle ผ่าน Vite); Web POS ยังค้นสินค้าออนไลน์เท่านั้น
- Deploy: ยังไม่ deploy (ไม่มี migration ใหม่)
- งานถัดไป: bundle html5-qrcode/bwip-js/zxing ผ่าน Vite, UAT อุปกรณ์จริง

## Deploy - 2026-10-16 (claude/fix-pos-scanner → production)
- เจ้าของสั่ง deploy; ใช้ GitHub Actions `Deploy ERP` (production) run 38837350569 จาก commit `81e73fb`
- ผล: job test ผ่าน, job deploy ผ่าน (รวม 7 commit ของ main ที่ต่อจาก e535a05 ด้วย เช่น migration `2026_10_04_000201_assign_depreciation_account_roles`)
- Smoke หลัง deploy: `/login` 200, `/build/manifest.json` 200, `/pos` และ `/wh` redirect ไปหน้า login ตามปกติ
- ยังไม่ได้ดู log migration/erp:health ของ run (proxy บล็อกการดาวน์โหลด log) ให้ดูที่หน้า Actions
- ยังต้อง merge branch เข้า main และทำ UAT กล้อง/เครื่องสแกน/พิมพ์ A4 บนอุปกรณ์จริง

## Handoff - 2026-10-16 (Claude: Web POS ขายออฟไลน์)
- Branch: `claude/web-pos-offline` (ต่อจาก `claude/fix-pos-scanner`)
- ทำอะไร: Web POS ขายออฟไลน์แบบ PWA ตาม `docs/POS-WEB-OFFLINE.md`
  - `public/pos-offline.js` (IndexedDB), `public/pos-sw.js` v3, Alpine ใน `pos/index.blade.php`
  - migration `2026_10_16_000001_create_pos_offline_sales_table`, `PosOfflineSaleController`, หน้า `/pos/offline-sales`
  - `PosController::checkout` รับ `X-Offline-Uuid` (web เท่านั้น) กันบิลซ้ำ และใส่หมายเหตุเวลาขายจริงเฉพาะบิลจากคิว
  - `erp:reset-transactions` รวม `pos_offline_sales`
- ทดสอบ: `php artisan test` 480 รายการ ผ่าน 479 (ที่ไม่ผ่านคือ FinanceSecurityControlTest เพราะรันเป็น root เหมือนรอบก่อน);
  `PosOfflineSaleTest` 10/10 รวมเทสต์ที่ผ่าน checkout จริงไม่ mock; ทดลองถอดการค้นบิลเดิมออก → เทสต์แดงจริง;
  `pos-offline.js` ทดสอบใน Chromium (บันทึก/ค้นบาร์โค้ด/แพ็ค/SKU/คิว) ผ่าน; `node --check` สคริปต์ inline ของ `/pos` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ทดสอบ end-to-end บนเบราว์เซอร์กับ Host จริง (UAT 6 ข้อใน docs);
  `pnpm run build` ใน sandbox ไม่ได้ (fonts.bunny.net ถูกบล็อก) ให้ CI build; ไม่ได้แก้ TS
- Deploy: ยังไม่ deploy (มี migration ใหม่ 1 ไฟล์ — สร้างตารางใหม่อย่างเดียว)
- งานถัดไป: UAT ออฟไลน์บนเครื่องสาขา 1 เครื่องก่อนเปิดทุกสาขา

## Deploy - 2026-10-16 (claude/web-pos-offline → production)
- เจ้าของสั่ง deploy; GitHub Actions `Deploy ERP` (production) run 38841224684 จาก `b993c0c`: test ผ่าน, deploy ผ่าน
- Smoke: `/login` 200, `/build/manifest.json` 200, `/pos-offline.js` 200, `/pos-sw.js` 200 (cache `popstar-pos-v3`),
  `/pos`, `/pos/ping`, `/pos/offline-sales` redirect ไป login ตามปกติ
- ยังไม่ได้ดู log migration `pos_offline_sales` (proxy บล็อก log) และยังไม่ได้ทำ UAT ออฟไลน์ 6 ข้อใน `docs/POS-WEB-OFFLINE.md`

## Handoff - 2026-10-16 (Claude: POS Python บิลที่ ERP ปฏิเสธ → ต้องตรวจสอบ)
- Branch: `claude/pos-python-review` (ต่อจาก `claude/web-pos-offline`)
- ปัญหาเดิม: บิลที่ ERP ตอบ 422 ค้างในเครื่อง POS ลองใหม่ทุก ≤15 นาทีไม่รู้จบ ผู้จัดการไม่เห็นใน ERP (มีแค่ Log::warning)
- ทำอะไร:
  - ERP: `PosApiController::checkout` เก็บบิล 422 ที่ key เป็น UUID ลง `pos_offline_sales` (source desktop) ตอบ `needs_review: true`; ส่งใหม่สำเร็จปิดรายการเอง
  - migration `2026_10_16_000002_add_device_source_to_pos_offline_sales`; หน้า `/pos/offline-sales` แสดงเครื่อง POS
  - POS Python: `sync_service` สถานะ `needs_review` หยุดยิงซ้ำ, `review_sales()`, `requeue_review_sales()`; worker นับ `review`; หน้าสถานะมีรายการ + ปุ่มส่งใหม่
- ทดสอบ: `php artisan test` 485 รายการ ผ่าน 483 (FinanceSecurityControlTest ล้มเพราะรันเป็น root, อีก 1 ไม่ใช่ failure);
  `PosDesktopRejectedSaleReviewTest` 5/5; POS Python `unittest` 210 รายการ ผ่าน 208 — 2 error เดิมจาก sandbox ไม่มีโมดูล `qrcode`;
  ถอดการตรวจ needs_review ออก → เทสต์ใหม่แดงจริง; ui.py ตรวจแค่ compile (ไม่มี PySide6 ใน sandbox)
- ยังไม่ทดสอบ: หน้าจอ PySide6 จริง, installer Windows (ต้อง build ผ่าน GitHub Actions + ออกรุ่นใหม่ให้สาขา)
- Deploy: ยังไม่ deploy

## Deploy - 2026-10-16 (claude/pos-python-review → production + POS 0.6.28)
- ERP: GitHub Actions `Deploy ERP` run 38846628464 จาก `46962da` test/deploy ผ่าน (migration `2026_10_16_000002` รันในขั้น deploy — ยังไม่ได้เปิด log ดู)
- POS Python: run 38846794930 (version 0.6.28, publish) ผ่าน test-and-package + publish
- ตรวจ: `/download/python-pos` 200 `PopCentral-POS-UAT-0.6.28-setup.exe` 178,557,216 bytes, `/login` 200, `/api/pos/ping` 401 (ไม่มี token ตามปกติ)
- งานถัดไป: ติดตั้ง 0.6.28 ที่สาขา 1 เครื่อง ทดสอบบิลที่ ERP ปฏิเสธ → ขึ้น "ต้องตรวจสอบ" ทั้งในเครื่องและ `/pos/offline-sales` → แก้ต้นเหตุ → กดส่งใหม่

## Handoff - 2026-10-16 (Claude: เอาหน้าซ้ำซ้อนออก)
- Branch: `claude/remove-redundant-pages` (ต่อจาก `claude/pos-python-review`)
- เอาออก: `/pos/compare` + ปุ่ม "เทียบ UI" + `public/pos-desktop-preview` (เทียบกับ Vue/Tauri POS ที่เลิกใช้แล้ว),
  `/erp-mockup/*` (หน้าข้อมูลจำลอง ErpMockupController/ErpMockData), `/greenfield` (หน้า starter Tailwind v4 + `resources/css/app.css`)
- ไม่เอาออก (ต้องให้เจ้าของตัดสิน): Web POS สาธารณะที่ `pos.popstarcenter.com` (`pos/browser.blade.php`, ใช้ Device Token) ซ้อนกับ `/pos`;
  `dashboard-odoo` (ธีมให้ผู้ใช้เลือก), `/pos/preview` (ใช้ใน POS designer), `apps/pos-desktop` (เก็บไว้ rollback ตาม PROJECT_MEMORY), หน้า legacy (อ้างอิงตามนโยบาย)
- ทดสอบ: `php artisan test` 476 ผ่าน 474 (FinanceSecurityControlTest ล้มเพราะรันเป็น root เหมือนเดิม)
- หมายเหตุ deploy: rsync ไม่มี `--delete` ไฟล์เก่าบน server ยังอยู่แต่ไม่มี route เข้าถึง ยกเว้น `public/pos-desktop-preview/` ที่ยังเปิดได้ตรง ๆ ต้องลบบน server ด้วยมือ

## Handoff - 2026-10-16 (Claude: เลิก Web POS ที่ pos.popstarcenter.com + ล้างไฟล์ค้างบน server)
- เจ้าของยืนยันให้ลบ: `pos.popstarcenter.com/` redirect ไป `/download/python-pos`; ลบ `PosController::browser()` และ `pos/browser.blade.php`
- `scripts/deploy.sh` ลบไฟล์ที่เลิกใช้ตามรายชื่อ `RETIRED_PATHS` (rsync ไม่มี --delete) รวม `public/pos-desktop-preview`
- ทดสอบ: `php artisan test` 475 ผ่าน 473 (FinanceSecurityControlTest ล้มเพราะรันเป็น root)

## Hotfix - 2026-10-16 (Claude): POS checkout ล้มทุกบิลตั้งแต่ deploy web-pos-offline
- อาการ: `PosController::checkout` ใช้ `$clientBillUuid` ใน `DB::transaction` closure แต่ไม่ได้อยู่ใน `use (...)`
  → ErrorException ทุกบิล (ทั้ง Web POS และ `/api/pos/checkout` ของ POS Python) ตั้งแต่ deploy `b993c0c` (~09:40)
- พบจาก: `POS Python e2e` workflow แดงตั้งแต่ `claude/web-pos-offline` และรันซ้ำในเครื่องได้ (`Undefined variable $clientBillUuid`)
- ทำไมเทสต์ไม่จับ: `PosOfflineSaleTest` mock checkout ทั้งหมด และไม่มีเทสต์ checkout จริงฝั่ง web
- แก้: เพิ่ม `$clientBillUuid` ใน `use`; e2e script ยอมรับ key `review` ใหม่
- ตรวจ: e2e Python → Laravel ผ่าน + `uat:reconcile` ผ่านทุกเกณฑ์
- ผลกระทบ: POS Python เก็บบิลในคิวและส่งใหม่เองหลัง deploy; Web POS แสดง error ทุกบิลในช่วงนั้น

## UAT อัตโนมัติ - 2026-10-16 (Claude)
- `ErpJourneyUatTest`: ผ่าน route จริงด้วยข้อมูล UAT- (seed_uat_data.php)
  1) ซื้อเชื่อ 20×40 → สต๊อก +20 → เจ้าหนี้ 800 → จ่ายโอน 800 → เจ้าหนี้ 0 → GL ดุล → `uat:reconcile` ผ่าน
  2) ใบจอง (ไม่ตัดสต๊อก) → แปลงขายเชื่อ (ตัดสต๊อก, ลูกหนี้ 600) → วางบิล → รับโอน 600 → ลูกหนี้ 0 → นำเข้า Statement → กระทบยอดตรง → GL ดุล → reconcile ผ่าน
- `PosWebCheckoutRealTest`: checkout Web POS จริง + `X-Offline-Uuid` ส่งซ้ำได้บิลเดิม, คิวออฟไลน์ลงบิลจริง; ถอด fix ออกแล้วเทสต์แดงจริง
- CI บน main: PostgreSQL UAT ผ่าน, POS Python e2e ผ่านหลัง hotfix (รันในเครื่องพร้อม `uat:reconcile`)
- ยังทำไม่ได้จาก sandbox: restore drill บน server จริง, UAT อุปกรณ์จริง (กล้อง/เครื่องสแกน/POS 0.6.28/ขายออฟไลน์)

## UAT Web POS ในเบราว์เซอร์จริง - 2026-10-16 (Claude)
- `tools/uat/webpos/*` (Playwright + Chromium) บนเซิร์ฟเวอร์ local: ทุก CHECK ผ่าน
  keyboard wedge ยิงซ้ำเร็วได้ 2 ชิ้น, `acceptScannerCode` เพิ่มลงตะกร้า, Service Worker คุมหน้า, catalog 50 รายการใน IndexedDB,
  ตัดเน็ต → ขายในเครื่อง `OFF-…`, ปิดเปิดแอปตอนออฟไลน์คิวยังอยู่, ต่อเน็ต → sync เหลือ 0, เน็ตหลุดหลัง server บันทึก → ไม่เกิดบิลซ้ำ, `uat:reconcile` ผ่าน
- บั๊กที่เจอและแก้: ค้นสินค้าที่ตอบ 500 ทำให้ POS คิดว่า Host ล่ม แล้วบิลออนไลน์ถัดไปไปเข้าคิวออฟไลน์ทั้งที่ server ยังดี
  → นับ Host ล่มเฉพาะเน็ตหลุด/502/503/504 และถาม Host อีกครั้งก่อนสลับไปขายในเครื่อง
- `pnpm run build` ผ่านเมื่อปิดการโหลดฟอนต์ bunny (sandbox บล็อก fonts.bunny.net) — CI build ได้ตามปกติ
- Backup: server มี `erp:backup --keep-days=30` ทุกคืน 02:15 ผ่าน scheduler อยู่แล้ว; ยังขาดสำเนานอกเครื่อง (rclone ไม่ได้ติดตั้ง)

## Handoff - 2026-10-27 (Claude: P1 ข้อ 1–4 จากรายงาน E2E)
- Commits: `581440a` product picker กลาง, `6b415d6` ฟอร์มบันทึกเงียบ, `397ca11` รวมสาขาซ้ำ + กันสาขาไม่มีคลัง,
  `5658e2a` นโยบายสต๊อกติดลบรายสาขา, `f63f66f` ปิด picker จาก backdrop
- Deploy: แล้ว ผ่าน workflow `Deploy ERP` production run `40473812567` (test + deploy ผ่าน, migration
  `2026_10_26_000135_add_negative_stock_policy_to_branches` ผ่าน, `erp:health` ผ่านทุกข้อ) — รอบนี้พา `a35bd59`
  (backup แบบ streaming) ที่ค้าง deploy ขึ้นไปด้วย
- ใหม่: `erp:merge-branches --from --into --dry-run` (เช็คทุก unique index ที่มี branch_id ก่อนย้าย),
  `erp:stock-negative-report --branch --csv --fix-opening --as-of --dry-run` (ยกยอดผ่าน `OpeningBalanceService`
  ลง GL Dr สินค้าคงเหลือ / Cr 3030 และ run ชนิด `stock_negative`), หน้า `/settings/stock-policies`
- ข้อ 5 (approval ใบรับคืน) ไม่ได้ทำ: main มี `WorkflowDefinition` + `SaleReturnController::approve()` อยู่แล้ว
- ยังไม่ได้รันบนข้อมูลจริง: `erp:merge-branches --from=0001 --into=HQ` และ `erp:stock-negative-report --fix-opening`
  — รอเจ้าของสั่ง ให้รัน `--dry-run` ก่อนทุกครั้ง; นโยบายสต๊อกทุกสาขาเริ่มที่ "ยอมให้ติดลบ" (พฤติกรรมเดิม)
- ข้อควรระวังที่เจอ: ห้ามรัน `scripts/deploy-ssh.sh` จาก git worktree — `.git` ใน worktree เป็นไฟล์ ไม่ถูก
  `--exclude='.git/'` กันไว้ rsync `--delete` จะลบ `.git/` บน server ทิ้ง (dry-run เห็น `*deleting .git/...`)

## Handoff - 2026-10-27 (Codex: รับงาน session เดิมและข้อ 10 ใบวางบิล)
- Commit: `8680725` — เปลี่ยนสถานะใบวางบิลตามยอดลูกหนี้อัตโนมัติ
- รับไม้ต่อ: pull main จาก `8c4967a` เป็น `15f31df`; fetch จาก worktree เดิมและ fast-forward งานที่ยังไม่ push ทั้ง 5 commit (`21fd98a`, `02a9266`, `1a04e65`, `6c07f94`, `89eef43`) เข้ามา ไม่มี rebase เพราะฐานตรงกับ origin/main
- ทำอะไร: ลงทะเบียน CustomerOpenItemObserver ด้วย ObservedBy; เมื่อ balance_amount/status เปลี่ยน ตรวจทุกใบวางบิล open/collected ที่เกี่ยวข้อง เปลี่ยนเป็น collected เมื่อทุกยอดค้าง ≤ 0.01 หรือกลับเป็น open เมื่อหนี้กลับมา ไม่เปลี่ยนใบ cancelled และอยู่ใน transaction เดียวกับบริการรับชำระ/ปรับหนี้
- ตรวจเส้นทางเขียนลูกหนี้: CustomerPaymentService, SaleReturnService, CreditDebitNoteService ใช้ model update; query builder ใน OpeningBalanceService เป็น insert ยอดยกมาใหม่ (ยังไม่มีใบวางบิล) และ reset-transactions ล้างใบวางบิลด้วย ไม่พบ query-builder update ยอดลูกหนี้ที่ข้าม observer ใน app
- ทดสอบ: งานเดิมก่อนเพิ่มข้อ 10 `php artisan test` ผ่าน (540 tests, passed 539, skipped 1); หลังเพิ่มข้อ 10 ผ่าน (543 tests, passed 542, skipped 1; runner รายงาน incomplete 6 เหมือน baseline); รวมหลายใบขาย/หลายใบวางบิล, ยอด 0.01, หนี้กลับมา, ใบ cancelled, rollback และรับชำระเช็คจริง→เช็คเด้ง; `vendor/bin/pint --dirty` ผ่าน; `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่รัน PostgreSQL หรือ UAT บน production; ไม่ backfill สถานะใบวางบิลเก่าจนกว่าจะมีการเปลี่ยนยอด/สถานะลูกหนี้; มี migration เดิมจากข้อ 8–9 ที่ยังต้อง deploy ตามขั้นตอน
- Deploy: ยังไม่ deploy และไม่ได้แก้ข้อมูล production
- งานถัดไปตามไฟล์ส่งต่อ: ข้อ 11 void พร้อมเหตุผล/กลับสต๊อก GL AR/AP และ audit, 12 เลือกบัญชีธนาคาร, 13 เปิดกะ POS, 14 ผลิต, 16 เลขใบจอง, 17 artisan แก้/รายงานข้อมูลหลักแบบ dry-run, 18 แจ้งเตือนตรวจนับค้าง; ข้อเหล่านี้ยังไม่ได้ทำในรอบนี้ ข้อ 15 อยู่ใน `02a9266` แล้ว
- การคลีน production: รอเจ้าของอนุมัติทุกขั้นและต้องแสดง dry-run ก่อน; ความหมายของ “ใบขายเก่าเอาออก” ยังไม่ได้ยืนยัน ห้ามรัน reset-transactions โดยตีความเอง
- ไฟล์ `docs/servers.md` เป็น untracked ที่มีอยู่ก่อนเริ่ม ไม่แก้และไม่ commit

## Handoff - 2026-10-27 (Codex: รับรายงาน UAT 14 ข้อ)
- Commits: `faf7258` ป้ายราคาแก้ไข/ลบ, `53b32ef` Pipeline AJAX ค้นลูกค้า, `6249c39` จำกัด Backup/schema เฉพาะ central admin, `84a2631` รายการสมาชิกก่อนฟอร์ม, `8d04c72` ซ่อนหมวดยกเลิกใน Web POS, `759548d` เตือน/กรองผู้ใช้ไม่มีสาขา, `cd94810` แนะนำตั้งบัญชีธนาคารและ validation, `4f53022` อธิบายราคา fallback, `b9ef413` เครื่องมือรายงาน read-only และลบป้ายทดสอบตรงตัว; commit ถัดมาเพิ่ม x-data ให้ validation ฟอร์ม Pipeline ทำงาน
- ทำอะไร: แก้โค้ดตามข้างต้น พร้อมเอกสารแยกผลตรวจทุกข้อและงานที่ยังต้องข้อมูลจริงใน `docs/uat/2026-10-27-menu-readiness.md`; บัญชีส่วนกลางยังให้ branch_id=null ตามหน้าที่ ไม่ตั้งสาขาเดาเอง
- ข้อข้อมูลจริง: เตรียม `erp:uat-readiness --dry-run` (อ่านอย่างเดียวเสมอ ไม่เชื่อม MSSQL) รายงานผู้ใช้/ธนาคาร/ลูกค้าที่ข้อมูลขาด/ผู้จำหน่าย 9,A00003/เอกสารที่ต้องตรวจ/วันที่ PO และรอบรับ/ราคาสินค้า/legacy import/ยอดวันนี้และ7วัน; `erp:remove-test-price-tag --dry-run` ตรวจเฉพาะ TEST01 + TEST-Claude ก่อนคำสั่งลบจริงที่เก็บ audit และปฏิเสธชื่อไม่ตรง ยังไม่ได้รันทั้งสองบน production
- ผลตรวจที่ควรรู้: dashboard ค่าเริ่มต้น 7 วัน ส่วน executive วันนี้ ต้องเทียบวัน/สาขาเดียวกันก่อนชี้ว่า GL ผิด; product_prices 0 แถวไม่ได้แปลว่าสินค้า sync 0 เพราะ POS fallback default_price; ห้าม import ประวัติ BPlus เพื่อแก้รายงาน 0 โดยขัดนโยบาย legacy
- ทดสอบ: `php artisan test` รอบสุดท้าย 556 tests, passed 555, skipped 1, ไม่มี failure/error, runner รายงาน incomplete 6 เหมือน baseline; 4023 assertions; รันทั้งชุดระหว่างแต่ละกลุ่มแก้ไขด้วย; `vendor/bin/pint --dirty` ผ่าน; `git diff --check` ผ่าน; `node --check` สคริปต์ Pipeline ผ่าน (แทน Blade route ด้วย URL ชั่วคราวเฉพาะตรวจ syntax)
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ UAT เบราว์เซอร์/อุปกรณ์จริง ไม่ทดสอบ PostgreSQL หรือ production flow; ไม่มี migration ใหม่; เปลี่ยนสิทธิ์ Backup/schema ให้ต้องมี global users.manage+settings.manage จึงต้องตรวจ admin จริงก่อน deploy; การซ่อนหมวดแก้เฉพาะ Web POS ไม่ใช่ Python POS
- Deploy: ยังไม่ deploy; ไม่ลบ TEST01 หรือธุรกรรม production ไม่แก้ master data จริง
- รอข้อมูลจากเจ้าของ: mapping ผู้ใช้→สาขา และบัญชีธนาคาร/ผังบัญชีจริง (ถามแล้วระหว่างทำงาน ยังไม่ตอบ); เกณฑ์ลูกค้า 291 ราย/รายการธุรกรรมทดสอบที่ยืนยันแล้ว/รายงาน BPlus และวันสาขาที่คาดหวัง
- งานถัดไป: รอคำสั่ง deploy ตาม OPERATIONS; รัน dry-run ให้เจ้าของดูก่อนแก้ข้อมูลจริง; งาน session ก่อนข้อ 11–14,16–18 ยังคงค้าง โดยเฉพาะ void กลับ stock/GL/AR/AP และการเลือกบัญชีธนาคารผูก GL ทุกหน้ารับจ่าย ห้ามใช้ reset-transactions แทนการล้างเฉพาะเอกสารทดสอบ
- ไม่แตะ `docs/servers.md` ที่ untracked อยู่ก่อนเริ่ม

## Deploy - 2026-10-27 (Codex: UAT fixes และงาน session เดิม → production)
- เจ้าของสั่ง `deploy`; ใช้ GitHub Actions `Deploy ERP` production run `40492657123` จาก commit `64ee96e76e6758be041477bd592d87b2492feeed`: test และ deploy สำเร็จทั้งคู่; frontend build ผ่าน; rsync ไม่มี --delete
- Scope: รวมโค้ดหลัง deploy เดิม `f63f66f` ทั้ง AP payments, quotation conversion, sale-return source, credit settlement, billing-note observer และ UAT menu fixes ที่ส่งมอบถึง `adf8d3b`
- Backup ก่อน deploy: `/var/www/jeterp/storage/app/backups/erp-db-20261027-175611.sql.gz`; erp:health ก่อน deploy ผ่านทุกข้อ; ตรวจอ่านอย่างเดียวพบ active central admin ที่มี global users.manage+settings.manage 1 บัญชี
- รอบแรก `40492365759` ถูก CI test บล็อกก่อน deploy เพราะ PosCategoryVisibilityTest พึ่ง Vite manifest ในเครื่อง dev; แก้ด้วย withoutVite() ใน `64ee96e` ตามขอบเขตเทสต์ (ไม่ข้าม assertions); รัน php artisan test ในเครื่องซ้ำผ่าน 556 tests (555 passed, 1 skipped, runner incomplete 6 เหมือน baseline) แล้ว push และ deploy รอบใหม่สำเร็จ
- Migration production: `2026_10_27_000001` supplier allocation, `000002` unreferenced-return permission, `000003` source_document_id, `000004` credit_settlement — DONE ทั้ง 4 ใน deploy log และตรวจ migrate:status บน server เป็น batch 130 Ran ทั้งหมด
- ตรวจหลัง deploy: erp:health ผ่าน DB/Migration/Backup/ขาย-GL/Storage/Queue (failed jobs 0); HTTP /login และ /build/manifest.json = 200; /pos, /price-tags, /crm/pipeline, /members, /operations, /database-structure = 302 ไป authentication ตามปกติ
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ UAT หลังล็อกอินหรือสร้างเอกสารธุรกิจบน production ไม่ยืนยัน flow อุปกรณ์จริงจาก HTTP smoke อย่างเดียว
- ข้อมูลจริง: ไม่ได้รัน erp:remove-test-price-tag, erp:prune-products, reset-transactions หรือแก้ master data; TEST01 และข้อมูลทดสอบยังรอตรวจ dry-run/ขั้นตอนเดิม การอนุมัติ deploy ไม่ได้ขยายเป็นการล้างข้อมูล
- งานถัดไป: UAT ป้ายราคา/Pipeline/สมาชิก/สิทธิ์ admin บน production และรับ mapping ผู้ใช้→สาขา/บัญชีธนาคารจริง; รายละเอียดคงค้างใน docs/uat/2026-10-27-menu-readiness.md

## Handoff - 2026-10-27/28 (Claude: ล้างข้อมูลเก่า + นำเข้าลูกค้า/สินค้าชุดใหม่ บน production)
- Commits: `7e9bf8a` erp:purge-products, `7f391ba`+`34a6fd7` erp:purge-legacy-masters, `60b1420`+`57e3a9f` erp:import-popstar-export, `ac9b8da` erp:apply-unit-export (ทุกตัวมี --dry-run, ต้องมี backup ≤2 ชม., transaction เดียว, มี Feature test)
- เจ้าของรันเองบน production ตามลำดับ (deploy ล่าสุด `57e3a9f` run 40525603394): backup `erp-db-20261027-214430` → reset-transactions (1,056 แถว) → purge-products (5,332) → สร้างประเภท 900 "ค่าขนส่ง/บริการ" → backup `erp-db-20261027-215806` → purge-legacy-masters (ลูกค้า 7,422, พนักงานขาย 45, legacy_table_mappings 311) → import-popstar-export
- ผลหลังนำเข้า (ตรวจแล้ว): customers 7,183 (ตัดซ้ำชื่อ+เบอร์ 19), contacts 7,563, products 4,057 (active 3,637; CC ปิดใช้ 420 = ต้นทุน 0/#ยกเลิก#), barcodes 7,658 (SCALE_PLU 801xxx 76; ไม่นำเข้า 801 ที่ยกเลิก 7 ตัว), salesmen 0; erp:health ผ่านทุกข้อ
- ลบ MSSQL_SOURCE_* ออกจาก .env production แล้ว (สำรองที่ `.env.bak-20261027-mssql` ยังมีรหัสผ่าน รอเจ้าของสั่งลบ); LEGACY_POS_SYNC_SECRET ยังอยู่
- ค้าง/ความเสี่ยงก่อน go-live 5 พ.ย. (B006 อำนาจเจริญ):
  1. **ยังไม่ deploy `ac9b8da`** แล้วรัน `erp:apply-unit-export storage/app/imports/legacy_price_units.tsv` (ข้อมูลจากหน้า p_product1.php ของเว็บระบบเดิม อ่านอย่างเดียว) — ตอนนี้ **is_vat=false ทุกสินค้า** และทุกบาร์โค้ด unit_factor=1 (หน่วย PCS) ลัง/แพ็กตัดสต๊อกผิด; 36 SKU หน่วยขัดกันต้องตั้งเอง
  2. ไม่มีพนักงานขาย/แคชเชียร์เลย ต้องตั้งแคชเชียร์ + PIN B006 ใหม่ (เจ้าของตั้งเอง)
  3. สต๊อกเป็น 0 ทุกตัว ต้องรับเข้า/นับตั้งต้น (เว็บเดิมไม่มีคลังอำนาจเจริญ)
  4. สินค้า ~2,400 SKU ไม่ปรากฏในเว็บระบบเดิมเลย (น่าจะเลิกขาย) — เสนอเจ้าของย้ายไป CC ปิดใช้ ยังไม่ได้คำตอบ
  5. บัญชีธนาคาร/QR บริษัท (ยังเป็นเบอร์ส่วนตัว)
- ไฟล์ที่มีข้อมูลลูกค้าบนเซิร์ฟเวอร์: `storage/app/imports/popstar_customers_products.txt` ควรลบหลังเจ้าของยืนยัน
- auto-mode ของ Claude ไม่อนุญาตให้ Claude รันคำสั่งลบ/deploy บน production เอง เจ้าของรันเอง

## Handoff - 2026-10-28 (Claude: ปิดรายการแก้ข้อ 11–18 + จัดสาขา/คนขายสำหรับ go-live B001)
- ข้อมูล production ที่เจ้าของรันแล้ว: unit/VAT จาก BPlus (`erp:apply-unit-export` 4,057 สินค้า, VAT 2,181), ปิดสินค้าที่ BPlus ปิด (เปิดใช้ 1,622), เติมบาร์โค้ด BPlus 1,329 + รหัส 6 หลักให้สินค้าเปิดใช้ครบ, แก้ราคาหลักเป็นหน่วยฐาน 676 + 6 ตัวตามเจ้าของ, `erp:reset-branches` เหลือ HQ + B001 อำนาจเจริญ (เดิม B006), `erp:setup-branch-pos-staff` amnat + B001 แคชเชียร์ + M001 หัวหน้า (เจ้าของตั้ง PIN เอง), ลบหน้า/API/ข้อมูลระบบเดิมทั้งหมด
- โค้ดรายการแก้: ข้อ 11 `f890652` DocumentVoidService (ขายเชื่อ/ขายสด/รับคืน/ซื้อ/ใบเสร็จ กลับสต๊อก GL AR/AP สมุดเงินสด audit, สิทธิ์ใหม่ `documents.void`), ข้อ 13 `1af0c3a`, ข้อ 12 `336de04` เลือกบัญชีธนาคาร, ข้อ 16 `a51a707` เลขใบจอง BK+สาขา+วันที่, ข้อ 14 `5d46e5b`, ข้อ 17 `ea0b0b1` (`erp:repair-master-names`, `erp:report-duplicate-product-names`; เปลี่ยนชื่อคลังวารินไม่ต้องทำแล้วเพราะลบสาขา), ข้อ 18 `372565e`
- ทดสอบ: phpunit 616 ผ่าน, POS e2e CI + PostgreSQL UAT ผ่านบน `372565e`
- ยังไม่ deploy `f890652..372565e` (มี migration `documents.void`)
- ค้าง: ชื่อผู้จำหน่ายเพี้ยน 10 ราย ต้องดึงจาก BPlus APFILE (สคริปต์ให้เจ้าของรัน) แล้วรัน `erp:repair-master-names --suppliers=...`; หน่วยเพี้ยนไม่มีใครใช้ 74 รายการ `--drop-unused-units`; QR/บัญชีธนาคารบริษัท; สต๊อกตั้งต้น; เครื่อง POS จริง; คู่มือเทส `docs/uat/2026-10-28-b001-golive-test.md`

## Handoff - 2026-10-29 (Codex: POS Python unit coverage production preflight)
- แก้ POS barcode cart ให้ใช้ราคาบาร์โค้ด/หน่วยขายที่ ERP ส่งมา, เก็บ unit ID/name ใน SQLite, ไม่รวมบรรทัดขายที่เป็นคนละบาร์โค้ด/หน่วย; ปรับ planner ให้เลือกสินค้าที่มีสต็อกโดยยังบังคับครอบคลุม unit/barcode universe ทั้งแค็ตตาล็อก และตรวจ exact barcode/ราคา live ก่อนเปิดกะ
- Production read-only preflight ผ่าน: เครื่อง `POS-B001-01`, branch 34, สินค้า active 1,622; plan ที่มีสต็อก 82 SKU ครอบคลุมหน่วยขาย 109 class และ barcode `CUSTOM`, `EAN13_STANDARD`, `SCALE_PLU`; exact lookup/ราคา ERP ตรงครบ 109 barcode
- ใบรับเข้าทดสอบที่บันทึกแล้ว: `POB00120261029001`, 82 SKU × 10 หน่วยฐาน, ฿48,086.80 ซื้อเชื่อกับ `UAT-POS-TEST`; เป็นสต็อก/เจ้าหนี้จริง ต้องให้เจ้าของจัดการก่อนเปิดขาย 5 พ.ย. ห้ามล้างเอง; ใบ `POB00120261028001` ถูกยกเลิกและกลับรายการครบ ยอดสุทธิเป็นศูนย์
- POS smoke: รอบแรกหยุดก่อนเปิดกะเพราะ plan เลือก `102397` ที่สต็อก 0; ไม่มี POS shift หรือบิลจากรอบนั้น หลังปรับ planner preflight ผ่านแล้ว แต่ยัง **ไม่ได้** ล็อกอิน/เปิดกะ/ขาย/นำเงินออก/ปิดกะ; เลขบิลและ server shift id จึงยังไม่มี
- ให้เจ้าของรันต่อใน Terminal เพื่อกรอก PIN เองแบบซ่อนตัวอักษร แล้วสคริปต์จะทำรายการขายจริง: `cd apps/pos-python && python3 -u e2e/pos_smoke_production.py --token-from-ssh --unit-coverage`; ห้ามใส่ PIN ในแชต/ไฟล์/log และห้ามล้างบิลหรือกะทดสอบเอง
- ทดสอบล่าสุด `PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests`: 244 tests ผ่าน; `--help`, AST parse และ `git diff --check` ผ่าน; ยังไม่ deploy

## Handoff - 2026-10-29 (Codex: ตรวจฟอร์ม/บันทึก และเร่ง toast)
- Commit: `f83c9d9`
- ทำอะไร: toast สำเร็จทั่วไปยังแสดงมุมขวาบน แต่ปิดใน 2.2 วินาที (เดิม 2.6–3 วินาที); ลดเวลา animation เข้า/ออกเหลือ 180 ms; error popup ยังคงแสดง 5.2 วินาทีเพื่อให้อ่านทัน
- ทดสอบ: `php -d memory_limit=512M vendor/bin/phpunit` ผ่าน 630 tests, 1 skipped, 6 incomplete, 4,376 assertions; `php artisan test --filter=RouteIntegrityTest` ผ่าน 3 tests / 932 assertions (หน้า GET และฟอร์ม literal Blade 139+ จุด); `DashboardLayoutTest` ผ่าน 12 tests / 44 assertions; `git diff --check` ผ่าน
- หมายเหตุ runner: `php artisan test` ใช้ subprocess ที่ยังจำกัด 128 MB และหยุดตอนสร้าง MIME ของ XLSX ใน `ReportExportTest`; รัน PHPUnit โดยตรงที่ 512 MB แล้วผ่านครบ
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้เดินคลิกทุกปุ่มใน browser ที่ล็อกอิน production; ขอเจ้าของล็อกอินใน Chrome ก่อนตรวจหน้าจอ งานบันทึกขาย/ซื้อ/รับชำระ/โอนที่มีผลต่อ stock หรือบัญชีจริงไม่ยิงซ้ำจาก browser; automated feature suite ครอบคลุม flow บันทึกบนฐานทดสอบ
- Deploy: ยังไม่ deploy
- งานถัดไป: ตรวจ production UI แบบอ่าน/validation เมื่อเจ้าของพร้อมล็อกอิน; หากต้องยิง save จริง ให้ใช้ staging/ฐานทดสอบ หรือกำหนดเอกสารทดสอบและการกลับรายการที่อนุมัติชัดเจน

## UAT - 2026-10-29 (Codex: production deployment และ POS preflight)
- Commit ที่ปล่อย: `11574f6`; GitHub Actions Deploy ERP production run `40869177182` ผ่านทั้ง test, build และ deploy
- `erp:health` หลังปล่อย: ฐานข้อมูล, migration, backup (16.7 ชั่วโมง), ขาย-GL, ตรวจนับค้าง, storage และ queue ผ่าน; failed queue 0
- POS read-only preflight: `POS-B001-01`, branch 34, catalog สินค้า 1,622; 109 หน่วยขาย/บาร์โค้ดทั้ง `CUSTOM`, `EAN13_STANDARD`, `SCALE_PLU` ตรงรหัสและราคากับ ERP; แผน 82 SKU ไม่เกิน 10 หน่วยฐานต่อ SKU
- `python3 -m unittest discover -s tests`: 244 ผ่าน
- สคริปต์ production POS หยุดที่ prompt PIN ของ B001 ตามข้อกำหนดให้เจ้าของกรอกเอง; ยังไม่เปิดกะ/สร้างบิล/นำเงินออก/ปิดกะ จึงไม่มีเลขกะหรือเลขบิลจากรอบนี้
- Browser UAT ยังรอเจ้าของล็อกอินในแท็บ ERP; ยังไม่ได้ตรวจคลิกปุ่มและฟอร์มบน production หลัง deploy
- ไม่ล้างข้อมูลหรือสต๊อก; เมื่อทำ POS UAT ต่อ ให้เก็บเลขบิล/กะทั้งหมดเพื่อกลับรายการและเคลียร์ภายหลังตามที่เจ้าของกำหนด

## Handoff - 2026-10-30
- Commit: `54cbed3`
- ทำอะไร: เพิ่มหน้าแก้ไขใบจองสำหรับรายการที่ยังไม่แปลง/ไม่เริ่มส่งของ โดยคงเลข BK เดิมและย้ายยอดจองสต๊อกแบบ transaction; เลือกลูกค้าแล้วดึงสายการขายตามข้อมูลลูกค้าได้และยังเลือก “ไม่ระบุ” ได้; แสดงเลขชุดใหม่ BK + สาขา + วันที่ + ลำดับให้ชัดเจน; เปลี่ยนคำเตือนตรวจฟอร์มรวมถึง “จำนวนจองเกินสินค้าพร้อมจอง” ให้เป็น toast มุมขวาบน
- ทดสอบ: `php -d memory_limit=512M vendor/bin/phpunit tests/Feature/BookingSalesAreaTest.php` ผ่าน 10 tests / 56 assertions; `php -d memory_limit=512M vendor/bin/phpunit` ผ่าน 635 tests (634 passed, 1 skipped), 4,407 assertions, incomplete 6; `vendor/bin/pint --dirty`, `git diff --check`, `node --check` สำหรับ JS ของฟอร์ม และตรวจ route แก้ไขผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ทดสอบคลิกบน production หลัง deploy; screenshot มีสินค้าคงเหลือพร้อมจอง 0.00 จึงบล็อกการจองอย่างถูกต้อง แม้ย้าย popup ไปมุมขวาบนแล้วก็ยังต้องมีสต๊อกพร้อมจองก่อนบันทึก; ไม่ได้สร้าง/แก้เอกสาร production
- Deploy: ยังไม่ deploy
- งานถัดไป: deploy ตามขั้นตอน `docs/OPERATIONS.md` เมื่อเจ้าของสั่ง แล้วตรวจหน้าใบจองจริงและตำแหน่ง toast บน production

## Handoff - 2026-10-30 (สิทธิ์ IT สำหรับทดสอบ POS)
- Commit: `dd0dd63`
- ทำอะไร: ตรวจหน้า “ผู้ใช้และสิทธิ์” บน production พบ role IT_MGR มี `pos.use`/`pos.void` แต่ไม่มี `pos.sell`/`pos.cash.manage`; เพิ่ม migration ให้ IT_MGR ใช้ POS flow ปกติได้: เปิดกะ/ขาย/ปิดกะและจัดการเงินลิ้นชัก โดยไม่เพิ่มสิทธิ์อนุมัติส่วนลดพิเศษหรือขายต่ำกว่าทุน
- ทดสอบ: `php -d memory_limit=512M vendor/bin/phpunit tests/Feature/ItPosUatAccessTest.php` ผ่าน 1 test / 10 assertions; `php -d memory_limit=512M vendor/bin/phpunit` ผ่าน 636 tests (635 passed, 1 skipped), 4,417 assertions, incomplete 6; `vendor/bin/pint --dirty` และ `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: migration ยังไม่รันบน production; หลัง deploy role IT_MGR จะขายได้บน POS ทุกสาขาที่อุปกรณ์/สต๊อก/กะพร้อม และจะต้องตั้ง PIN ด้วยตนเองก่อนล็อกอินแคชเชียร์; บิล POS ที่บันทึกจะลงยอดขาย/สต๊อก/บัญชีจริง
- Deploy: ยังไม่ deploy
- งานถัดไป: เจ้าของสั่ง deploy ก่อนจึงมีผลบน production; หลัง deploy ตรวจ role และให้เจ้าของตั้ง PIN เอง แล้วค่อยทดสอบด้วยเอกสารที่จดเลขไว้สำหรับกลับรายการ

## Handoff - 2026-10-30 (ตัวเลือกสินค้าแบบหน้าต่างค้นหากลาง)
- Commit implementation: `572929c`
- ทำอะไร: รวมการเลือกสินค้าลงเอกสารให้ใช้หน้าต่างค้นหากลางแบบหน้าใบขอซื้อ ค้นหารหัส/ชื่อ/บาร์โค้ด ดูหน่วยและราคา และเลือกด้วยเมาส์หรือปุ่มลูกศร/Enter; ใช้กับใบจอง ใบขาย ใบซื้อ ใบคืน ใบเสนอราคา งานโอน/เบิก/ปรับ/แปรรูป การผลิต OCR ตารางราคา Flash sale ป้ายราคา และแคมเปญโปรโมชั่น; เอารายการสินค้า preload 300/1000 รายการออกจากบางหน้าเพื่อให้ค้นหาได้ครบผ่าน endpoint เดิม
- ทดสอบ: `php -d memory_limit=1G vendor/bin/phpunit` ผ่าน 637 tests (636 passed, 1 skipped), 4,421 assertions, incomplete 6; regression tests ตัวเลือกแคมเปญ/การผลิตผ่าน; `php artisan view:clear`, `php artisan view:cache`, PHP lint, Pint และ `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้คลิกทดสอบ UI บน production และไม่ได้บันทึกเอกสารจริง; การค้นหาเรียก endpoint สินค้าเดิมและขึ้นกับสิทธิ์ที่หน้าเดิมกำหนด
- Deploy: ยังไม่ deploy
- งานถัดไป: push commit และรอเจ้าของสั่ง deploy ก่อนตรวจหน้าใช้งาน production

## Handoff - 2026-10-30 (แก้ปุ่มปิดหน้าต่างยกเลิกเอกสาร)
- Commit: `21a8078`
- ทำอะไร: แก้ backdrop โมดัลยกเลิกเอกสารให้ใช้ `d-flex` เฉพาะตอนเปิด เพื่อไม่ให้ Bootstrap บังคับแสดงทับคำสั่งซ่อนจาก Alpine; เพิ่ม regression test ตรวจคลาสและปุ่มปิด
- ทดสอบ: `php -d memory_limit=512M vendor/bin/phpunit tests/Feature/DocumentVoidTest.php` ผ่าน 9 tests / 52 assertions; `php -d memory_limit=1G vendor/bin/phpunit` ผ่าน 638 tests (637 passed, 1 skipped), 4,425 assertions, incomplete 6; `vendor/bin/pint --dirty` และ `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ไม่มีสำหรับการปิดโมดัล; ไม่ได้กดยกเลิกเอกสารและเอกสาร production ยังคงเดิม
- Deploy: GitHub Actions Deploy ERP run `40997413458` (#93) สำเร็จบน production ที่ commit `95a14f67f5765667f429ccb039593f395249d267`; test, asset build, deploy และ `php artisan erp:health` ผ่าน
- ตรวจหลัง deploy: เปิด `/sales/4` และทดสอบเปิดโมดัลแล้วกด “ปิด”; โมดัลหายไปและยังแสดงใบขาย `DSB00120261030001` ตามเดิม
- งานถัดไป: ไม่มี

## Handoff - 2026-10-30 (Codex: flow ใบจองส่งสินค้า → ใบขาย)
- Commit: `4efae2343e20561e05f448c9e3f5bd96a40c786b` (`Enforce booking delivery and sales flow`), push `main` สำเร็จ
- ทำอะไร: ใบส่งของชั่วคราวระบุชัดว่าเป็นเอกสารประกอบและไม่ลงขาย; ใบขึ้นของบันทึกจำนวนที่ตรวจจริงและบังคับให้ของครบตามใบจองก่อนยืนยันขึ้นรถ; หากของขาดยังแก้ใบจองได้ก่อนรถออก โดยล้างรายการขึ้นของเก่าและให้ตรวจใหม่; ลำดับบอร์ดเป็นขึ้นรถ → กำลังส่ง → ส่งครบ พร้อม audit log; ใบขายเชื่อของการส่งจะออกได้เมื่อใบขึ้นของครบและส่งจริงแล้วเท่านั้น ใช้วันที่ส่งจริงทั้งวันที่เอกสาร เลขที่ และวันครบกำหนดชำระ พร้อมปฏิเสธวันที่ในงวดบัญชีปิด
- ทดสอบ: `php -d memory_limit=1G vendor/bin/phpunit` ผ่าน 643 tests (642 ผ่าน, 1 skipped), 4,473 assertions, incomplete 6; ทดสอบเฉพาะ 3 ชุดใบจอง/ขนส่ง/ขายผ่าน 29 tests / 215 assertions; `CashTransferScreenTest` ผ่าน 5 tests / 26 assertions; `vendor/bin/pint --dirty`, `git diff --check`, `php artisan view:cache` ผ่าน
- ข้อจำกัด runner: `php -d memory_limit=1G artisan test` ในเครื่องหยุดที่ 128 MB ระหว่างตรวจ MIME ของ XLSX ใน `ReportExportTest`; รัน PHPUnit ตรงด้วย memory 1 GB แล้วผ่านครบ
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ทำ browser UAT บน production; ไม่มี migration และไม่มีการแก้ข้อมูลธุรกิจ production; สินค้าที่ขาดต้องปรับใบจองก่อนขึ้นรถ ส่วนการส่งบางส่วนยังไม่มี workflow แยกเที่ยวในระบบ
- Production preflight: GitHub Actions run `41000157976` ผ่าน; backup `erp-db-20261030-105715.sql.gz` (788K), PostgreSQL restore drill ผ่าน, `health=ok`, `migrations_pending=0`, counts ตรงกันหลัง restore
- Deploy: GitHub Actions `Deploy ERP` run `41000130298` สำเร็จบน production ที่ commit ข้างต้น; test, asset build, upload และ `scripts/deploy.sh` ผ่าน (รวม `php artisan erp:health`)
- งานถัดไป: ทำ browser UAT ตามขั้นตอนใบจอง/ขึ้นรถ/ส่งของบน production โดยไม่สร้างเอกสารจริง เว้นแต่เจ้าของสั่งทดสอบการบันทึก

## Handoff - 2026-10-30 (Codex: สคริปต์เติมสต๊อกสินค้าเพื่อ UAT POS)
- Commits: `2390013c444bbb76b71af2ac5154ddfb72cf82dd` (สคริปต์/เวิร์กโฟลว์) และ `a33c51e` (ปรับเพดานยอดให้เทียบความละเอียด 4 ตำแหน่งของเอกสาร); push แล้วที่ `origin/main`
- ทำอะไร: เพิ่ม Artisan command `erp:uat-receive-all-active-products` และ GitHub Actions `UAT receive all active products`; preview เป็นค่าเริ่มต้น เติมเฉพาะจำนวนที่ขาดให้สินค้าที่เปิดใช้ขายได้หนึ่งครั้งต่อหน่วยขายที่มี barcode โดยใช้ทุนรับเข้าล่าสุดหรือทุนเฉลี่ย และผู้ขาย UAT `UAT-POS-TEST`; บันทึกจริงผ่าน `PurchaseService` ปกติ จึงสร้างใบซื้อเชื่อ เพิ่มสต๊อก เปิด AP และลง GL
- การ์ดป้องกัน: โหมดจริงต้องระบุ admin ผู้บันทึก, เพดานยอดซื้อ, คำยืนยัน; ล็อกสาขาและตรวจ reference ซ้ำใน transaction; สินค้าไม่มีต้นทุนหรือขาด lot/วันหมดอายุหยุดทั้งชุด และปฏิเสธ lot หมดอายุ; ไม่มีการเพิ่มสิทธิ์ admin และไม่ได้ใช้ขั้นอนุมัติใบขอซื้อเพื่อเลี่ยงกฎ self-approval
- ทดสอบล่าสุด: `php -d memory_limit=1G vendor/bin/phpunit` ผ่าน 649 tests (648 ผ่าน, 1 skipped), 4,517 assertions, incomplete 6; test สคริปต์ 6 tests / 44 assertions; Pint, PHP lint, workflow YAML parse และ `git diff --check` ผ่าน
- Production preflight: run `41002024039` ผ่าน; backup `erp-db-20261030-111714.sql.gz` (792K), PostgreSQL restore drill ผ่าน, `health=ok`, `migrations_pending=0`; ก่อนรับเข้ามี products 4,057, documents 5, GL journals 11, stock balances 83, stock movements 85
- Deploy: command/workflow รุ่นเริ่มต้น deploy ผ่าน run `41001541659`; รุ่นแก้ precision deploy ผ่าน run `41002092081` (test, asset build, upload และ `scripts/deploy.sh` ผ่าน)
- Production preview: run `41002241005` ผ่านแบบ dry-run; B001 / `UAT-POS-TEST`, active products 1,623, ครบสต๊อกแล้ว 21, รับเพิ่ม 1,602 บรรทัด รวม 19,689 หน่วยฐาน, ประมาณใบซื้อเชื่อ/AP ฿818,160.88 (ยอดเอกสาร 818,160.8800); ไม่แจ้งต้นทุนหรือ lot metadata ขาด; preview ไม่เปลี่ยนข้อมูล
- รับจริง: ยังไม่ได้สร้างเอกสารและไม่มีเลขใบซื้อ เพราะ auto-review ปฏิเสธการสร้างใบซื้อเชื่อจริง ฿818,160.8800 สำหรับ 1,602 รายการ โดยให้ขออนุมัติจากเจ้าของสำหรับยอดและผลกระทบ stock/AP/GL ที่แน่นอนก่อน; ห้ามลองส่งซ้ำหรือเลี่ยง reviewer; reference ที่ตั้งใจใช้คือ `UAT-ALLSKU-B001-20261030`
- งานถัดไป: รอเจ้าของยืนยันยอดรับเข้าจริง; เมื่อได้รับอนุมัติแล้วให้ dispatch workflow `mode=receive` ด้วย reference และ cap ที่ยืนยัน แล้วบันทึกเลขใบซื้อ/ยอดรับจริงก่อนทำ UAT POS

## Handoff - 2026-10-30 (Codex: แก้หน้าต่างเลือกสินค้าถูกโมดัลบังและค้นหาหน่วง)
- Commit: `4046471` (`Fix shared product picker layering and search`)
- ทำอะไร: ย้ายหน้าต่างเลือกสินค้าแบบใช้ร่วมกันไปไว้ใต้ `body` ด้วย Alpine teleport และยกชั้นแสดงผลเหนือโมดัลเอกสาร; ลด debounce ค้นหาจาก 220 เป็น 100 ms และยกเลิกคำขอเก่า/ละทิ้งผลลัพธ์ที่มาช้า เพื่อไม่ให้ผลค้นหาล่าสุดถูกเขียนทับ
- ทดสอบ: `php artisan test --filter=ProductPickerSharedTest` ผ่าน 6 tests / 63 assertions; `php -d memory_limit=1G vendor/bin/phpunit` ผ่าน 650 tests (649 ผ่าน, 1 skipped), 4,532 assertions, incomplete 6; `vendor/bin/pint --dirty`, `php artisan view:clear`, `php artisan view:cache` และ `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ตรวจภาพใน browser หลังแก้; แท็บ Chrome ปัจจุบันอยู่ที่ใบปรับปรุงสต็อก production จึงไม่ได้คลิกหรือบันทึกข้อมูลจริง; ไม่มี migration และไม่ได้แก้ข้อมูล production
- Deploy: ยังไม่ deploy; การส่ง `Deploy ERP` ไป production ถูก auto-review ปฏิเสธเพราะเป็นการปล่อยโค้ดที่อาจกระทบระบบจริง และยังต้องการคำสั่งอนุมัติโดยตรงสำหรับการเปลี่ยนแปลงรอบนี้
- งานถัดไป: deploy เมื่อเจ้าของสั่ง แล้วตรวจเปิดตัวเลือกสินค้าจากใบจองและหน้าเอกสารอื่นว่าหน้าต่างอยู่ด้านหน้าและผลค้นหาตอบสนองทันที

## Handoff - 2026-10-30 (Codex: จัดหน้าใบส่งของให้เป็น A5 แนวนอนจริง)
- Commit: `ee5dbde` (`Fix delivery note A5 landscape layout`)
- ทำอะไร: แก้ CSS กลางที่บังคับหน้าตัวอย่างเป็น A4 แนวตั้ง ทั้งที่ `@page` กำหนด A5 landscape; แยกหน้าใบส่งของให้แสดงกรอบ 210×148 มม., ลดระยะ/ตัวอักษรให้ข้อมูลและยอดรวมพอดี และ override ขนาดจริงตอนพิมพ์
- ทดสอบ: `php artisan test --filter=PrintablePageSizeTest` ผ่าน 3 tests / 11 assertions; `php -d memory_limit=1G vendor/bin/phpunit` ผ่าน 650 tests (649 ผ่าน, 1 skipped), 4,540 assertions, incomplete 6; `vendor/bin/pint --dirty`, `php artisan view:clear`, `php artisan view:cache`, `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ไม่ได้ทดลองพิมพ์กับเครื่องพิมพ์จริง; ทดสอบขนาดจาก CSS และ regression test; ไม่ได้แก้หรือสร้างเอกสาร production
- Deploy: ยังไม่ deploy
- งานถัดไป: deploy เมื่อเจ้าของสั่ง แล้วตรวจตัวอย่างใบส่งของบน Chrome และในหน้าต่างพิมพ์ว่าเลือก A5 แนวนอนและยอดไม่ล้น

## Handoff - 2026-10-30 (Codex: รหัสผ่าน ERP ชั่วคราว 6 หลัก)
- Commit: `0ba7ff7` (`Use six-digit temporary ERP passwords`)
- ทำอะไร: เปลี่ยนรหัส ERP ชั่วคราวที่ออกตอนผู้ดูแลรีเซ็ตรหัส ให้สุ่มเลข 6 หลักด้วย `random_int`; รหัสยังเก็บเป็น hash, ไม่ลง audit log และบังคับผู้ใช้เปลี่ยนรหัสหลังเข้าใช้ครั้งแรก; PIN POS ไม่เปลี่ยน
- ทดสอบ: `php -d memory_limit=1G vendor/bin/phpunit` ผ่าน 650 tests (649 ผ่าน, 1 skipped), 4,538 assertions, incomplete 6; `vendor/bin/pint --dirty` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ไม่ได้รีเซ็ตรหัสบัญชี production; รหัส 6 หลักมีชุดค่าที่เป็นไปได้น้อยกว่ารหัสยาว แต่หน้า login จำกัดการลองผิด 5 ครั้งต่อนาทีต่อ username และ IP และบังคับเปลี่ยนเมื่อเข้าได้
- Deploy: GitHub Actions `Deploy ERP` production run `41004046336` สำเร็จบน `main` ที่ commit `fe7b6d35171f30f80c18916b1aac82dd33594f46`; test job และ deploy job ผ่าน รวม asset build, upload และ `scripts/deploy.sh`; health check หลัง deploy ผ่าน (migration up to date, backup 0.4 ชั่วโมง, storage ผ่าน, queue failed jobs 0)
- งานถัดไป: หากต้องการรหัสใหม่ ให้ผู้ดูแลกดรีเซ็ตรหัส ERP ของบัญชีเป้าหมายเองหลัง deploy; งานนี้ไม่ได้รีเซ็ตรหัสบัญชี production

## Handoff - 2026-10-30 (Codex: POS Central ยิงขายผ่าน ERP API)
- Commits: `fdafa60` (`Prove POS Central sales through ERP API`) และ `d8d4fcc` (`Add Windows UAT build for POS Central`)
- ทำอะไร: ตั้ง URL เริ่มต้นของ POS Central เป็น `https://erp.popstarcenter.com` และย้าย config เก่าที่ชี้ `http://localhost:8000` ไป URL บริษัทใน startup ปกติ; เพิ่ม E2E ที่ใช้ตัว UI checkout, local outbox และ SyncWorker ของ POS Central ยิงเข้า Laravel API พร้อม barcode UAT; เพิ่ม step นี้เข้า workflow POS E2E เดิม; เพิ่ม Windows UAT workflow ที่สร้าง ZIP artifact แยก ไม่ publish ทับ installer ใช้งานจริง
- ทดสอบ: POS Central `28 passed`; local Laravel + SQLite E2E ได้ใบเสร็จ `CSUAT20261030001`, สแกน `UAT-BC-0001`, แล้ว `uat:reconcile` ผ่าน GL/ต้นทุน/สต๊อก; POS Python E2E เดิมผ่าน 10/10 เงื่อนไขและได้ reconcile ผ่านหลัง candidate; PHPUnit `651 tests` (650 ผ่าน, 1 skipped), 4,543 assertions, incomplete 6; workflow YAML, PHP lint และ `git diff --check` ผ่าน
- ข้อมูล/ความเสี่ยง: การขายทดสอบทั้งหมดอยู่ใน SQLite ชั่วคราว ไม่มีบิลหรือกะทดสอบถูกสร้างใน production; ยังไม่ได้ deploy หรือเปลี่ยน installer/release ซึ่งยังชี้ `apps/pos-python`; candidate ยังไม่มี migration คิว/กะออฟไลน์จาก POS หลักและยัง sync event น้อยกว่า จึงใช้เป็นตัวทดสอบแยกก่อน
- งานถัดไป: ให้ Windows UAT workflow build ZIP artifact แล้วเจ้าของเปิด POS Central และกรอก Device Token/PIN เองเพื่อทดลองขายผ่าน ERP ตามแผน UAT; ยังไม่สลับ installer ใช้งานจริงจนกว่าจะทบทวนข้อมูลค้างและส่วนต่าง flow

## Handoff - 2026-10-30 (Codex: สิทธิ์ทุกสาขาใน POS Workbench)
- Commit: `479962e` (`Honor all-branch access in POS workbench`)
- ทำอะไร: หน้าเครื่องมือ POS ใช้ `reports.all_branches` ปลดล็อกตัวเลือกสาขาและเปิดดูทุกสาขาเป็นค่าเริ่มต้นสำหรับผู้มีสิทธิ์ (รวม IT/GM); ผู้ใช้สาขายังถูกล็อกสาขาเดิม และผู้ใช้ที่ไม่กำหนดสาขา/ไม่มีสิทธิ์จะไม่เห็นบิลข้ามสาขา
- ทดสอบ: `php -d memory_limit=1G vendor/bin/phpunit` ผ่าน 655 tests (654 passed, 1 skipped), 4,585 assertions, incomplete 6; `vendor/bin/pint --dirty`, PHP lint และ `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ตรวจหน้า production หลัง deploy; ทดสอบ role scope และการกรองสาขาบน SQLite tests เท่านั้น; ไม่ได้แก้ข้อมูล production
- Deploy: ยังไม่ deploy
- งานถัดไป: deploy ตาม `docs/OPERATIONS.md` เมื่อเจ้าของสั่ง แล้วเปิด POS Workbench ด้วยบัญชี IT เพื่อตรวจว่าตัวเลือก “ทุกสาขา” แสดงบิลจากสาขาต่าง ๆ

## Handoff - 2026-10-31 (Codex: Dashboard รวมยอดและจำกัดตามสิทธิ์สาขา)
- Commit: `bcbfea3` (`Fix dashboard branch visibility`)
- ทำอะไร: Dashboard ใช้ `reports.all_branches` แสดงยอดรวมบริษัทและยอดแยกสาขา; ผู้จัดการหลายสาขาเห็นยอดรวมเฉพาะสาขาที่เลือกใน `user_branch_roles`; ผู้ไม่มี `reports.view` เห็นเพียงโครงหน้า ไม่มีข้อมูลธุรกิจ; จำกัดยอดขาย POS ลูกหนี้ จัดซื้อ และคลังตามขอบเขตเดียวกัน; ปรับคำอธิบายขอบเขตบน Dashboard และช่องกำหนดสาขา
- ทดสอบ: `php -d memory_limit=512M vendor/bin/phpunit` ผ่าน 673 tests (672 passed, 1 skipped), 5,716 assertions, incomplete 6; `DashboardLayoutTest` ผ่าน 15 tests / 60 assertions; `vendor/bin/pint --dirty`, `php artisan view:cache` และ `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: `php artisan erp:health` ในเครื่อง dev รายงาน migration ค้าง 25 รายการและไม่พบไฟล์ backup; ไม่ได้รัน migration หรือแตะข้อมูล production; ยังไม่ได้ยืนยันภาพ Dashboard บน production หลังแก้
- Deploy: ยังไม่ deploy
- งานถัดไป: deploy ตาม `docs/OPERATIONS.md` เมื่อเจ้าของสั่ง แล้วตรวจด้วยบัญชี IT/GM, ผู้จัดการหลายสาขา และบัญชีที่ไม่มี `reports.view` ว่ายอดรวมและการจำกัดสาขาตรงตามสิทธิ์

## Handoff - 2026-10-31 (Claude: แก้ผลตรวจ Dashboard และขอบเขตสาขา)
- ทำอะไร: สต๊อกบน Dashboard ใช้เจ้าของคลังจาก `default_warehouse_location_id` เมื่อ `warehouses.branch_id` เป็น NULL; ทุกสาขาต้องมาจาก global `reports.all_branches` หรือ system admin (`canSeeAllReportBranches()`); รายงาน, Executive Dashboard และ POS Workbench ใช้สาขาที่มอบหมายจริงผ่าน `resolveReportBranch()` เลือกได้ทีละสาขาในขอบเขตสิทธิ์
- ทดสอบ: `php -d memory_limit=512M vendor/bin/phpunit` ผ่าน 678 tests (677 passed, 1 skipped), incomplete 6; ถอด scope สต๊อกแล้วเทสต์แดงจริง; `P0ReportFiguresTest` เลิกใช้ static user ข้ามเทสต์
- ยังไม่ทดสอบ: PostgreSQL จริงและหน้าจอ production; `addBranchRoles()` ยังไม่ได้แก้เรื่อง role เก่าในสาขาเสริม

## Handoff - 2026-10-31 (Claude: เปลี่ยนคนประจำเครื่องและย้ายสาขาจากหน้าเว็บ)
- ทำอะไร: `/pos-admin` เพิ่ม "เปลี่ยนคน" ต่อเครื่อง (ไม่ต้องออก Token ใหม่) และ "ย้ายสาขา" ต่อคนขาย ทั้งสองอย่างกันกะที่เปิดอยู่และบันทึก audit
- ทดสอบ: phpunit เต็มชุดผ่าน; `PosAdminReassignTest` 3 tests; ยังไม่ได้ทดสอบกับเครื่อง POS จริง
- Deploy: รอเจ้าของสั่ง

## Handoff - 2026-10-31 (Claude: คนขายเข้าเครื่องไหนในสาขาก็ได้)
- ทำอะไร: Device Token ระบุเครื่อง/สาขาเท่านั้น; `/api/pos/cashiers` ส่งทุกคนที่มี `pos.sell` ในสาขาของเครื่อง และ user ที่ผูกตอนออก Token ยังเข้าได้; `pull_cashiers` เลิกกรองตาม `device_user_id`
- ทดสอบ: phpunit ผ่าน 681 tests (680 passed, 1 skipped), incomplete 6; pos-python unit tests ผ่าน; ยังไม่ได้ทดสอบเครื่องจริง
- ความเสี่ยง: PIN ซ้ำในสาขาจะให้เลือกชื่อ; เครื่องต้องอัปเดตแอปถึงจะเห็นทุกคน
- Deploy: รอเจ้าของสั่ง พร้อมออกตัวติดตั้ง POS ใหม่

## Handoff - 2026-10-31 (Claude: POS ถาม PIN ตอนเลือกคนขาย)
- ปัญหา: แอปส่งเลือกชื่อโดยไม่มี PIN แต่ production ตั้ง `pos_passwordless_login=0` จึงได้ 422 "กรุณาระบุ PIN"
- แก้: หน้าเริ่มขายถาม PIN ตาม `cashier_login_mode` ทั้งออนไลน์และออฟไลน์
- ทดสอบ: pos-python unit tests ผ่าน; ยังไม่ได้ทดสอบหน้าจอจริง

## Handoff - 2026-10-31 (Claude: เครื่องไม่ผูกคนขายจากมุมผู้ดูแล)
- ทำอะไร: เพิ่มเครื่องได้โดยใช้ user ภายในซ้ำได้; เอาปุ่มเปลี่ยนคนออก; ย้ายสาขาแล้วเครื่องสาขาเดิมเปลี่ยน user ภายในอัตโนมัติ; POS 0.7.4 บังคับตั้ง PIN ใหม่เมื่อเป็น PIN ชั่วคราว
- ทดสอบ: phpunit ผ่าน 679 tests (678 passed, 1 skipped), incomplete 6; pos-python unit tests ผ่าน; ยังไม่ได้ทดสอบหน้าจอจริง
- งานถัดไป: รอรายชื่อพนักงานรายคน

## Handoff - 2026-10-31 (Claude: ค้นสินค้าด้วยบาร์โค้ด)
- ทำอะไร: เพิ่มบาร์โค้ดในค้นหา Flash Sale, ตารางราคา, ป้ายราคา และ grid POS Python; ต่อจาก `a110746` ของหน้า products
- ทดสอบ: phpunit และ pos-python unit tests ผ่าน; หน้า ERP ที่ใช้ `ilike` ไม่มีเทสต์ SQLite ครอบคลุม
- Deploy: รอเจ้าของสั่ง พร้อม POS รุ่นถัดไป

## Handoff - 2026-10-31 (Claude: ตั้งค่า POS ไม่ใช้รหัส, รายชื่อคนขาย, อนุมัติด้วย PIN หัวหน้า)
- ทำอะไร: เจ้าของสั่งเปิดตั้งค่า POS โดยไม่ใช้ PIN; หน้าเปิดกะแสดง `is_seller` (CASHIER/SALES/BRANCH_MGR); ส่วนลด/แก้ราคาให้หัวหน้าเลือกชื่อใส่ PIN ผ่าน `/api/pos/approver/verify` หรือ credential ออฟไลน์ แทน Local IT PIN
- ทดสอบ: phpunit ผ่าน 681 tests (680 passed, 1 skipped), incomplete 6; pos-python unit tests ผ่าน; ยังไม่ได้ทดสอบหน้าจอจริง
- ความเสี่ยง: ใครที่หน้าเครื่องแก้ Token/ตั้งค่าได้; ต้อง deploy ERP พร้อม POS รุ่นใหม่

## Handoff - 2026-10-31 (Claude: ตั้ง PIN หลังบ้าน และค้นสินค้าที่ POS)
- ทำอะไร: `/pos-admin` ให้ผู้ดูแลตั้ง PIN 4 หลักที่ใช้ได้ทันที; POS Enter แล้วค้นชื่อ/รหัส/บาร์โค้ด เจอรายการเดียวเพิ่มเข้าบิล; เลขอารบิกและถามน้ำหนักเฉพาะสินค้าที่ไม่มีป้ายชั่ง
- ทดสอบ: phpunit ผ่าน 682 tests (681 passed, 1 skipped), incomplete 6; pos-python unit tests ผ่าน; ยังไม่ได้ทดสอบเครื่องจริง

## Handoff - 2026-10-31 (Claude: POS เงินทอน, EAN-13, ส่วนลด)
- ทำอะไร: ย่อหน้าต่างเงินทอน; สแกน 12 หลักหา EAN-13 ที่มี 0 นำหน้าและส่งบาร์โค้ดจริงขึ้น ERP; เจ้าของสั่งให้คนขายใส่ส่วนลดเองโดยไม่ต้องให้หัวหน้าอนุมัติ
- ทดสอบ: pos-python unit tests ผ่าน; ยังไม่ได้ทดสอบเครื่องจริง
- Deploy: รอเจ้าของออกรุ่น 0.7.7

## Handoff - 2026-10-31 (Claude: ใบเสร็จ)
- ทำอะไร: พิมพ์ Windows queue ด้วย QPainter ตัวหนา Tahoma และวัดความกว้างจริง แทน Courier ที่ไม่มีอักษรไทย
- ทดสอบ: pos-python unit tests ผ่าน; ยังไม่ได้พิมพ์จริง
- Deploy: รอเจ้าของออกรุ่น POS ถัดไป

## Handoff - 2026-10-31 (Claude: เปิดกะด้วย PIN อย่างเดียว)
- ทำอะไร: POS โหมด PIN ถามแค่ PIN แล้วรู้ตัวคนขาย; PIN ซ้ำออนไลน์ให้เลือกชื่อ, ออฟไลน์ปฏิเสธ; `/pos-admin` กันตั้ง PIN ซ้ำในสาขา
- ทดสอบ: phpunit ผ่าน 682 tests (681 passed, 1 skipped), incomplete 6; pos-python unit tests ผ่าน; ยังไม่ได้ทดสอบเครื่องจริง

## Handoff - 2026-10-31 (Codex: รับสต๊อก UAT จำนวนคงที่ต่อสินค้า)
- Commit: `b77fac7` (`Allow fixed-quantity UAT stock receipts`)
- ทำอะไร: เพิ่ม `--qty-each` ในคำสั่งและ GitHub Actions UAT ให้ preview/รับเพิ่มจำนวนหน่วยฐานที่ระบุต่อสินค้า active แต่ละรายการ; ค่าเริ่มต้นเดิมยังเติมเฉพาะจำนวนขาด; โหมดบันทึกจริงยังคงต้องใช้ actor, เพดานยอด, คำยืนยัน และ lot/วันหมดอายุครบ
- ทดสอบ: `php artisan test --filter=UatReceiveAllActiveProductsTest` ผ่าน 9 tests / 61 assertions; PHPUnit เต็มผ่าน 685 tests, 5,788 assertions (1 skipped, 6 incomplete); POS Python 253 tests ผ่าน; Pint, YAML parse และ `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้บันทึกรับสินค้าจริง; preview เป็นใบซื้อเชื่อและจะเพิ่มเจ้าหนี้/GL ตามยอดทุนที่คำนวณได้ จึงยังต้องกำหนดเพดานยอดก่อน execute
- Deploy: GitHub Actions `Deploy ERP` run `41192385887` (#110) ผ่านบน production, commit `4a4494e638e4ba85ab7f31febf47ba331877cc48`; health ผ่านครบ (DB, migration, backup 3.3 ชม., sales-GL, stock count, storage, queue)
- Production preview: GitHub Actions `UAT receive all active products` run `41192596510` (#3) ผ่านแบบ dry-run; B001 / `UAT-POS-TEST`, เพิ่ม 10 หน่วยฐานให้ active SKU 1,623 รายการ รวม 16,230 หน่วย; ปัจจุบันมีของขายได้รวม 794 หน่วย; ประมาณยอดใบซื้อเชื่อ/AP ฿1,506,995.30; ไม่พบ warning ต้นทุนหรือ lot metadata ขาด; preview ไม่เปลี่ยนสต๊อก

## Handoff - 2026-10-31 (Codex: เตรียมปรับสินค้า 102204 เป็น 20 แพ็คที่ B001)
- Commit: โค้ดฐาน `bac7cca`; รอบนี้เปลี่ยนข้อมูลผ่าน production UI และบันทึกผลเท่านั้น ไม่มีการแก้โค้ดระบบ
- คำสั่งเจ้าของ: สาขา B001 อำนาจเจริญ, ยอดคงเหลือสุดท้าย 20 แพ็ค และอนุญาตให้เปิดใช้งานสินค้า 102204 เพื่อทดลองขาย
- ทำอะไร: เปิดใช้งานสินค้า `102204` (product ID `35441`, หมูเด้ง TKF แพ็ค 500กรัม (หิ้ว5กก.)); ยืนยันรหัส/บาร์โค้ด `102204`, `8859999010239` และราคาขายเริ่มต้น ฿70.00 คงเดิม; สร้างใบปรับสต๊อก `AJB00120261031003` (document ID `56`) ผ่านบัญชีเว็บที่เปิดอยู่ สถานะ `pending_approval`, คลัง MAIN พื้นที่หลัก - สาขาอำนาจเจริญ, ยอดระบบ 0 → ยอดนับ 20 แพ็ค, ผลต่าง +20
- ตรวจจริง: หน้าสินค้าหลัง reload แสดงใช้งาน 1 / ปิดใช้งาน 0; หน้าเอกสารแสดงสินค้า/สาขา/คลัง/จำนวนถูกต้องและข้อความส่งรออนุมัติสำเร็จ; ตรวจใบปรับก่อนหน้าแล้วเป็นสินค้าอื่น ไม่ใช่ 102204; `git diff --check` ผ่านสำหรับบันทึกนี้
- ยังไม่เสร็จ/งานถัดไป: สต๊อกจริงยังไม่เพิ่มจนกว่าผู้มีสิทธิ์อีกคนอนุมัติที่ `https://erp.popstarcenter.com/stock-adjustments/56`; บัญชีผู้สร้างไม่มีปุ่มอนุมัติเอกสารตนเองตาม maker-checker ใน `docs/OPERATIONS.md`; ไม่ได้สลับบัญชีหรือข้ามขั้นตอนอนุมัติ และไม่ได้รับสต๊อกทั้งแค็ตตาล็อกจริง
- Deploy: ไม่ต้อง deploy (ไม่มีโค้ดระบบเปลี่ยน)

## Handoff - 2026-10-31 (Codex: รายงานคลังค้นหาสินค้าครบทุกหน้า)
- Commit: `a07d9f6` (`Make inventory report searchable across the full product catalogue`)
- สาเหตุที่ยืนยัน: หน้ารายงานเดิมส่งมาแค่ 25 แถว ไม่มี pagination จริง, input ไม่มี auto-submit และสินค้าที่ไม่มี `stock_balances` ถูก inner join ตัดออก; production ส่งคำค้น 102204 แล้วได้ 0 รายการ แม้ทะเบียนสินค้าค้นพบ
- ทำอะไร: รายงานสินค้าคงเหลือใช้ทะเบียนสินค้าครบต่อที่เก็บ รวมยอด 0 โดยไม่เขียนแถวสต๊อก, total ก่อนแบ่งหน้า, ปุ่มเปลี่ยนหน้า, ค้นชื่อ/รหัส/บาร์โค้ดบน server ก่อนแบ่งหน้าและแสดงบาร์โค้ด/หน่วย; ค้นอัตโนมัติ 500 ms ผ่าน fetch คงเคอร์เซอร์และกันผลเก่าทับ; ยังคุมสิทธิ์สาขา; แสดงปริมาณ 8 ตำแหน่งตามระบบ และส่งออก XLSX เป็นตัวเลขโดยเก็บบาร์โค้ดเป็นข้อความ; ปุ่ม Excel ส่ง XLSX จริงและปุ่มพิมพ์ขอชุดสำหรับพิมพ์จาก server
- ทดสอบ: PHPUnit เต็ม 694 tests / 5,895 assertions ผ่านโดยไม่มี failure (1 skipped, 6 incomplete เดิม); ชุดเจาะจง Report/Export/Governance รอบสุดท้าย 22 tests / 180 assertions ผ่าน; StockBalanceReportTest 8 tests / 107 assertions; Node report-search 5 tests ผ่าน (debounce, cursor, stale response, IME, retry, pagination); Pint, PHP/JS syntax, workflow YAML, `git diff --check` ผ่าน; ถอด controller ที่แก้ออกแล้ว regression test รายการนอกหน้าแรกล้มเหลวจริง จากนั้นคืนโค้ดแก้กลับ
- Deploy: กำลังเตรียม deploy ตามคำสั่งเจ้าของเดิม "ถ้าผ่านก็ deploy เลย"; รอ PostgreSQL CI, backup/restore check และตรวจหน้าจอจริงหลัง deploy
- ขอบเขต/ความเสี่ยง: ไม่มี migration และไม่มีการแก้รหัส/บาร์โค้ด/ราคา/ต้นทุน/สต๊อก/GL; ใบปรับ 102204 `AJB00120261031003` ยังรออนุมัติอยู่ ณ การสร้างเอกสารรอบก่อน รายงานนี้ไม่ทำให้ยอดเพิ่มเอง; รายงานคงเหลือยังอ่านยอดปัจจุบันตามเดิม

## Handoff - 2026-10-31 (Claude: IT สิทธิ์เต็ม)
- ทำอะไร: เจ้าของสั่งให้ผู้ถือ users.manage + settings.manage แบบ global ผ่านทุกสิทธิ์ รวมสิทธิ์ควบคุมภายในเดิม; กติกาผู้สร้างอนุมัติเอกสารตัวเองไม่ได้ยังอยู่
- ความเสี่ยง: GM และ IT_MGR ได้ผลเหมือนกัน; deploy รอเจ้าของ

## Handoff - 2026-10-31 (Claude: IT อนุมัติเอกสารตัวเองได้)
- ทำอะไร: เจ้าของสั่งให้ `system.admin` ข้ามกฎผู้สร้าง/ผู้ส่งอนุมัติเองและข้ามสาขาในการอนุมัติ
- ทดสอบ: phpunit ผ่าน; ยังไม่มีเทสต์เฉพาะแต่ละหน้าอนุมัติ
- Deploy: รอเจ้าของ
# Handoff — 2026-11-01 (ลด query ที่ทำให้ Dashboard และสินค้าโหลดช้า)

## Commit
ยังไม่ commit — เครื่องมือถูกจำกัดสิทธิ์เขียนใน `.git`

## ทำอะไรไป
- กรองล็อตแจ้งเตือนหมดอายุใน SQL และจำกัดผลลัพธ์ 20 รายการ แทนการดึงล็อตทั้งหมดมากรองใน PHP
- เปลี่ยนการหา PLU สูงสุดของสินค้าเป็น query เรียงลำดับและอ่านเพียงค่าเดียว แทนการดึง barcode ทั้งชุดขึ้นหน่วยความจำ

## ทดสอบไปแล้วแค่ไหน
- ชุดเฉพาะ Dashboard/สินค้า/รายงานสต๊อก: 41 tests, 272 assertions ผ่าน
- PHPUnit ทั้งชุด: 694 tests, 693 ผ่าน, 1 skipped, 6 incomplete, 5,895 assertions
- `git diff --check` และ PHP syntax check ผ่าน

## ความเสี่ยง/งานถัดไป
- ยังไม่ได้ deploy และยังไม่ได้วัดเวลาบน PostgreSQL production
- ควรเก็บ URL ที่ผู้ใช้ยังรู้สึกช้าเพื่อวัด query plan ต่อเป็นรายหน้า

# Handoff — 2026-11-01 (นโยบายขายติดลบทุกสินค้าและรายหมวด)
- Commit: ยังไม่ commit
- ทำอะไร: เพิ่มค่ากลางอนุญาต/ห้ามขายติดลบ, ข้อยกเว้นรายประเภทสินค้า และคงนโยบายรายสาขา/รายสินค้าเดิม; ต่อ resolver เข้าขายสดและแปลงใบจองเป็นใบขายเชื่อ; หน้าตั้งค่าเดิมแสดงปุ่มบันทึกเฉพาะผู้ถือบทบาท `IT_MGR` และ backend ปฏิเสธผู้ใช้อื่นด้วย 403
- ทดสอบ: `php artisan test --filter='NegativeStockPolicyTest|CreditSale'` ผ่าน 10 tests / 48 assertions; PHP syntax ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้รัน PHPUnit ทั้งชุด และยังไม่ได้ deploy; ควรทดสอบกับ PostgreSQL/ข้อมูลจริงก่อนเปิดใช้
- Deploy: ยังไม่ deploy
- งานถัดไป: ตรวจหน้า settings ด้วยบัญชี IT และบัญชีทั่วไป แล้วค่อย commit/push/deploy เมื่อเจ้าของสั่ง

# Handoff — 2026-11-01 (แก้ PostgreSQL UAT ชน memory limit)
- Commit: ยังไม่ commit
- ทำอะไร: ปรับ PostgreSQL UAT ให้รัน PHPUnit ด้วย `memory_limit=1G`; log เดิมจบด้วย `Premature end of PHP process` ระหว่าง `BookingDeliveryDueTest` ก่อนถึง assertion failure
- ทดสอบ: ยังรอ GitHub Actions PostgreSQL UAT รอบใหม่
- Deploy: รอบก่อนยังไม่ deploy เพราะ UAT run `41282634443` ล้มเหลว
- งานถัดไป: push workflow fix แล้วรอ UAT และ Deploy ERP ใหม่
# Handoff — 2026-11-01 (แก้เส้นทางใบรวมจองซ้ำ)

## Commit
- รอ commit หลัง push งานรอบนี้

## ทำอะไรไป
- ลบ route `GET /bookings/summary` ที่ประกาศซ้ำ ทำให้หน้ารายงานใบรวมจองมี route เดียวและไม่เกิดความกำกวมตอน resolve route
- ตรวจระบบ B Plus ผ่าน AnyDesk แบบอ่านอย่างเดียว ยืนยันโครงสร้างใบจอง → ใบส่งของชั่วคราว → DS/DSN และพบว่าระบบ ERP มีเล่ม DS/DSN กับ `no_vat` อยู่แล้ว จึงไม่บล็อกข้อมูลเก่าที่สินค้ายังไม่มีการกำหนดภาษีรายสินค้า

## ทดสอบไปแล้วแค่ไหน
- `php artisan test --filter='Booking|QuotationConversion|NoVatBook'`: 42 tests / 213 assertions ผ่าน
- `git diff --check` ผ่าน
- Full `php artisan test` ในเครื่อง dev ยังชน memory limit 128MB ที่ `ReportExportTest` ระหว่างตรวจ MIME ของ XLSX; ไม่ใช่ failure จากการแก้รอบนี้

## ความเสี่ยง/งานถัดไป
- งานนี้ยังไม่เพิ่มการแยกรายการ DS/DSN อัตโนมัติในใบเดียวกัน; ปัจจุบันเลือกเล่ม DS/DSN ตอนแปลงใบจองได้ และ migration `no_vat` มีอยู่แล้ว
- ต้องรอ workflow PostgreSQL UAT และ production check ก่อนถือว่า deploy สำเร็จ
# Handoff — 2026-11-01 (มาตรฐานรหัส POS และแบรนด์เหมียวฟู้ด)

## Commit
ยังไม่ commit

## ทำอะไรไป
- เพิ่ม migration มาตรฐานเล่มขายสดของสาขาเดียวจาก `PS07` เป็น `PS01` เฉพาะเมื่อไม่มี `PS01` อยู่แล้ว และไม่แตะเอกสารย้อนหลัง
- เปลี่ยนค่า fallback ชื่อบริษัทไทย/อังกฤษเป็น `เหมียวฟู๊ด` / `Meaw Foods`
- ยังไม่ได้ลบข้อมูลบริษัทหรือธุรกรรมเก่า เพราะเสี่ยงทำลายประวัติเอกสารและบัญชี

## ทดสอบไปแล้วแค่ไหน
- `php -d memory_limit=1G vendor/bin/phpunit` — 698 tests, 697 passed, 1 skipped, 6 incomplete, 5903 assertions
- `git diff --check` และ `php -l` migration — ผ่าน

## ความเสี่ยง/งานถัดไป
- หาก production มีทั้ง `PS01` และ `PS07` migration จะไม่ merge ให้อัตโนมัติ ต้องตรวจเอกสาร/เครื่อง POS ก่อน
- งานตั้งค่าให้ครบทุกโมดูล, alert ทุกเมนู และมาตรฐาน auto-code ต้องแตกเป็น backlog รายโมดูล ไม่ควรแก้แบบลบข้อมูลทั้งระบบในครั้งเดียว
- Deploy รอเจ้าของยืนยันผลตรวจ migration บน production

# Handoff — 2026-11-01 (เช็กราคาในหน้า POS Preview)

## Commit
รอ commit หลังทดสอบ

## ทำอะไรไป
- เพิ่มปุ่ม `เช็กราคา` ในหน้า `/pos/preview`
- เพิ่ม modal ค้นหาด้วยชื่อ/SKU หรือยิง barcode จากเครื่องสแกนแล้วกด Enter
- เรียก catalog endpoint แบบ GET เพื่อใช้ราคาปัจจุบัน/ราคาตามช่วงเวลา/โปรโมชั่นที่ backend คำนวณอยู่แล้ว
- โหมดนี้อ่านอย่างเดียว ไม่เปิดกะ ไม่สร้างบิล และไม่ตัดสต๊อก

## ทดสอบไปแล้วแค่ไหน
- `php artisan test tests/Feature/PosWebModeTest.php --compact` — 6 tests, 33 assertions ผ่าน
- `git diff --check` — ผ่าน

## ความเสี่ยง/งานถัดไป
- หน้า Preview ยังเป็นหน้าตัวอย่าง ไม่ใช่หน้าขายจริง; ปุ่มเช็กราคาจึงไม่เพิ่มสินค้าเข้าตะกร้า
- ราคาสาขาจะถูกบังคับตาม branch ของผู้ใช้ที่ล็อกอิน หากไม่มี branch จะใช้ราคากลาง/ราคาสินค้า
- ยังไม่ได้ deploy เพราะคำขอล่าสุดไม่ได้สั่ง deploy
# Handoff — 2026-11-01 (แสดงเวลาใบขายสด)

## Commit
- `08ca0fb` — Add MPM Food Group logo preset

## ทำอะไรไป
- เพิ่มคอลัมน์เวลาในรายการใบขายสด โดยใช้ `created_at` และแสดงรูปแบบ `HH:mm:ss`
- ปรับ colspan ของสถานะไม่มีข้อมูลให้ตรงกับจำนวนคอลัมน์ใหม่

## ทดสอบไปแล้วแค่ไหน
- `php -d memory_limit=1G artisan test tests/Feature/SalesDocumentEndToEndTest.php --compact` — 7 tests, 86 assertions ผ่าน
- `php -l app/Http/Controllers/CashSaleController.php` และ `git diff --check` ผ่าน

## ความเสี่ยง/งานถัดไป
- ยังไม่ได้ลบข้อมูล เพราะ `CASH_SALE` หลังบ้านผูกกับสต๊อก/บัญชี และยังไม่มีตัวบ่งชี้ว่าเอกสารมาจาก Python หรือ Vue โดยตรง
- ต้อง backup และทำ dry-run ระบุช่วงเลขที่/เวลา/เครื่องก่อนลบจริง
- ยังไม่ได้ deploy การเปลี่ยนแปลงนี้

# Handoff — 2026-11-01 (เพิ่มโลโก้ MPM Food Group)

## Commit
- รอ commit หลังตรวจงาน

## ทำอะไรไป
- เพิ่มไฟล์โลโก้จริง `public/images/logos/mpm-food-group.jpg` เป็น preset ในหน้าตั้งค่าโลโก้ระบบ
- ระบบจะค้นหาไฟล์ในโฟลเดอร์ preset อัตโนมัติ จึงเลือกใช้ได้จากหน้าตั้งค่าโดยไม่ต้องแก้โค้ดเพิ่ม
- ยังไม่เปลี่ยนชื่อบริษัท เลขภาษี หรือที่อยู่ เพราะโลโก้เพียงอย่างเดียวไม่ยืนยันข้อมูลนิติบุคคล

## ทดสอบไปแล้วแค่ไหน
- ตรวจไฟล์ JPEG สำเร็จ ขนาด 1254x1254
- `git diff --check` ผ่าน
- Deploy workflow `41320929936` ผ่านทั้ง test และ production deploy

## ความเสี่ยง/งานถัดไป
- ต้องเลือกโลโก้นี้ใน Settings หรือกำหนดค่า `logo_path` หลัง deploy จึงจะแสดงทั่วระบบ
- หากต้องเปลี่ยนชื่อบริษัทบนเอกสาร ต้องยืนยันชื่อไทย/อังกฤษ เลขภาษี และที่อยู่ก่อนแก้ข้อมูลกิจการ

# Handoff — 2026-11-01 (วาง installer Vue/Tauri ในหน้า POS Admin)

## Commit
- `7e378bc` — Align POS installer fallback and hidden web menu

## ทำอะไรไป
- เพิ่มลิงก์ดาวน์โหลด Vue/Tauri installer รุ่นล่าสุดไว้ข้าง Python installer ในหน้า `/pos-admin`
- เปลี่ยน `/download/pos` ให้ดาวน์โหลดไฟล์ Vue/Tauri จาก manifest ล่าสุดจริง
- ซ่อนเมนู `เปิด POS ขาย` ของ Web POS จาก navigation โดยไม่ลบ route/โค้ดเดิม
- คง Python installer ไว้สำหรับเครื่องเดิมและการ rollback

## ทดสอบไปแล้วแค่ไหน
- GitHub Actions Vue/Tauri release `41319998812`: checks, Windows native build และ publish ผ่านทั้งหมด
- PHP lint ผ่าน
- `AppLauncherTest` และ `PosWorkbenchTest`: 14 tests, 80 assertions ผ่าน
- Deploy workflow `41321568368` ผ่านทั้ง test และ production deploy

## ความเสี่ยง/งานถัดไป
- Web POS ยังเปิดได้จาก URL โดยตรง แต่ไม่แสดงในเมนู
- ต้องทดสอบดาวน์โหลดจากบัญชี IT หลัง deploy และติดตั้งบน Windows จริง

# Handoff — 2026-11-01 (แก้การอัปโหลดโลโก้)

## Commit
- รอ commit หลังทดสอบ

## ทำอะไรไป
- ทำให้ฟอร์มอัปโหลดโลโก้คงแท็บโลโก้ไว้เมื่อ validation ไม่ผ่าน เพื่อเห็นข้อความผิดพลาดทันที
- เปลี่ยน validation ให้รองรับไฟล์ `png`, `jpg`, `jpeg`, `webp` และ `svg` ตามตัวเลือกหน้าเว็บ
- ตรวจไฟล์อัปโหลดที่เสียหายก่อนบันทึก และสร้างโฟลเดอร์ปลายทางอัตโนมัติ
- ใช้ชื่อไฟล์แบบไม่ซ้ำ เพื่อไม่ให้การอัปโหลดซ้ำทับไฟล์เดิม

## ทดสอบไปแล้วแค่ไหน
- `PosInstallPageTest.php` และ `PosBuildCenterTest.php`: 13 tests, 56 assertions ผ่าน
- PHP lint และ `git diff --check` ผ่าน

## ความเสี่ยง/งานถัดไป
- ต้อง deploy production และทดสอบเลือกไฟล์โลโก้จริงจากหน้า Settings

# Handoff — 2026-11-01 (แก้ Vue/Tauri ERP URL scope)

## Commit
- รอ commit หลังทดสอบ

## ทำอะไรไป
- เพิ่ม `erp.popstarcenter.com` ใน Tauri HTTP allowlist ทั้ง HTTP/HTTPS
- เพิ่มโดเมนจริงใน CSP `connect-src` และ `img-src`
- แก้สาเหตุที่ installer รุ่นเดิมปฏิเสธการเชื่อมต่อด้วยข้อความ `url not allowed on the configured scope`

## ทดสอบไปแล้วแค่ไหน
- Vue/Tauri unit tests: 31 tests ผ่าน
- Vue typecheck และ production web build ผ่าน

## ความเสี่ยง/งานถัดไป
- ต้องสร้าง Windows native installer รุ่นใหม่ แล้วเผยแพร่แทนรุ่นเดิม
- หลังติดตั้งรุ่นใหม่ ให้เชื่อมต่อด้วย `https://erp.popstarcenter.com` และทดสอบ ping/catalog/sync

# Handoff — 2026-11-01 (ปรับหน้าเชื่อมต่อ POS)

## Commit
- รอ commit หลังทดสอบ

## ทำอะไรไป
- เปลี่ยนค่าเริ่มต้นและข้อความแนะนำให้ใช้ `https://erp.popstarcenter.com`
- เพิ่มปุ่ม `ปิด` และปุ่ม `X` ให้ modal ตั้งค่าเครื่องปิดได้ แม้ยังเชื่อมต่อไม่สำเร็จ
- เพิ่ม layout ปุ่มใน modal ให้ใช้งานได้บนจอ Windows
- ตั้ง source version เป็น `1.4.4` เพื่อไม่ให้ build ใหม่แสดงค่าเก่า

## ทดสอบไปแล้วแค่ไหน
- Vue/Tauri unit tests: 31 tests ผ่าน
- Vue typecheck และ production web build ผ่าน

## ความเสี่ยง/งานถัดไป
- ต้องเผยแพร่ installer รุ่นถัดไปแทน binary เดิมที่ยังอยู่บนเครื่องร้าน

# Handoff — 2026-11-01 (บังคับ HTTPS ใน POS release)

## Commit
- รอ commit หลังทดสอบ

## ทำอะไรไป
- เปลี่ยน workflow สร้าง manifest ให้สร้างลิงก์ installer เป็น `https://erp.popstarcenter.com`
- เปลี่ยน Tauri updater endpoint เป็น HTTPS โดเมนจริง
- ปรับเอกสาร UAT ไม่ให้ชี้ไป IP/HTTP เดิม

## ทดสอบไปแล้วแค่ไหน
- ตรวจทุกจุดที่สร้างลิงก์ release แล้ว ไม่พบ workflow ที่ยังสร้าง URL IP เดิม

## ความเสี่ยง/งานถัดไป
- ต้อง build/release รุ่นใหม่จึงจะเขียน `latest.json` ชุดใหม่บนเซิร์ฟเวอร์

# Handoff — 2026-11-01 (ตรวจ version installer ให้ตรงป้ายดาวน์โหลด)

## Commit
- รอ commit หลังทดสอบ

## ทำอะไรไป
- ทำให้ `package.json`, `Cargo.toml` และ `tauri.conf.json` ใช้ version ฐานเดียวกัน
- เพิ่มขั้นตอน CI ตรวจ Windows installer metadata ว่าตรงกับ version ที่สั่ง build ก่อน publish

## ทดสอบไปแล้วแค่ไหน
- `git diff --check` ผ่าน
- ต้องยืนยันด้วย native Windows build รุ่นถัดไป

## ความเสี่ยง/งานถัดไป
- รุ่นที่มีอยู่บนเครื่องอาจยังเป็น binary เก่า ต้องติดตั้ง installer รุ่นใหม่ทับ
## Handoff - 2026-11-01
- Commit: `pending`
- ทำอะไร: เพิ่มปุ่มอัปโหลดโลโก้ที่มองเห็นและกดได้จริง พร้อมแสดงชื่อไฟล์ที่เลือกในหน้าตั้งค่า; ตรวจศูนย์ควบคุมโมดูลให้รองรับการเข้าหน้าจัดการข้อมูลตั้งต้นและวงจรเอกสารตามสถานะ โดยไม่เปิดให้ลบเอกสารที่มีผลบัญชี/สต๊อก
- ทดสอบ: `php artisan test --filter='SystemSetting|ModuleControl|Settings'` ผ่าน 8 tests, 37 assertions; `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ทดสอบอัปโหลดไฟล์จริงบน production และยังไม่ได้ตรวจทุก workflow ของเอกสารแต่ละประเภทแบบ end-to-end
- Deploy: ยังไม่ deploy
- งานถัดไป: ให้เจ้าของตรวจหน้า Settings และสั่ง deploy เมื่อพร้อม

# Handoff - 2026-11-01 (POS fallback เมื่อ junction ใช้งานไม่ได้)
- Commit: `pending`
- ทำอะไร: แก้ `prepare_local_storage` ให้สร้าง junction ไปไดรฟ์ D แบบไม่บล็อกการขาย; หาก Windows สร้าง junction ไม่ได้ จะคืนกลับโฟลเดอร์เดิมและใช้ SQLite บนเครื่องต่อ ทำให้ปุ่มคิดเงินไม่ค้างที่ “ตรวจฐานข้อมูล”
- ทดสอบ: `git diff --check` ผ่าน; เครื่อง dev ไม่มี `cargo` จึงยังไม่ได้รัน Rust build แบบ native
- Deploy: ยังไม่ deploy
- งานถัดไป: ให้ GitHub Actions สร้าง Windows installer รุ่นใหม่ แล้วทดสอบเปิดกะ/คิดเงินจริงบนเครื่อง Windows

## Handoff - 2026-11-02 (Claude: เวลาเริ่ม-สิ้นสุดโปรตามจำนวน)
- ทำอะไร: เพิ่ม `qty_promotions.starts_at/ends_at`; ERP และ POS Tauri เช็กตามเวลาจริง; POS รุ่นใหม่ดึงโปรล่วงหน้าไว้ใช้ออฟไลน์ด้วย `include_upcoming=1`; pos-python ยังอ่านแค่วันที่
- ทดสอบ: phpunit ผ่าน; Vue test รอ CI; ต้อง migrate

# Handoff - 2026-11-02 (ป้องกันหน้า Settings ล้มจาก VAT table)
- Commit: `pending`
- ทำอะไร: ป้องกัน `SystemSettingController` เรียก `vat_rates` โดยตรงเมื่อ migration ยังไม่อยู่บน production ทำให้ `/settings` ล้ม 500; หน้าใช้ VAT 7% เป็นค่าเริ่มต้น และการบันทึกจะไม่เขียน VAT จนกว่าตารางพร้อม
- ทดสอบ: `php -l app/Http/Controllers/SystemSettingController.php`; `PosInstallPageTest.php` และ `PosBuildCenterTest.php` ผ่าน 13 tests / 56 assertions
- Deploy: ยังไม่ deploy
- งานถัดไป: commit/push แล้ว deploy production จาก workflow ที่เจ้าของอนุมัติ จากนั้นเปิด `/settings` และทดสอบอัปโหลดโลโก้จริง

# Handoff - 2026-11-02 (เพิ่มปุ่มนำเข้าโลโก้โดยตรง)
- Commit: `pending`
- ทำอะไร: ยกเลิกการ submit อัตโนมัติทันทีที่เลือกไฟล์ และเพิ่มปุ่ม `นำเข้าและบันทึกโลโก้` ให้เลือกไฟล์ก่อนแล้วค่อยส่งฟอร์มอย่างชัดเจน
- ทดสอบ: `git diff --check` และ PHP lint ผ่าน
- Deploy: ยังไม่ deploy
- งานถัดไป: deploy แล้วทดสอบเลือกไฟล์โลโก้และกดปุ่มนำเข้าจากหน้า Settings จริง

# Handoff - 2026-11-02 (กัน upload logo เป็น 500)
- Commit: `pending`
- ทำอะไร: ครอบการสร้างโฟลเดอร์/ย้ายไฟล์/บันทึกค่าโลโก้ด้วย error handling เพื่อไม่ให้ permission หรือ filesystem error กลายเป็นหน้า 500; ส่งกลับหน้า Settings พร้อมข้อความแก้ไขได้
- ทดสอบ: PHP lint และ feature tests ที่เกี่ยวข้องต้องรันก่อน deploy
- Deploy: รอ deploy
- งานถัดไป: deploy แล้วทดลองอัปโหลดไฟล์จริงบน production

# Handoff - 2026-11-02 (ย้ายโลโก้ custom ไป Laravel public storage)
- Commit: `pending`
- ทำอะไร: แก้สิทธิ์อัปโหลดถาวรโดยเก็บโลโก้ custom ใน `storage/app/public/images/logos`, สร้าง storage link ตอน deploy และปรับ `AppSetting::logoUrl()` ให้สร้าง URL/ตรวจเวลาไฟล์จาก storage ได้
- ทดสอบ: PHP lint, `git diff --check`, PosInstallPageTest และ PosBuildCenterTest ผ่าน 13 tests / 56 assertions
- Deploy: รอ deploy production
- งานถัดไป: ทดสอบเลือกไฟล์และกดนำเข้าโลโก้จริงหลัง deploy

# Handoff - 2026-11-02 (แก้รายงานติดลบและมูลค่าสินค้าคงเหลือ)
- Commit: `pending`
- ทำอะไร: รายงาน `สต็อกต่ำ / ติดลบ` แสดงเฉพาะยอดติดลบจริง และเพิ่มราคาต่อหน่วยกับมูลค่าคงเหลือ; รายงาน `สินค้าคงเหลือ` เพิ่มสองคอลัมน์เดียวกันโดยคำนวณจาก `default_price`
- ทดสอบ: PHP lint, `git diff --check`, `P0ReportFiguresTest` ผ่าน 6 tests / 32 assertions
- Deploy: ยังไม่ deploy
- งานถัดไป: ตรวจรายงานจริงและ deploy เมื่อเจ้าของอนุมัติ

# Handoff - 2026-11-02 (เพิ่มมูลค่ารายงานสต็อกตามสาขา)
- Commit: `pending`
- ทำอะไร: เพิ่มคอลัมน์ `มูลค่าคงเหลือ` ในรายงานสต็อกตามสาขา โดยรวมจำนวนคงเหลือคูณราคาสินค้า
- ทดสอบ: รัน PHP lint, `git diff --check` และชุดรายงานก่อน deploy
- Deploy: รอ deploy
- งานถัดไป: ตรวจผลรายงานตามสาขาหลัง deploy
# Handoff — 2026-11-02 (เพิ่ม Stock Card รายละเอียด)

## ทำอะไรไป
- เพิ่มรายงานใหม่ `inventory.stock_card_detail` แยกจากรายงานเคลื่อนไหวสินค้าเดิม
- แสดงวันที่/เวลา, สินค้า, คลัง, Lot, เอกสาร, ประเภท, ยอดก่อน, รับเข้า, จ่ายออก, ยอดหลัง, ทุนต่อหน่วย และผู้ทำรายการ
- คำนวณยอดก่อน/หลังจาก movement ย้อนหลังจริง ไม่เริ่มนับใหม่เฉพาะช่วงวันที่ที่เลือก
- เพิ่ม migration ให้รายงานโผล่ในเมนูสินค้าและสต็อก พร้อมใช้ตัวกรองสาขาและค้นหาเดิม

## ทดสอบไปแล้วแค่ไหน
- `php artisan test --filter='ReportGovernanceTest|StockBalanceReportTest' --no-coverage`: 16 tests / 165 assertions ผ่าน
- PHP syntax check และ `git diff --check` ผ่าน
- `git pull origin main` ทำไม่ได้ในสภาพแวดล้อมนี้เพราะสิทธิ์เปิด `.git/FETCH_HEAD` ถูกปฏิเสธ

## งานถัดไป
- รัน migration และเปิดตรวจรายงานจริงบน production หลัง deploy ตามคำสั่งเจ้าของ
- ทดสอบรายการรับเข้า/ขาย/โอน/คืน/ปรับ และตรวจยอดก่อน-หลังกับ Stock Balance
# Handoff — 2026-11-02 (แสดงตัวคูณหน่วยแบบอ่านง่าย)

## ทำอะไรไป
- ปรับตัวคูณหน่วยในแฟ้มสินค้าให้ตัดศูนย์ท้าย เช่น `1.0000` เป็น `1`, `12.0000` เป็น `12`, และยังรองรับทศนิยมจริง เช่น `1.25`
- คงการเก็บข้อมูลและค่าความละเอียดในฐานข้อมูลเดิม ไม่กระทบการคำนวณตัดสต๊อก

## ทดสอบ
- `php artisan view:cache` ผ่าน
- Product catalog tests: 3 tests / 34 assertions ผ่าน
- `git diff --check` ผ่าน

## งานถัดไป
- Deploy production เมื่อเจ้าของสั่ง

# Handoff — 2026-11-02 (แก้ราคาหน่วยฐานในรายงานสต็อก)

## ทำอะไรไป
- ให้รายงานสินค้าคงเหลือใช้ราคาบาร์โค้ดหน่วยฐานที่ใช้งานอยู่ก่อนราคาหลักของสินค้า
- ป้องกันกรณีราคาหลักเป็นราคาแพ็ก แต่ราคาขายหน่วยฐานในแฟ้มสินค้าเป็นราคาที่ถูกต้องกว่า
- เพิ่ม test ยืนยันสินค้า 301261 ราคา 10 บาท คงเหลือ 20 ชิ้น มูลค่า 200 บาท

## ทดสอบ
- `php artisan test --filter=StockBalanceReportTest --no-coverage`: 9 tests / 111 assertions ผ่าน
- `php artisan view:cache` ผ่าน
- `git diff --check` ผ่าน

## Deploy
- รอ deploy production เมื่อเจ้าของสั่ง


# Handoff — 2026-11-02 (ปรับ popup แฟ้มสินค้าแบบ minimal)

## ทำอะไรไป
- ปรับ popup แฟ้มสินค้าให้เป็นโทน minimal อ่านง่ายขึ้น และเพิ่มขนาดตัวอักษร/ช่องกรอก
- จัดปุ่มเมนู แท็บ เพิ่มรหัส และบันทึกให้ขนาดสม่ำเสมอ
- ตัดการพึ่งพาหัวระบบเดิมในหน้า popup โดยคงเฉพาะชื่อสินค้าและเครื่องมือที่จำเป็น

## ทดสอบ
- `php artisan view:cache` ผ่าน
- Product catalog tests: 3 tests / 34 assertions ผ่าน
- `git diff --check` ผ่าน

## งานถัดไป
- Deploy production เมื่อเจ้าของสั่ง

# Handoff — 2026-11-02 (เพิ่มตัวกรองสถานะสินค้า)

## ทำอะไรไป
- เพิ่ม dropdown กรองสินค้า `ทุกสถานะ`, `ใช้งาน`, และ `ไม่ใช้งาน / พักไว้` ในหน้าทะเบียนสินค้า
- เพิ่มป้ายสถานะสินค้าให้เห็นชัดใน popup แก้ไข และคงการบันทึก checkbox เดิม
- ไม่มีการเปลี่ยนแปลงข้อมูลสินค้าอัตโนมัติ

## ทดสอบ
- `php artisan view:cache` ผ่าน
- Product catalog tests: 3 tests / 34 assertions ผ่าน
- `git diff --check` ผ่าน

## งานถัดไป
- Deploy production เมื่อเจ้าของสั่ง

# Handoff — 2026-11-02 (ปรับ popup แก้ไขสินค้าให้ทันสมัย)

## ทำอะไรไป
- ไม่ render แถบระบบ `JET ERP` เมื่อเปิดหน้าแก้ไขสินค้าแบบ popup
- เปลี่ยนฟอร์มแก้ไขสินค้าเป็นการ์ด 2 คอลัมน์ ช่องกรอกใหญ่ และระยะห่างอ่านง่าย
- คงการทำงานเดิมของฟอร์มและการบันทึกข้อมูล

## ทดสอบ
- `php artisan view:cache` ผ่าน
- Product catalog tests: 3 tests / 34 assertions ผ่าน
- `git diff --check` ผ่าน

## งานถัดไป
- Deploy production เมื่อเจ้าของสั่ง

# Handoff — 2026-11-02 (จัดรูปแบบรายงานสต็อกต่ำสำหรับพิมพ์)

## ทำอะไรไป
- เอาคอลัมน์คลัง/ที่เก็บออกจากรายงานสต็อกต่ำ เพื่อไม่ให้ข้อความล้นหน้ากระดาษ
- คงชื่อสินค้าและข้อมูลจำนวน/ราคา/มูลค่าไว้ในตาราง
- คงชื่อสาขาไว้เฉพาะหัวเอกสารด้านบน

## ทดสอบ
- `php artisan test --filter='StockBalanceReportTest|ReportSmokeTest' --no-coverage`: 10 tests ผ่าน, 1 skipped
- `php artisan view:cache` ผ่าน
- `git diff --check` ผ่าน

# Handoff — 2026-11-02 (พิมพ์เฉพาะหน้าปัจจุบัน)

## ทำอะไรไป
- แก้โหมดพิมพ์ไม่ให้เปลี่ยน `per_page` เป็น 2,000 แถว
- พิมพ์ตาม `page` และ `per_page` เดียวกับหน้าจอทุกเอกสาร
- คง export CSV/XLSX ให้ดึงข้อมูลครบทั้งรายงานเหมือนเดิม

## ทดสอบ
- `php artisan test --filter='StockBalanceReportTest|ReportSmokeTest' --no-coverage`: 10 tests ผ่าน, 1 skipped
- `php artisan view:cache` ผ่าน
- `git diff --check` ผ่าน

# Handoff — 2026-11-03 (มาตรฐานงานพิมพ์รายงานและเอกสาร)

## ทำอะไรไป
- ตรวจเส้นทางงานพิมพ์ของรายงาน เอกสาร A4/A5 ใบขึ้นของ และสลิปเงินเดือน โดยคงใบเสร็จความร้อน/ฉลากตามขนาดเฉพาะของเดิม
- แก้สาเหตุหน้าว่างท้ายเอกสาร: shared print theme เคยบังคับความสูง A4 เต็ม 297mm ทั้งที่ `@page` มีขอบกระดาษ
- กำหนดมาตรฐาน A4, หัวตารางซ้ำทุกหน้า และห้ามแยกแถวลายเซ็น/ยอดรวมกลางหน้าให้เอกสารมาตรฐาน
- โหมดพิมพ์รายงานซ่อนหัว panel ที่ซ้ำกับหัวเอกสาร และยกเลิก scroll/max-height ของตารางเพื่อไม่ให้ข้อมูลถูกตัด
- ใบขึ้นของพิมพ์ค่าจริงแทน select/input และสลิปเงินเดือนใช้ระยะขอบ/ตารางที่ปลอดภัยกับ A4

## ทดสอบ
- `php artisan view:cache` ผ่าน
- `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/PrintablePageSizeTest.php`: 3 tests / 12 assertions ผ่าน
- `php -d memory_limit=1G vendor/bin/phpunit --filter='ReportSmokeTest|StockBalanceReportTest'`: 10 passed, 1 skipped / 113 assertions
- `git diff --check` ผ่าน
- หมายเหตุ: `artisan test` spawn child process ที่กลับไปใช้ memory 128MB; full PHPUnit runner ถูกเริ่มด้วย 1GB แต่ CLI session นี้ตัดการรอผลก่อนมี output จึงไม่ใช้เป็นผลยืนยัน

## งานถัดไป
- Push commit แล้วรอเจ้าของสั่ง deploy เพื่อเปิด Chrome print preview บน production ตรวจฉบับจริงอีกครั้ง

# Handoff — 2026-11-03 (ตรวจรับสินค้าและตรวจนับสต๊อก)

## ทำอะไรไป
- ตรวจ flow รับสินค้าอิสระ, รับ PO แบบบางส่วน, ตรวจรับโอน, ตรวจนับสต๊อก และการอนุมัติใบปรับ
- ยืนยันว่า PO ถูก lock, กันรับเกินยอดค้าง และรับแต่ละครั้งสร้างใบซื้อ/ล็อต/GL ผ่าน service กลาง; ตรวจรับโอนเป็นหลักฐานแยก ไม่ทำให้สต๊อกซ้ำ
- แก้ service กลางให้ตำแหน่งเก็บต้องเป็นของสาขาเดียวกับใบตรวจนับและใบปรับสต๊อก
- กันการเปิดใบตรวจนับซ้อนในตำแหน่งเดียวกันขณะที่ใบเดิมยังนับ/รอตรวจ/รอใบปรับ และ lock ใบตอน Post เพื่อกันสร้างใบปรับซ้ำจากการกดพร้อมกัน

## ทดสอบ
- `php artisan test --filter='InventoryApprovalAndCloseTest|PurchaseOrderPartialReceiptTest|InventoryFlowEndToEndTest|StaleStockCountAlertTest' --no-coverage`: 16 tests / 67 assertions ผ่าน
- `php artisan view:cache` ผ่าน
- `git diff --check` ผ่าน

## ความเสี่ยง/งานถัดไป
- ยังไม่ deploy; หลัง deploy ควร UAT รับ PO บางส่วน, สแกนตรวจรับ และตรวจนับ 1 ตำแหน่งแบบจบถึงอนุมัติ โดยใช้ข้อมูลทดสอบเท่านั้น

# Handoff — 2026-11-03 (Rollback shared document print regression)

## ทำอะไรไป
- ถอน CSS ส่วนกลางที่เพิ่มใน `documents.partials.print-theme` รอบมาตรฐานงานพิมพ์ เพราะกระทบใบเสนอราคา/ใบจองหลายหน้าและทำให้ Chrome พิมพ์ต่อหน้าแรกไม่ได้
- คงการแก้เฉพาะหน้า reports, ใบขึ้นของ และสลิปเงินเดือนไว้ เพราะไม่ใช้ shared document theme นี้

## ทดสอบ
- `php artisan test --filter='PrintablePageSizeTest' --no-coverage`: 3 tests / 12 assertions ผ่าน
- `php artisan view:cache` ผ่าน
- `git diff --check` ผ่าน

## ความเสี่ยง/งานถัดไป
- ยังไม่ deploy; ต้องเปิด print preview ของใบเสนอราคา/ใบจองที่มีหลายรายการบน production หลัง deploy เพื่อยืนยันจำนวนหน้าจริง

# Handoff — 2026-11-03 (จำนวนใบจองตามหน่วยขาย)

## ทำอะไรไป
- แก้ข้อความสต๊อกพร้อมจองไม่พอให้แสดงรหัสสินค้า จำนวน และหน่วยขายที่อ่านได้ โดยไม่ปล่อยเลขทศนิยม 8 ตำแหน่งจากฐานข้อมูล
- สินค้าชั่งแสดงและปรับจำนวนทีละ `0.01`; สินค้านับเป็นแพ็ก/ชิ้นปรับทีละ `1`.
- เพิ่มปุ่ม `−` / `+` ที่ช่องจำนวน และปรับ hint คงเหลือ/จองแล้ว/พร้อมจองให้แสดงหน่วยทุกค่า.
- คงกฎธุรกิจเดิม: หลังล้างระบบยอดคงเหลือเป็นศูนย์ จึงกันการสร้างใบจองจนกว่าจะรับเข้าหรือลงยอดยกมา.

## ทดสอบ
- `php artisan test --filter=BookingSalesAreaTest --no-coverage`: 11 tests / 73 assertions ผ่าน
- `php artisan test --filter='BookingDeliveryAndCashTransferTest|SalesDocumentEndToEndTest' --no-coverage`: 19 tests / 139 assertions ผ่าน
- `php artisan view:cache` ผ่าน
- `git diff --check` ผ่าน

## งานถัดไป
- ยังไม่ deploy; หลัง deploy ให้ UAT สินค้าแพ็ก 1 รายการและสินค้าชั่ง 1 รายการบนหน้าใบจอง โดยต้องมีสต๊อกเริ่มต้นก่อนจอง.

## Handoff - 2026-11-04
- Commit: `1fbd74b`
- ทำอะไร: ยกระดับ popup สำหรับกรอก/แก้ไขข้อมูลที่ใช้ร่วมกันเป็น workspace เกือบเต็มจอทั้ง desktop และ mobile ครอบคลุมใบจอง เอกสารขาย/ซื้อ/เสนอราคา/วางบิล/เพิ่มลดหนี้ บัญชี ผู้ใช้ ผังองค์กร และ BOM; dialog ยืนยันสั้นยังคงขนาดกระชับ
- ทำอะไร: แก้ปุ่ม “แก้ไข” ในแฟ้มพนักงานให้เปิดโหมดแก้ไขจริง เติมข้อมูลเดิมครบ ใช้ `PUT` ไป route update และเปลี่ยนหัว/ปุ่มบันทึกตามโหมด แทนการเปิดเป็นเพิ่มพนักงานใหม่
- ทดสอบ: `php artisan test --filter=EmployeeCreationTest --no-coverage` ผ่าน 3 tests / 14 assertions; `php artisan view:cache` ผ่าน; `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ต้องตรวจด้วย browser หลัง deploy ว่าแต่ละ popup มีการเลื่อนและปุ่ม footer ตามหน้าจอจริง; งานนี้ไม่แตะ print CSS หรือข้อมูล production
- Deploy: ยังไม่ deploy
- งานถัดไป: รอเจ้าของสั่ง deploy แล้ว UAT popup หลักบน production

## Handoff - 2026-11-05 (JET POS Python touch flow)
- ทำอะไร: เพิ่ม `TouchKeypadDialog` สำหรับ PIN คนขาย, เงินทอนเปิดกะ, แก้จำนวนสินค้า, รับเงิน และปิดกะ/Z ให้แตะจอได้โดยไม่ต้องพึ่งคีย์บอร์ด
- ทำอะไร: เพิ่ม keypad ให้รายการเงินสดระหว่างกะ และแสดงข้อผิดพลาดจาก API ตอนเข้า/เปิดกะโดยตรง แทนการสลับไปออฟไลน์เมื่อ ERP ตอบ error
- ความปลอดภัย: ยังคงใช้ API/PIN/กฎราคา/โปรโมชั่น/คิว sync เดิมของ ERP; การเชื่อม production ต้องเปิดตัวเลือกบนเครื่องโดยผู้ใช้ และไม่มีการสร้างบิลในการทดสอบนี้
- ทดสอบ: `PYTHONPATH=apps/jet-pos-python python3 -m unittest discover -s apps/jet-pos-python/tests -v` ผ่าน 25 tests; UI smoke ด้วย `QT_QPA_PLATFORM=offscreen` ผ่าน; `git diff --check` ผ่าน
- งานถัดไป: Build UAT, ติดตั้งบนเครื่อง UAT แล้วตรวจหน้าจอ PIN/เปิดกะด้วย PIN ที่เจ้าของกรอกเอง ก่อนสร้างบิลทดสอบจริง

## Handoff - 2026-11-05 (JET POS Python sales workspace)
- ทำอะไร: ขยายพื้นที่บิลจากหนึ่งในสามเป็นสองในห้าของหน้าจอ และเพิ่มแผงปุ่ม POS แบบตารางขนาดใหญ่สำหรับเข้ากะ, พัก/เรียกบิล, เงินสดกะ, พิมพ์บิล และตั้งค่า
- ทำอะไร: ขยายปุ่ม toolbar, ปุ่มแก้จำนวน/ลบ และปุ่มรับชำระ เพื่อรองรับหน้าจอสัมผัสโดยไม่ย้อนกลับไปใช้ UI legacy
- ทดสอบ: `PYTHONPATH=apps/jet-pos-python python3 -m unittest discover -s apps/jet-pos-python/tests -v` ผ่าน 25 tests; UI smoke ผ่าน; `git diff --check` ผ่าน
- งานถัดไป: Build/install UAT และให้เจ้าของกรอก PIN บนเครื่องจริงเพื่อเก็บ error เปิดกะจาก ERP หากยังไม่ผ่าน

## Handoff - 2026-11-05 (Codex: Python POS session flow / JET POS)
- Branch: `claude/pos-python-settings-popup`, base `8fbbded`; commits `353e08b` (branding / session lifecycle / touch notices), `ba89748` (process restart / shortcut guard / screenshots). Commit เอกสารและปรับ capture tool ดู git log หัวข้อนี้
- Git: `git pull origin main` จาก worktree เดิมถูก sandbox ปฏิเสธ `FETCH_HEAD` เพราะ metadata อยู่ภายนอกพื้นที่เขียน; clone `--no-hardlinks` ไป `/private/tmp/pos-session-work`, คงชื่อ branch ปัจจุบันและ origin GitHub เดิม, pull main สำเร็จ `Already up to date`. Commit/push จากสำเนานี้และเก็บ source ตรงกันใน workspace เดิม. HEAD/index ของ worktree เดิมยังเป็น `8fbbded`: รับงานต่อจาก remote branch หรือสำเนานี้ ห้าม commit งานซ้ำจาก metadata เดิม
- ทำอะไร: ทำตาม `docs/ai/codex-pos-python-session-flow.md`; เพิ่มโลโก้ MPM จาก Vue ไว้ใน assets ของ Python บนแถบบน หน้าล็อก PIN ตั้งค่าครั้งแรก และ Z preview; ชื่อหน้าต่าง/application display/installer/shortcut เป็น JET POS. ใช้ icon.ico เดิมของ Vue กับ QApplication/หน้าต่าง/PyInstaller/Inno Setup และ bundle assets ด้วย `--add-data`; คงชื่อ setup `PopCentral-POS-UAT-*-setup.exe`, executable และ install path เดิมเพื่อรักษาการอัปเกรด
- เปิดแอป: แสดงหน้าล็อกเต็มพื้นที่หน้าต่างทุกครั้ง → เข้างาน → PIN ตามนโยบาย ERP → เปิดกะด้วย numpad ถ้ายังไม่มีกะ; คนเดิมมีกะแล้วเข้าขายทันทีโดยไม่ถามทำกะเดิมซ้ำ. คงโหมดเลือกชื่อเฉพาะเมื่อ ERP กำหนด `cashier_login_mode=selection` ตามสิทธิ์เดิม ไม่สร้างหรือเปลี่ยน credential
- แถบบน: โลโก้/ชื่อคนขายแสดงตลอด, ปุ่ม `🔒 ล็อก/สลับคนขาย`, `ออกงาน`, `⏻ ปิดโปรแกรม`; ย้ายประวัติ/เงินสด/ใบเสร็จล่าสุดไปแถวปุ่มสัมผัสถัดลงมา. ล็อกล้างเฉพาะตัวตนในหน้าจอ เก็บกะ DB และตะกร้าไว้; F12 ใช้ไม่ได้ขณะล็อก. ย่อหน้าต่างแล้วล็อกและยกเลิก dialog ที่เปิดอยู่; restore กลับมาไม่ข้ามล็อก
- ปิดโปรแกรม: X/Alt+F4/ปุ่มเรียก closeEvent เดียวกัน รวม programmatic close; กลับไปขายต่อเป็น default/Esc, ล็อกหน้าจอ, ปุ่มปิดสีแดงแยกห่าง 32px. ถ้าตะกร้าหรือคิวค้างต้องยืนยันอีกรอบ โดย default ของการยืนยันเป็นกลับ; flush snapshot ล่าสุดก่อนปิดและเปิดใหม่ยังล็อก. ไม่ปิดกะอัตโนมัติ
- ออกงาน: นับเงิน/ยืนยัน → service ปิดกะเดิม (ยังกันบิล pending/failed) → Z และ auto-print เมื่อเลือก Windows queue → กลับหน้าล็อก. เพิ่มเทสต์ยืนยัน auto-print เรียก printer adapter; ไม่ส่งงานไปเครื่องพิมพ์จริง
- Touch popup: แทน `QMessageBox` ทุกแห่งใน ui.py ด้วย TouchMessage ที่ใช้ TouchDialog และเอา QInputDialog import ออกทั้งหมด. เพิ่มเทสต์ตรวจ source ไม่มี `QMessageBox.` / `QInputDialog.`; notice ใช้ข้อความตัดบรรทัด ปุ่มใหญ่ และ confirmation เริ่มที่กลับ. ปรับ backdrop ให้แสดงเมื่อ reuse dialog
- ทดสอบ: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python -m pytest apps/pos-python/tests -q` **307 passed / 7.35s**. เพิ่ม 10 เทสต์ session: เข้างาน/PIN→เปิดกะ, ยกเลิก PIN/เปิดกะ, resume, lock ไม่ปิดกะและเก็บตะกร้า, ออกงาน+Z, close draft/queue ต้องยืนยัน, Esc/cancel, minimize, ป้องกันคนอื่นรับกะ, ไม่มี native dialog, Z auto-print และ restart คนละ process คืน 9 ชิ้น ฿1,430 บนหน้าล็อก. ใช้ DB ชั่วคราวและ mocked auth ไม่สร้าง PIN/password/token
- ภาพ: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python apps/pos-python/tools/capture_session.py` ผ่าน; เปิดตรวจครบ 8 ภาพ 1024×768 ใน `docs/ai/pos-python-shots/session/`: lock, pin, opening, sales, close-app, sign-off, pairing, z-report. Tool ปฏิเสธการรันถ้าไม่ได้ตั้ง offscreen. แก้ capture_parity เดิมให้เลือกหน้าขายใน preview และ bypass close เฉพาะ cleanup ของ tool; รันสำเนา tool แยก output ใต้ `/private/tmp/pos-session-capture-check` ผ่าน (payment 980×744)
- Laravel: `php -d memory_limit=1G artisan test` ติด child memory limit 128MB เช่นรอบก่อน; รัน `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' php -d memory_limit=1G vendor/bin/phpunit` จบ exit 0, runner รายงาน `tests=753, passed=751, assertions=6199, skipped=2, incomplete=6, duration_ms=21694` (ไม่ตีความ skipped/incomplete ว่าผ่าน). `git diff --check` ผ่าน. ไม่แก้ PHP หรือ `apps/pos-desktop/`
- ยังไม่ทดสอบ/ความเสี่ยง: Windows installer/.exe icon/shortcut และ printer hardware ยังต้องตรวจบน Windows; workflow build ถูกแก้แต่ไม่สั่ง publish/deploy. ใบเสร็จและ Z ที่พิมพ์เป็นข้อความล้วน ไม่มีช่อง raster logo จึงเพิ่มโลโก้เฉพาะ Z preview ไม่แทรกรูปเข้า text adapter. คง guard เดิม: คนอื่นรับกะที่ยังเปิดของแคชเชียร์เดิมไม่ได้ ต้องปิด/ส่งมอบตามขั้นตอนเดิม (ปุ่มล็อกไม่เปลี่ยนเจ้าของกะหรือ cashier ของบิล)
- Deploy: **ยังไม่ deploy**, ไม่เรียก production/legacy และไม่สั่ง build publish. ทุก Qt invocation ใช้ `QT_QPA_PLATFORM=offscreen`; workflow Windows test/self-check กำหนด env เดียวกัน
- งานถัดไป: รับ branch ที่ push แล้วไป review และ Windows UAT จอสัมผัส 1024×768 / printer จริงก่อนขาย; เจ้าของกรอก PIN/token เองและสั่ง deploy แยกตาม OPERATIONS

## Handoff - 2026-11-05 (Codex: Python POS shift float / blind close)
- Branch: `claude/pos-python-settings-popup`, base/HEAD `4a1da62`; **ยังไม่มี commit ใหม่**. ทำงานเฉพาะ workspace เดิมตามคำสั่งเจ้าของ ไม่ย้าย clone หรือเปลี่ยน branch
- Git: ทดลอง `git pull origin main` ถูกปฏิเสธที่ worktree `FETCH_HEAD`; ทดลอง commit ขั้น protocol ด้วย `git add` ระบุไฟล์แล้วถูกปฏิเสธที่ `index.lock` (`Operation not permitted`) เนื่องจาก metadata อยู่ `/Users/jetsadapolsan/Documents/Codex/2026-07-18/sites-plugin-sites-openai-bundled/outputs/jeterp/.git/worktrees/pypos/` นอก writable roots. ไม่ข้าม sandbox. ไม่ push เพราะยังสร้าง commit งานไม่ได้; ไฟล์ทั้งหมดคงอยู่ใน workspace นี้ตามคำสั่งเจ้าของ
- ทำอะไร: อ่านและทำตาม `codex-pos-python-shift-float.md` / API specification. เพิ่ม `pos_python/shift_float.py`: Decimal validation ไม่ปัดเศษ input, capability guard (`default_float` + `carried_float`), preview/final แยกกันและไม่ส่ง expected/deposit เป็นค่าจริง, ตรวจยอดตอบกลับตรงสตางค์. expected/difference รองรับค่าติดลบ แต่ยอดนับ/เก็บ/นำส่งต้องไม่ติดลบ
- เปิดกะ: GET policy หลังยืนยันคนขาย, prefill carried รวมศูนย์ / fallback default เมื่อ null, บังคับเหตุผลเมื่อรับกะต่าง, ส่ง `opening_note` ผ่าน durable outbox / sync worker และ direct provisioning method. คงการเปิด server shift ก่อนส่งบิล
- ปิดกะ: TouchDialog ใหม่ 2 คอลัมน์พอดี 1024×768. blind ไม่เติม/แสดง expected หรือส่วนต่างก่อนนับและขอ preview; ผู้มีสิทธิ์เห็น expected เฉพาะเมื่อ ERP ส่ง field มา. กรอกยอดรวมหรือนับ 11 ชนิดธนบัตร/เหรียญด้วย NumPad/ValueDialog เดิม, เงินทอนเก็บ default min(counted, default), แก้ได้ไม่เกินยอดนับ, เตือนต่ำกว่า default, เลขซอง/หมายเหตุ. แก้ทุก field จะล้าง preview และปิดปุ่มยืนยันจนตรวจใหม่
- เงินเข้าออก/เตือน: เอายอด expected ใน status หลัง movement ออกเพื่อไม่รั่ว blind; อ่าน `cash_warning` จาก ERP โดยตรงเมื่อเข้ากะ/บันทึกเงิน/ทุก 30 วินาที ไม่คำนวณยอดลิ้นชักหรือ deposit เอง
- ความถูกต้องการปิด: บังคับส่ง outbox ค้างให้ครบก่อนเปิด modal; ERP ปิดสำเร็จก่อนปรับกะ local และไม่สร้างคิวปิดซ้ำ. ถ้าคำขอปิดไม่ทราบผล/ข้อมูลตอบกลับไม่สอดคล้อง จะ persist `uncertain_float_close`, ล็อกและกันเริ่มขายต่อข้าม restart; ไม่ retry อัตโนมัติ. **ต้องให้ผู้ดูแลตรวจสถานะ/รายงานกะบน ERP และกระทบยอด local ก่อนล้าง flag นี้**; ยังไม่มี API อ่านกะปิดตาม ID ในสเปกที่จะใช้ recover อัตโนมัติอย่างปลอดภัย
- Compatibility: ERP เก่าใช้ flow เดิมและไม่มี preview request; การปิดเมื่อ runtime ERP ออฟไลน์ถูกบล็อกพร้อมข้อความต้องต่อเน็ต. โหมด local-only เดิมไม่สร้าง deposit สมมติ
- Z: พิมพ์ 2 jobs ระบุใบที่ 1/2 และ 2/2, ใช้ server report สำหรับ float API; ครบเงินยกมา/ส่วนต่างรับกะ/เหตุผล, cash drops, expected/count/difference, deposit/reference, float kept, ยอดขายแยกช่องทาง/เงินเข้า/เบิกจ่าย/ส่วนลด/ยกเลิก/คืน และลายเซ็นผู้ส่ง/ผู้รับ. field เก่าที่ไม่มีแสดงไม่มีข้อมูล ไม่เดายอด. preview เลื่อนอ่านได้
- ทดสอบ Python: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python -m pytest apps/pos-python/tests -q` ผ่าน **335 tests**. เพิ่ม 28 tests: fake API cents 15432.75−2000=13432.75, carry/null/zero และ reason, strict fractional cents, signed expected, blind/manager, invalidate ทุก field, denominations, warning flag, legacy ไม่มี preview, offline block, open note sync, server-first close, timeout ไม่ปิด local/ไม่ retry และ Z 2 jobs ครบช่อง. fixture ใช้ SQLite ชั่วคราว ไม่สร้าง PIN/password/token
- Mutation checks: ถอดทีละจุดแล้วคืนไฟล์ทุกครั้ง ได้แก่ deposit reconciliation, required opening reason, blind blank input, preview invalidation, Z second copy → pytest exit 1 ครบทั้ง 5 จุด (ตรวจพบจริง). จากนั้นรัน suite ปกติผ่าน
- ภาพ: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python apps/pos-python/tools/capture_shift_float.py`; ตรวจภาพ 1024×768 ใน `docs/ai/pos-python-shots/shift-float/`: opening, closing-blind, closing-preview, z-report, z-report-signatures. ใช้ fake API ไม่มี network. ทุก Qt invocation ในงานนี้ตั้ง offscreen
- Laravel: `php -d memory_limit=1G artisan test` ติด child memory limit 128 MB เช่นรอบเดิม; รัน `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' php -d memory_limit=1G vendor/bin/phpunit` ต่อ exit 0; runner รายงาน tests=753, passed=751, assertions=6199, skipped=2, incomplete=6 (ไม่ถือ skipped/incomplete ว่าผ่าน). ไม่แก้ PHP หรือ apps/pos-desktop และไม่รัน Vue build
- ยังไม่ทดสอบ/ความเสี่ยง: ERP float branch ยังไม่ merge จึงทดสอบสัญญา API ด้วย fake ตามสเปก ไม่ใช่ integration บน production; ต้อง UAT Windows/จอสัมผัส/เครื่องพิมพ์จริงและการส่งมอบซองเงิน. เมื่อ response ปิดกะหาย ต้องกระทบยอดโดยผู้ดูแลตามข้างต้นก่อนเปิดขายต่อ
- Deploy: **ยังไม่ deploy / ไม่ release / ไม่เรียก production หรือ legacy**
- งานถัดไป: เปิด workspace ที่เขียน Git metadata เดิมได้, ตรวจ diff และ pull/reconcile โดยคง branch/ไฟล์งาน, commit แยก (1) protocol + provisioning/services/sync, (2) UI + tests + capture/images, (3) handoff/memory; ทดสอบแล้ว push branch เดิม. ห้าม commit .claude/.codex/credentials และห้าม deploy จนเจ้าของสั่ง

## Handoff - 2026-11-05 (Codex: close-shift single workspace)
- Commit: ยัง commit/push ไม่ได้; HEAD คง `4a1da62`, branch `claude/pos-python-settings-popup`. คงงาน shift-float เดิมและงานรอบนี้ทั้งหมดใน workspace ตามคำสั่งเจ้าของ
- Git: รัน `git pull origin main` แล้วติด `FETCH_HEAD: Operation not permitted`; ทดลอง stage brief `git add docs/ai/codex-pos-python-close-shift-ui.md` ถูกปฏิเสธ `index.lock` เนื่องจาก metadata อยู่นอก writable roots. ไม่ย้าย clone/branch หรือข้าม sandbox และไม่ push งานที่ยัง commit ไม่ได้. **brief ยัง untracked ต้องรวมใน commit เมื่อเขียน metadata ได้**
- ทำอะไร: อ่าน PROJECT_MEMORY/WORKFLOW และ brief ทั้ง close-shift/shift-float; เปลี่ยน float close เป็น workspace เต็ม 1024×768 สี่ขั้น นับแยก 11 ชนิดหรือกรอกยอดรวม, keypad ในหน้าและไฮไลต์ช่องที่เลือก, blind ทุกสิทธิ์ในขั้นแรก, preview จาก ERP หลังถัดไป, ตรวจยอด/สีส่วนต่าง/รายการนำส่งระหว่างกะแบบอ่านอย่างเดียวแบ่งหน้า 4 รายการ, แยกเงินทอน/เลขซอง/หมายเหตุพร้อม keypad, สรุปและยืนยันพิมพ์ Z; ย้อนกลับได้ทุกขั้น ยืนยันได้เฉพาะขั้นสุดท้ายที่ preview ยังถูกต้อง
- เงิน: ใช้ cash/Decimal เดิม ไม่คำนวณ deposit เพื่อส่ง ERP. เปลี่ยนทุก field ล้าง preview; ถัดไปจากขั้นแยกเงินขอ preview ใหม่ก่อนยืนยัน. จำนวนใบ/เหรียญต้องจำนวนเต็ม; error อยู่ในหน้า ไม่เปิด popup. คง server-first close, uncertain close guard, ERP เก่าไม่ส่ง preview และออฟไลน์ block เดิม
- นำส่งระหว่างกะ: เอาปุ่ม/เมธอดเรียกนำส่งออกจาก legacy close. เมนูเงินสดแยกชื่อ “นำส่งเงินระหว่างกะ”; จำนวน+keypad+เลขซอง+เหตุผล+ยืนยันอยู่หน้าเดียว ใช้ local-first/outbox/idempotency เดิม. ชื่อ “เงินนำส่งปิดกะ” แยกจากรายการระหว่างวัน
- Dialog: TouchDialog ที่เปิดใหม่ซ่อน QDialog เดิมก่อนแสดง และคืนหน้าเดิมเมื่อเสร็จ รองรับการเปิดต่อหลายชั้นโดยไม่มีหน้าซ้อนกัน. Minimize ยกเลิกทุก dialog รวมหน้าที่ซ่อนและห้ามคืน popup บนหน้าล็อก. Settings ที่เป็น QDialog อยู่ในกฎซ่อนเดียวกัน
- ทดสอบ: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python -m pytest apps/pos-python/tests -q` ล่าสุด **338 passed / 8.54s**. ปรับเทสต์ denomination/นำส่งให้ตรงหน้าเดียว; เพิ่ม 3 regression tests สำหรับ replace/restore 3 dialogs (มี TouchDialog visible เดียว), wizard เดินหน้า/ย้อนกลับ/preview ใหม่/กรอบปุ่มและตารางไม่มี scroll, keypad สลับจากเหรียญกลับยอดรวม. เทสต์ exact cents/uncertain close/ERP เก่า/Z สองใบเดิมยังผ่าน
- Mutation: ปิด `dialog.hide()` ชั่วคราว เทสต์ no-stacked-dialog ล้มเหลว exit 1; คืนโค้ดแล้ว suite ผ่าน. ไม่เหลือ mutation ในไฟล์
- ภาพ: รัน capture tool ด้วย offscreen และ fake API ไม่มี network; ตรวจภาพ opening/closing-blind/closing-preview/closing-split/closing-confirm/mid-shift-drop/Z ใน `docs/ai/pos-python-shots/shift-float/`; 1024×768. แก้ layout ขั้นแยกเงินเป็นสองคอลัมน์หลังพบ keypad เบียดพื้นที่จากภาพรอบแรก
- Laravel: `php artisan test` ติด memory limit 128MB; `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' php -d memory_limit=1G vendor/bin/phpunit` exit 0, runner tests=753 / passed=751 / assertions=6199 / skipped=2 / incomplete=6. ไม่ถือ skipped/incomplete ว่าผ่าน. `git diff --check` ผ่าน. ไม่แก้ PHP/Vue
- ยังไม่ทดสอบ/ความเสี่ยง: ยังต้อง Windows 1024×768/hardware printer UAT และ integration กับ ERP float branch จริง. รายการเงินระหว่างกะอ่านรายละเอียดจาก SQLite ของเครื่อง ส่วนยอดรวมและยอดปิดเป็นของ ERP. ทุก Qt invocation ตั้ง offscreen; ไม่สร้าง credential หรือเรียก production/legacy
- Deploy: **ยังไม่ deploy / ไม่ release**
- งานถัดไป: เมื่อ workspace เขียน Git metadata ได้ ให้ reconcile remote โดยรักษาไฟล์ทั้งหมด แล้ว commit เป็นขั้น: (1) brief `codex-pos-python-close-shift-ui.md`, (2) protocol/provisioning/services/sync ของ shift-float เดิม, (3) wizard/shared-dialog/tests/capture/images, (4) memory/handoff; รัน suite แล้ว push branch ตาม brief. ห้ามรวม `.claude/`/`.codex/` และไม่ deploy โดยไม่มีคำสั่ง

## Handoff - 2026-11-06 (Codex: Python POS five payment methods)
- Commit: ยัง commit/push ไม่ได้; HEAD `e0fb0ab`, branch เดิม `claude/pos-python-settings-popup`; เก็บไฟล์ทั้งหมดใน workspace ตามคำสั่งเจ้าของ
- Git: `git pull origin main` ถูกปฏิเสธ `FETCH_HEAD: Operation not permitted`; `git add apps/pos-python/pos_python/payments.py apps/pos-python/pos_python/services.py apps/pos-python/pos_python/sync_service.py` ถูกปฏิเสธ `index.lock: Operation not permitted` เพราะ metadata อยู่ภายนอก writable roots. ไม่ย้าย branch/clone และไม่ push งานที่ยัง commit ไม่ได้
- ทำอะไร: ทำตาม `docs/ai/codex-pos-python-payments.md`; รองรับ cash/transfer/mixed/credit_card/cheque. ตรวจ Vue checkout/form และ ERP validation: ใช้ `payment_ref` ร่วม (ไม่พบ field เลขเช็คแยก), reference จำเป็นบน UI ของทุกวิธีที่ไม่ใช่เงินสด และสูงสุด 80 ตัวอักษรตาม ERP. โอน/ผสมบังคับยืนยันเงินเข้าและ last4 ASCII 4 หลัก; ไม่บังคับมี PromptPay config เพื่อโอนด้วยบัญชี/สลิป. เมื่อมี config ยังแสดง QR ได้
- เงิน: `payments.py` ตรวจ/ปัดด้วย Decimal ROUND_HALF_UP 2 ตำแหน่ง; mixed เงินสดต้อง >0 และ <ยอดหลังปัด, โอน=ยอด−สด, ไม่มีเงินทอน. เงินสดรับมากกว่ายอดเก็บเงินทอน; noncash เก็บยอดสุทธิจริง. ปฏิเสธวิธีที่ไม่รู้จัก/NaN/Infinity/ยอดติดลบ. การตรวจเกิดก่อนเขียนบิล
- Local/offline: mixed เป็น payments สองแถว cash+transfer ภายใน transaction บิลเดิม ไม่ต้อง migration; sync ประกอบ method=mixed พร้อม cash_received/cash_amount/transfer_amount/change_amount=0/payment_ref/last4/payment_confirmed. เก็บ UUID และ retry เดิม; เทสต์ส่ง timeout แล้วส่งซ้ำ payload/key เท่ากันและเปิด SQLite connection ใหม่อ่าน split กลับมาเหมือนเดิม. ไม่เปลี่ยนประวัติบิลเก่า
- ยอดกะ/Z: cash drawer และเงินเข้า/ออกเปลี่ยนจาก SQLite SUM ที่แปลงเป็น float เป็นรวม Decimal จาก TEXT; เพิ่มยอดกะแยก cash/transfer/credit_card/cheque และ mixed แจกสองส่วน, ยกเลิกบิลตัดออก. Local Z แสดงยอดแยกวิธี; ERP Z ยังคงใช้ยอด server authoritative เดิม
- ใบเสร็จ: ชื่อไทยครบ 5 วิธี, mixed แสดงสองส่วน, reference และ last4; 2 ใบลูกค้า/ร้าน และ 3 ใบเมื่อมีโอน (+สำเนาตรวจยอดโอน) ตาม Vue ค่าเริ่มต้น. เส้นทาง auto-print Windows และ file printer ใช้ชุดสำเนาร่วม. ไม่ส่งงานไปเครื่องพิมพ์จริง
- UI: แถววิธีชำระ 5 ปุ่มใหญ่ใน workspace เดียว 1024×768, reference/last4 พร้อม keypad ในหน้า; error อยู่ในหน้า. ผสมเปลี่ยนยอดแล้วล้าง checkbox ยืนยันเงินเข้า. QR แสดงด้าน keypad เมื่อมี config, QR config ผิดยังโอนได้ด้วยบัญชี/สลิป. ไม่เพิ่ม dialog ซ้อน
- ทดสอบ Python: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python -m pytest apps/pos-python/tests -q` ล่าสุด **375 passed / 8.82s**. เพิ่ม 37 tests: ทั้ง 5 วิธี, payload/retry, mixed 0/เท่ายอด/เกิน/ติดลบ/NaN/Infinity, last4 ผิด/ไม่ส่ง/เลข Unicode, reference, cents rounding, cash ไม่พอ, unknown method, ยอดกะ/รายวัน/void, จำนวนสำเนา/ข้อความใบเสร็จ, persistence หลัง reconnect, invalid mixed ไม่มี financial records, UI ทั้ง 5 และ QR config/ขนาด/ปุ่มไม่ทับกัน. ปรับ fixture โอนเดิมให้มี last4 ที่กติกาใหม่บังคับ
- Mutation: เปลี่ยนชั่วคราวทีละจุด (1) เอาขอบเขต mixed ออก → 2 failed, (2) ไม่หัก cash จากยอดโอน → 1 failed, (3) ไม่ส่ง method=mixed → 1 failed; ทุกคำสั่ง exit 1 แล้วคืนไฟล์ใน finally. Full suite หลังคืนผ่านตามข้างต้น
- ภาพ: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python apps/pos-python/tools/capture_payments.py`; ตรวจภาพ cash/transfer/mixed/credit_card/cheque ใน `docs/ai/pos-python-shots/payments/` ครบ 1024×768; แก้แถวปุ่มจากภาพรอบแรกที่เบียดกัน. ไม่มี network/credential ใน capture. ทุก Qt invocation ตั้ง offscreen
- Laravel: `php artisan test` หยุดที่ memory limit 128 MB ใน PosWorkbenchTest; fallback `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' php -d memory_limit=1G vendor/bin/phpunit` exit 0, runner tests=753 / passed=751 / assertions=6199 / skipped=2 / incomplete=6; ไม่ถือ skipped/incomplete ว่าผ่าน. `git diff --check` ผ่าน. ไม่แก้ PHP หรือ apps/pos-desktop
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่รัน Python→Laravel HTTP E2E ทั้ง 5 วิธี: local campaign runner เดิมสร้าง credential และไม่มี token/PIN สำหรับ fixture ที่ใช้ได้โดยไม่สร้างใหม่ จึงไม่ข้ามข้อห้ามสร้าง/เขียน PIN/password/token. ต้อง integration ด้วย fixture/credential ที่เจ้าของเตรียมไว้ และ UAT Windows/จอสัมผัส/เครื่องพิมพ์/ลิ้นชักจริง. หากพิมพ์หลายใบล้มเหลวกลางทาง งานพิมพ์ถูกทำ failed; การพิมพ์ซ้ำอาจได้สำเนาซ้ำ ต้องตรวจบิลก่อนพิมพ์ซ้ำ
- Deploy: **ยังไม่ deploy / ไม่ release / ไม่เรียก production หรือ legacy**
- งานถัดไป: เมื่อเขียน Git metadata ได้ ให้ reconcile remote โดยรักษางานนี้ แล้ว commit เป็นขั้น (1) payments/services/sync + service tests, (2) UI/receipt/printing + tests/capture/images, (3) handoff/memory; รัน suite แล้ว push branch เดิม. ไม่รวม .claude/.codex หรือ credentials

## Handoff - 2026-11-06 (Codex: Python POS polish 0.8.9)
- Commit: ยัง commit/push ไม่ได้; HEAD `2eb8bcf`, branch ปัจจุบัน `claude/pos-python-settings-popup`. คงไฟล์ทั้งหมดใน workspace เดิมตามคำสั่งเจ้าของ
- Git: `git pull origin main` ถูกปฏิเสธ `FETCH_HEAD: Operation not permitted`; หลัง Python suite ผ่าน ทดลอง stage ชุด promotions/order/parity/E2E แล้วถูกปฏิเสธ `index.lock: Operation not permitted` เพราะ metadata ชี้ออกนอก writable roots. ไม่ย้าย branch/clone ไม่ข้าม sandbox และไม่ push งานที่ยัง commit ไม่ได้
- ทำอะไร (brief `docs/ai/codex-pos-python-polish-089.md`):
  1. ช่องค้นหามีปุ่ม × 56×56 พร้อมชื่อสำหรับ accessibility; ล้างข้อความ คืนรายการตามหมวดเดิม และ focus พร้อมสแกน. เพิ่มสินค้าจากผลค้นหาสำเร็จแล้วล้างอัตโนมัติ; ยกเลิกกรอกน้ำหนักหรือสินค้ายังไม่มีราคาไม่ล้าง. ซ่อนการ์ดเก่าทันทีก่อน deleteLater เพื่อไม่ให้ค้างซ้อนตอนเปลี่ยนคำค้น
  2. ปรับข้อความ PIN/เปิดกะ/ปิดกะ/ใบปิดกะและสถานะบิลเป็นภาษาตรงๆ; cashier errors กรองคำเทคนิค/URL/ข้อผิดพลาดภาษาอังกฤษและบอกขั้นตอนต่อ. รายชื่อที่โหลดไม่ได้บอกให้ตรวจการเชื่อมต่อ/ใช้รายชื่อในเครื่อง ไม่โชว์ raw exception. หน้าตั้งค่าเครื่องยังมีศัพท์เทคนิคได้ตาม brief
  3. หน้าประวัติระบุเลือกบิลเพื่อยกเลิก/รับคืน, รายละเอียดมีปุ่มยกเลิกสีแดงใหญ่, หน้ายอดขายวันนี้/บิลล่าสุดมีปุ่มเข้าประวัติเพื่อยกเลิก/รับคืน. เหตุผลมีสามปุ่มสำเร็จรูปและพิมพ์เองได้ สูงสุด 500 ตัวอักษร; เลือกหัวหน้าจาก role=manager ที่ ERP ส่งมา แล้วใส่ PIN บน PinPad เดิม. เรียก provisioning.verify_approver (`/api/pos/approver/verify`) ก่อน receipt/void หรือ receipt/return; เหตุผล verify ตัดที่ 200 ตาม validation ส่วนเหตุผลใบยกเลิก/คืนเก็บเต็ม 500. ไม่เปลี่ยนคนขายและไม่เก็บ PIN ลงฐาน/คิว; ล้าง PIN ออกจาก payload ทั้ง success/failure
  4. แยก blocker ไม่เปิดกะ/ออฟไลน์/กะยังไม่ส่ง/บิลยังไม่ส่ง/บิลยกเลิกแล้ว; void จำกัดบิลวันนี้ในกะเดิมและแนะนำรับคืนเมื่อเป็นบิลเก่า. หัวหน้าไม่ผ่านไม่ส่งคำขอ void/return; ถ้าส่งแล้ว timeout ไม่ mark void และบอกตรวจ ERP ก่อนทำซ้ำ. รับคืนแสดงชัดว่าเป็นคำขอรออนุมัติใน ERP ก่อนคืนเงินจริง ไม่ mark บิลเดิมเป็น void
  5. โปรสินค้าเดียวกันรวม qty ทุกบรรทัดด้วย Decimal สำหรับ discount baht/percent, bundle และจำนวน free_item ที่ Order สร้าง. คงราคาฐาน list_price, การกระจายส่วนลดเดิมและโปรอีก 8 แบบ. แก้ fixture parity ที่เคยคาด firstWhere และ expected ใน campaign HTTP runner ให้เป็น aggregate (ยังไม่ได้รัน HTTP ดูข้อจำกัด)
  6. จำนวนของแถมแสดงเลขปกติ 1 / 1.5 / 1.25 แทนเลขทศนิยม 8 ตำแหน่ง โดยไม่แก้จำนวนที่บันทึก
- สินค้ากิโลกรัมรอเจ้าของตัดสินใจ: `104011` “BB-B ก้าวหน้า (ถุง10kg.)” หน่วยกิโลกรัม แต่ไม่มีชื่อ ชั่ง/ซั่ง และไม่มี PLU 800/801 จึงยังไม่ถามน้ำหนักตามกติกา is_scale เดิม. **ไม่ได้เปลี่ยนกติกา**; เพิ่ม regression ยืนยันว่าหน่วยกิโลกรัมอย่างเดียวไม่เปิด dialog น้ำหนัก. ต้องให้เจ้าของตัดสินใจว่าจะเปลี่ยน master/is_scale หรือกติกา ERP ก่อน
- ข้อจำกัด ERP ที่ตรวจจาก source จริง: `PosPricingGuard::qtyPromotionDiscount` ใน checkout นี้ยังใช้ firstWhere ไม่ใช่ PR #6 aggregate; `PosApiController::cashierRole` ยังระบุ GM/BRANCH_MGR เท่านั้น ไม่รวม IT_MGR. Python ใช้ role=manager จาก ERP และให้ API ตรวจสิทธิ์ขั้นสุดท้าย ไม่ยกระดับสิทธิ์เอง. **IT_MGR ยังไม่รับรองว่าอนุมัติได้ และห้ามใช้ Python aggregate กับ ERP รุ่น firstWhere ก่อนปรับรุ่นให้ตรงกัน**. ไม่แก้ PHP หรือ apps/pos-desktop ตาม brief
- ทดสอบ: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python -m pytest apps/pos-python/tests -q` **397 passed / 26.48s**. หลังปรับ subtitle/จำกัด verify reason 200 รันเฉพาะ `test_polish_089.py` ซ้ำ **21 passed / 3.73s**. เทสต์ใหม่ครอบ 2+2/list-price/เศษจำนวน/หลายชุดของแถม/จำนวนแสดงผล, ปุ่มล้าง/focus/เลือกสินค้า/รายการคืน/ไม่มีการ์ดซ้อน/is_scale, void+return endpoint order/เหตุผล preset+พิมพ์เอง/PIN cleanup, blocker/บิลเก่า/approval failure/timeout. ไม่สร้าง PIN/password/token ใช้ empty hash ที่ล็อกอินไม่ได้และ object sentinel ผ่าน mock เท่านั้น
- Mutation ถอดตัวแก้ทีละจุดแล้วแดงจริง: aggregate ส่วนลด 3 failed; aggregate จำนวนของแถม 1 failed; ล้างหลังเลือกสินค้า 1 failed; gift format 3 failed; verify ก่อน void/return 2 failed. ทุกครั้ง exit 1 และคืน source ใน finally; suite หลังคืนผ่านตามข้างต้น
- ภาพ: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python apps/pos-python/tools/capture_089.py`; ตรวจภาพ 8 ใบ 1024×768 ใน `docs/ai/pos-python-shots/089/`: search-clear/search-restored/void-history/void-details/void-reason/void-manager/void-pin/void-result. ใช้ temporary SQLite + mocked ERP ไม่มี network/credential; tool ใช้ fixture ใน test_polish_089.py เพื่อให้หน้าจอและเทสต์ใช้ข้อมูลชุดเดียวกัน. ทุกครั้งที่รัน Qt ตั้ง offscreen
- Laravel: `php artisan test` exit 2 เพราะ memory 128MB ที่ PrintablePageSizeTest; fallback `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' php -d memory_limit=1G vendor/bin/phpunit` exit 0: runner tests=753 / passed=751 / assertions=6199 / skipped=2 / incomplete=6. ไม่ถือ skipped/incomplete ว่าผ่าน. `git diff --check` ผ่าน; ไม่มี PHP/Vue เปลี่ยน
- ยังไม่ทดสอบ/ความเสี่ยง: ไม่มี HTTP E2E กับ ERP aggregate/IT_MGR เพราะ source ERP นี้ยังเก่าและไม่สร้าง credentials ตามข้อห้าม; ไม่รัน campaign runner ที่สร้าง credential. ต้อง Windows/จอสัมผัส/เครื่องพิมพ์จริง UAT. วันที่ภาพใช้เวลาระบบ fixture ณ ตอนรัน ไม่ใช่ธุรกรรมจริง
- Deploy: **ยังไม่ deploy / ไม่ build release / ไม่เรียก production หรือ legacy**. APP_VERSION ยังคง dev จน workflow รับเลขรุ่นจากเจ้าของ
- งานถัดไป: เมื่อเขียน Git metadata ได้ ให้ reconcile remote โดยรักษาไฟล์ทั้งหมด แล้ว commit เป็นขั้น (1) aggregate promotions/order/parity/E2E expectations, (2) cashier UI/wording/tests/capture/images, (3) handoff/memory; ทดสอบแล้ว push branch เดิม. ต้องตรวจ ERP PR #6 และ role IT_MGR ให้ตรงก่อน UAT รวมระบบ; ห้ามแก้ PHP/ปล่อยใช้งานโดยไม่มีคำสั่งแยก

## Handoff - 2026-11-06 (Codex ต่อ polish + void 0.8.9)
- Commit: ไม่มี commit/push ตามคำสั่งเจ้าของ เก็บงานเดิมและงานเพิ่มใน workspace HEAD `2eb8bcf`; pull ถูก sandbox ปฏิเสธ FETCH_HEAD ภายนอก writable roots
- ทำอะไร: ตรวจงาน polish เดิมและเติม flow void เพิ่มเติม ช่องค้นเลขบิลในประวัติค้นได้ทั้ง document_no และ server_receipt_no ครอบคลุมบิลเกิน 100 รายการเดิม เลือกบิล → เหตุผล → เลือกหัวหน้าที่ active/ไม่ revoked/ERP role manager → PIN pad → verify → void/return
- PIN: ทุก ValueDialog แบบ PIN ใช้ Password echo และจุด ● ล้าง entry หลังยืนยัน/ยกเลิกก่อนคืนค่า; login/verify/change PIN ล้าง field ลับใน request dict ผ่าน finally ทั้งสำเร็จและล้มเหลว; void/return ล้างก่อนแจ้งผล ไม่รอผู้ใช้ปิด popup. ข้อผิดพลาดเข้างานใช้ข้อความคงที่เพื่อไม่สะท้อน PIN จาก ERP. ไม่เพิ่ม PIN/password/token หรือเรียก production
- เทสต์ใหม่: ค้น local/ERP bill และล้างตัวกรอง, PIN masked/จุด/ล้างเมื่อ accept และ cancel/ไม่มี log, รายชื่อไม่มี cashier/manager inactive/revoked. ปรับเทสต์ normalization เดิมให้ตรวจ PIN ภายใน mock ขณะส่ง และตรวจว่าถูกล้างหลังส่ง
- Mutation เพิ่มเติม: ถอด entry.clear → เทสต์ PIN failed, ถอด filter → เทสต์ค้นบิล failed (exit 1 ทั้งคู่) แล้วคืนโค้ดและรัน suite ใหม่
- ภาพ: capture_089.py สร้างภาพ 8 ใบใหม่แบบ offscreen 1024×768 ใน docs/ai/pos-python-shots/089/ ตรวจภาพประวัติที่มีช่องค้นเลขบิลแล้ว
- Laravel: vendor เดิมไม่ครบ ติดตั้งใหม่ตาม composer.lock ด้วย --no-scripts (สำรอง vendor เดิมไว้ /private/tmp/pos-089-vendor-backup-*). php artisan test ชน memory limit ของ child process; รัน APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' php -d memory_limit=1G vendor/bin/phpunit สำเร็จ tests=753 passed=751 assertions=6199 skipped=2 incomplete=6 ไม่มี failed; skipped/incomplete ยังไม่ได้ผ่านการทดสอบ
- ข้อจำกัดยังคงเดิม: PHP checkout นี้ใช้ firstWhere และ cashierRole รองรับ GM/BRANCH_MGR ยังไม่รวม IT_MGR จึงยังยืนยัน integration aggregate/IT admin ไม่ได้ใน repo นี้ ตาม brief ห้ามแก้ PHP. ต้อง ERP รุ่นที่รวม PR #6 และรองรับ IT_MGR ก่อน UAT รวมระบบ; ไม่ยกระดับ role เอง. สินค้า 104011 หน่วยกิโลกรัมยังยึด is_scale เดิม รอเจ้าของตัดสินใจ
- Deploy: ยังไม่ deploy; รอ Windows/touch/printer hardware UAT
- ผล Python รอบสุดท้ายหลังแก้ทั้งหมด: QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python -m pytest apps/pos-python/tests -q → **400 passed in 31.14s**, exit 0; git diff --check ผ่าน. ทุก Qt invocation ในรอบนี้ตั้ง offscreen
## Handoff - 2026-11-06 (Python POS: clear stuck dialog backdrop)
- Branch: `claude/pos-python-settings-popup`
- ทำอะไร: แก้ popup ของ `apps/pos-python` ที่หลังยกเลิกบิลอาจเหลือ overlay สีเทาทับทั้งหน้าจอ โดยให้ `TouchDialog` ซ่อน backdrop ทั้ง `finished`, `done`, `hideEvent` และ `closeEvent`; ยก dialog ขึ้นเหนือ backdrop ทุกครั้งเพื่อไม่ให้แผ่นเทาทับตัว popup
- Compatibility: เปลี่ยน runtime ทุกจุดจาก `QDialog.Accepted` เป็น `QDialog.DialogCode.Accepted` ซึ่งเป็น enum ของ PySide6 รุ่น Windows ที่ใช้จริง ป้องกัน error `QDialog object has no attribute 'Accepted'` ระหว่างเปิด/ปิด popup
- ทดสอบ: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python -m pytest apps/pos-python/tests/test_close_shift_wizard.py apps/pos-python/tests/test_touch_popups.py apps/pos-python/tests/test_polish_089.py -q` ผ่าน **36 passed**; เพิ่ม regression ยืนยันว่า popup ที่ปิดแล้วไม่เหลือ backdrop แสดงอยู่; `git diff --check` ผ่าน
- Suite เต็ม: เริ่ม `pytest apps/pos-python/tests -q --disable-warnings` แล้วแสดงผลผ่านถึงช่วงท้าย แต่คำสั่งเกิน timeout 30 วินาทีของ session นี้ จึงยังไม่บันทึกว่า suite เต็มผ่าน
- Deploy: **ยังไม่ deploy production** ตาม handoff UAT; ต้อง build UAT installer ใหม่และติดตั้งทับบนเครื่องทดสอบก่อนลองยกเลิกบิลอีกครั้ง
## Handoff - 2026-11-06 (Python POS: simplified cashier flow)
- Branch: `claude/pos-python-settings-popup`
- ชำระเงิน: หน้าขายแสดงเฉพาะ `เงินสด` และ `โอน / QR`; โอนใช้เพียงเลขท้ายบัญชีผู้โอน 4 หลัก, ตัด checkbox ตรวจยอดและช่องเลขอ้างอิงที่ซ้ำซ้อนออก การกด `รับเงิน / ออกบิล` เป็นคำยืนยันของแคชเชียร์เอง โดยยังส่งเลขท้าย 4 หลักตามสัญญา ERP
- เงินสดระหว่างกะ: ตัดเมนูเลือกหลายประเภทออกจากหน้าขาย เหลือบันทึก `นำส่งเงินระหว่างกะ` โดยตรง; ไม่เปลี่ยน API หรือยอดเงินที่บันทึก
- หน้าจอ: เปลี่ยนปุ่ม `ล็อก/สลับคนขาย` เป็น `ล็อกหน้าจอ` เพื่อสื่อว่าเป็นการพักเครื่อง ไม่ใช่สลับกะ; เปลี่ยน `ออกงาน` เป็น `ปิดกะ`; ประวัติบิลใช้สองบรรทัด ตัดเลขยาวกลางข้อความ และปิด horizontal scroll
- ทดสอบ: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python -m pytest apps/pos-python/tests/test_payments.py apps/pos-python/tests/test_payment_touch.py apps/pos-python/tests/test_touch_popups.py apps/pos-python/tests/test_close_shift_wizard.py -q` ผ่าน **47 passed**; `git diff --check` ผ่าน
- Deploy: **ยังไม่ deploy production**; ต้อง build/install UAT ใหม่ก่อนตรวจบน Windows 1024×768
## Handoff - 2026-11-06 (Python POS: UAT build follow-up)
- แก้ test เดิม 2 จุดที่ยังบังคับให้ POS แสดง checkbox ตรวจยอดโอน ซึ่งไม่ตรง flow ใหม่; เปลี่ยนเป็นตรวจว่าการโอนต้องมีเลขท้ายบัญชีผู้โอน 4 หลัก และไม่มีข้อความ/handler checkbox ซ้ำซ้อน
- ทดสอบ: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python -m pytest apps/pos-python/tests/test_pos_service.py apps/pos-python/tests/test_ui_style.py apps/pos-python/tests/test_payments.py -q` ผ่าน **76 passed**; `git diff --check` ผ่าน
- CI ก่อนแก้: GitHub Actions run `42189856059` ล้มเฉพาะ test เก่าทั้ง 2 จุด ไม่ได้ build installer; commit ถัดไปต้อง trigger Windows UAT build ใหม่
- Deploy: **ยังไม่ deploy production**

## Handoff - 2026-11-06 (Codex — Windows UAT build recovery)
- Branch/source: `claude/pos-python-settings-popup` @ `48493c3`
- ทำอะไร: ตรวจ log ของ run `42189856059` พบว่า fail จาก assertion เก่าที่บังคับข้อความ/checkbox ยืนยันเงินเข้า; source และ test ปัจจุบันใช้ flow โอนแบบเลขท้ายบัญชี 4 หลักแล้ว
- ทดสอบ local: `pytest` ผ่าน **397 tests**; `git diff --check` ผ่าน
- Windows UAT: run `42214670350` ผ่านครบทั้ง dependency install, tests, version stamp, PyInstaller, packaged self-check, Inno Setup, installer verification และ upload artifact
- Artifact: `PopCentral-POS-UAT-0.9.0` ขนาดประมาณ 178.8 MB; `publish=false` จึงยังไม่เผยแพร่หน้าโหลดและไม่สร้างธุรกรรม production
- Deploy: ยังไม่ deploy และยังไม่ publish
- งานถัดไป: ดาวน์โหลด artifact ไปติดตั้งบน Windows UAT จริง ตรวจจอ 1024×768, touch, scanner, scale และ printer ก่อนเจ้าของสั่ง publish/release

## Handoff - 2026-11-06 (Codex: fix first-launch pairing crash)
- Commit: `1be701e`
- ทำอะไร: แก้ `run_pairing_wizard()` ของ `apps/pos-python` ที่เรียก `dialog.Accepted` จาก instance ซึ่งไม่มีใน PySide6 ทำให้เครื่องติดตั้งใหม่ล้มก่อนแสดงหน้าวาง Token/เชื่อม ERP; เปลี่ยนเป็น `QDialog.DialogCode.Accepted` และเพิ่ม regression test เส้นทางเปิดครั้งแรก
- ทดสอบ: `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-parity-env/bin/python -m pytest -q apps/pos-python/tests` ผ่าน **398 passed**; compileall ด้วย isolated pycache ผ่าน; `git diff --check` ผ่าน
- ยังไม่ทดสอบ/ความเสี่ยง: ยังไม่ได้ติดตั้ง artifact ใหม่บน Windows จริง และยังไม่ได้ทดสอบ HTTP กับ ERP production ด้วย credential ของเจ้าของ
- Deploy: **ยังไม่ deploy / ไม่ publish**
- งานถัดไป: push branch นี้และสั่ง Windows UAT build ใหม่จาก commit `1be701e`; ติดตั้งทับเครื่องทดสอบ แล้วลองเปิดเครื่องใหม่ที่ยังไม่มี `pos-config.json` จากนั้นวาง Token และกดตรวจสอบ

## Handoff - 2026-11-06 (Codex: diagnose production Products 500)
- Commit: เอกสารนี้อยู่ใน commit ถัดจาก `501bd8f`
- พบจาก production log แบบอ่านอย่างเดียว: `/products` ตอบ 500 เวลา 19:20–19:21 เพราะ `Route [units.index] not defined` ที่ `resources/views/products/index.blade.php:29`
- สาเหตุ: ไฟล์ view บน production ถูกแก้ค้างใน working tree วันนี้และเรียก route `units.index` แต่ route ที่มีจริงคือ `product-units.index`; source ที่ tracked ใน workspace ใช้ `product-units.index` ถูกต้องอยู่แล้ว
- หลักฐานเพิ่มเติม: production `/var/www/jeterp` มีไฟล์เปลี่ยน/ใหม่ค้างประมาณ 783 รายการ จึง **ห้าม reset/restore ทั้ง repo** เพราะอาจทับงานอื่น; ยังไม่ได้แก้ไฟล์ production, ยังไม่ได้แตะฐานข้อมูล และยังไม่ได้ deploy
- ทดสอบ source ที่ถูกต้อง: `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' php -d memory_limit=1G vendor/bin/phpunit --filter ProductCatalogFilterTest` ผ่าน **3 tests / 34 assertions**
- งานถัดไป: เจ้าของอนุมัติให้แก้ production เฉพาะ `resources/views/products/index.blade.php` หรือ deploy commit ที่สะอาด แล้วตรวจ `/products` ด้วยบัญชีจริง; ห้ามใช้ `git reset --hard` บน server

## Handoff - 2026-11-06 (Codex: production Products hotfix deployed)
- Commit: source เดิมที่ tracked ของ production (`HEAD`); ไม่มีการ deploy ทั้ง repo เพราะ production มีงานค้าง 783 ไฟล์
- ทำอะไร: สำรอง view redesign ที่พังไว้ 2 ไฟล์ แล้วกู้เฉพาะ `resources/views/products/index.blade.php` จาก `HEAD` ซึ่งใช้ route ที่มีจริง (`product-units.index`, `products.*`, `scale-prices.index`); ล้าง compiled views ด้วย `php artisan view:clear`
- ตรวจผลจริง: รีโหลด session ที่ล็อกอินอยู่แล้วหน้า `https://erp.popstarcenter.com/products` กลับมาเป็นหน้า “สินค้า - POPSTAR ERP” แสดงตัวกรอง รายการสินค้า และปุ่มจัดการหน่วยนับได้ตามปกติ
- ความปลอดภัย: ไม่ reset ทั้ง repo, ไม่แตะฐานข้อมูล, ไม่รัน migration, ไม่ลบงานอื่น; backup อยู่บน server ที่ `resources/views/products/index.blade.php.pre-products-route-fix-20261106-1945` และ `resources/views/products/index.blade.php.pre-products-view-restore-20261106-1948`
- Deploy: **hotfix เฉพาะหน้า Products เสร็จแล้ว**; full repository deploy ยังไม่ทำเพราะ working tree production มีงานค้างจำนวนมาก
- งานถัดไป: ตรวจ/รวมงานค้างบน production ให้เป็น commit ที่ตรวจ test แล้วก่อนทำ full deploy; ห้ามใช้ `git reset --hard` เพื่อแก้ความสะอาดของ working tree

## Handoff - 2026-11-06 (Codex: remove identified Products redesign artifacts)
- ทำอะไร: ลบเฉพาะไฟล์ redesign/backup ของหน้า Products ที่ระบุได้ว่าเป็นชุดที่ทำให้ 500 (`index.blade.php.pre-products-route-fix-20261106-1945` และ `index.blade.php.pre-products-view-restore-20261106-1948`)
- คงไว้: `resources/views/products/index.blade.php` ที่กู้จาก `HEAD` และตรวจแล้วไม่มี route `units.index`, `products.create` หรือ `scale.index`
- ขอบเขต: ไม่ลบเหมารวมไฟล์ค้างอื่นประมาณ 784 รายการ เพราะไม่มีหลักฐานว่าเป็นของ Gemini/Antigravity และอาจเป็นงานของผู้ใช้อื่น; ไม่แตะฐานข้อมูล
- Deploy: **ลบ artifacts ที่ระบุได้แล้ว**; หน้า Products ที่ใช้งานจริงยังคงอยู่

## Handoff - 2026-11-09 (Codex Python POS 0.9.1)
- Commit — ไม่มี commit/push ตามคำสั่งเจ้าของ งานอยู่ใน workspace branch `claude/pos-python-settings-popup` จาก HEAD `67e61dc`. `git pull origin main` ถูก sandbox ปฏิเสธการเขียน FETCH_HEAD ใน Git metadata ภายนอก writable roots จึงทำต่อจาก HEAD ตาม brief
- ทำอะไร — ทำตาม `docs/ai/codex-pos-python-091.md` ทั้งสามข้อ แก้เฉพาะ Python และ handoff
  - เพิ่ม `local_datetime_text` ใน time_service สำหรับแปลง ISO timestamp เป็น timezone ของเครื่องตอนแสดงผล รองรับ Z/offset และการข้ามวัน ค่ารุ่นเก่าที่ไม่มี offset ถือเป็น UTC ตาม clock ที่ใช้เก็บข้อมูลเดิม ค่าไม่ถูกต้องยังแสดงเป็นข้อความเดิม และค่าว่างแสดง `-`. ไม่มีการเปลี่ยน timestamp ในฐานข้อมูล
  - ใช้ตัวแปลงเดียวกันในใบเสร็จ/สำเนา/พิมพ์ซ้ำ, รายการประวัติและรายละเอียดบิลที่ใช้ยกเลิก/คืน, หน้าพบกะเดิม, Z/ใบปิดกะ, เวลาบิลล่าสุดในยอดขายรายวัน, sync logs และสถานะซิงก์ชุดข้อมูล. การจัดกลุ่มวันธุรกิจและเงื่อนไขบิลวันนี้ยังอิงวันไทยตามกติกาเดิม
  - กดรับชำระโอน/ผสมแล้วบันทึก `confirmed_at` จาก clock ของ checkout โดยอัตโนมัติ และ UI ส่ง payment_confirmed จากวิธีชำระหลังยืนยันรับเงิน ป้องกันการอิง checkbox ที่ซ่อนอยู่. sync payload โอน/ผสมส่ง `payment_confirmed=true` พร้อม `transfer_account_last4`. ไม่เพิ่ม checkbox หรือ migration
  - ตรวจ lifecycle backdrop เดิมจาก 7ce2e28 ด้วย modal Qt จริงและ QTimer. ผ่านทั้งยกเลิก/คืนในกรณีสำเร็จ, PIN ผิด, ERP ปฏิเสธ, timeout, offline, ยกเลิกเหตุผล/เลือกหัวหน้า/PIN. เมื่อ popup จบ backdrop ถูกซ่อน หน้ารายละเอียดกลับมาได้ และปิดรายละเอียดแล้วไม่มี backdrop ค้าง จึงไม่มีการแก้ touch_dialogs เพิ่ม
- ทดสอบ — Python 3.12.16 + PySide6 6.12.0 ใน `/private/tmp/pos-091-py312` ติดตั้งจาก requirements-test.txt. ทุก Qt invocation ตั้ง `QT_QPA_PLATFORM=offscreen`. เทสต์ใหม่ `test_pos_091.py` ผ่าน 28 เคส ใช้ tzinfo Bangkok แบบ fixed UTC+7 โดยตรง ไม่มี time.tzset และอ่านไฟล์ด้วย encoding="utf-8". ไม่มีการสร้าง/เขียน PIN หรือ token และไม่มี network ไป ERP ในเทสต์ใหม่
- Suite เต็ม — `QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python /private/tmp/pos-091-py312/bin/python -m pytest apps/pos-python/tests -q` ผ่าน **426 passed / 63.31s** ก่อน mutation
- Mutation — ถอดตัวแก้ทีละจุดและคืน source ใน finally ทุกครั้ง เทสต์แดงจริง exit 1 ทั้งสี่จุด คือไม่แปลง timezone 4 failed, ไม่บันทึก confirmation อัตโนมัติ 2 failed, UI กลับไปอ่าน checkbox 1 failed, ปิดการซ่อน backdrop 16 failed. Mutation runner อยู่ใน /private/tmp ไม่เพิ่ม artifact ใน repo
- ตรวจเพิ่มเติม — compileall ผ่าน และ git diff --check ผ่าน. `php artisan test` เริ่มไม่ได้ exit 255 เพราะ workspace ไม่มี vendor/autoload.php. ไม่มี PHP/Vue เปลี่ยน
- ยังไม่ทดสอบ/ความเสี่ยง — ยังไม่มี Windows/เครื่องพิมพ์/จอสัมผัสจริง UAT หรือ HTTP integration กับ ERP จริง. ต้องตั้ง timezone ของเครื่อง POS ให้ถูกต้องเพื่อให้แสดงเวลาตามสถานที่ใช้งาน
- Deploy — ยังไม่ deploy หรือ build release
- งานถัดไป — UAT Windows ตรวจเวลาใบเสร็จ/พิมพ์ซ้ำ/Z และบิลโอนที่ส่ง ERP ตามการอนุญาตของเจ้าของ
- ผลยืนยันหลังคืน mutation ทั้งหมด — suite เต็มรอบสุดท้ายผ่าน **426 passed / 62.28s**, exit 0. `git diff --check` รอบส่งมอบผ่าน
