# POS layout contract

สัญญาเดียวที่ ERP, Web POS และ PopCentral POS (Python) ใช้ร่วมกัน
แหล่งความจริงคือ `App\Support\PosLayout` — **ห้ามคัดลอกค่า default หรือช่วงค่าไปไว้ที่อื่นอีก**

## ที่เก็บค่า

| AppSetting key | ความหมาย |
|---|---|
| `pos_layout_draft` | แบบร่าง ยังไม่มีผลกับสาขา |
| `pos_layout_published` | ตัวที่หน้าขายและเครื่อง POS ใช้จริง |
| `pos_layout_version` | เลขรุ่น เพิ่มทีละ 1 ทุกครั้งที่กด Build & Publish |
| `pos_layout_published_at` | เวลาที่ publish (ISO 8601) |

## โครงสร้าง

```jsonc
{
  "schema": "popcentral-pos-layout",
  "version": 7,            // เท่ากับ pos_layout_version ตอนที่ publish
  "layout_version": 7,     // ชื่อที่ชัดเจนสำหรับฝั่ง client ใช้เทียบว่าต้องรีเฟรชไหม
  "canvas": { "columns": 12, "rows": 12 },
  "components": [ { "id": "cart", "type": "cart", "x": 8, "y": 1, "w": 5, "h": 5 } ],
  "runtime": {
    "product_width": 55,        // 30-70 (%) — รวมกับ cart_width ต้องได้ 100
    "cart_width": 45,           // 30-70 (%)
    "product_rows": 3,          // 1-8
    "product_columns": 4,       // 2-8
    "density": "comfortable",   // compact | comfortable | roomy
    "button_size": "medium",    // small | medium | large
    "show_branch": true,
    "show_terminal": true,
    "show_seller": true,
    "show_shift": true
  }
}
```

`components` คือของเดิม ไม่เปลี่ยนรูปแบบ เพื่อให้ POS Python รุ่นที่ติดตั้งไปแล้วยังอ่านได้
`runtime` กับ `layout_version` เป็นของใหม่ client รุ่นเก่าจะข้ามไปเอง

## ตัวแปร CSS

`/api/pos/ping` แนบ `pos_layout.css` ที่คำนวณแล้วมาด้วย (มาจาก `PosLayout::cssVariables()`)
client จึงไม่ต้องมีตาราง density/button ของตัวเองให้ค่าเพี้ยนกัน

| ตัวแปร | ที่มา |
|---|---|
| `--pos-product-fr`, `--pos-cart-fr` | `product_width` / `cart_width` |
| `--pos-product-columns`, `--pos-product-rows` | ตารางสินค้า |
| `--pos-card-min-height`, `--pos-card-font-size` | `density` |
| `--pos-grid-gap`, `--pos-grid-padding` | `density` |
| `--pos-button-min-height`, `--pos-button-font-size`, `--pos-button-padding` | `button_size` |

ค่าจริงของแต่ละ density/button_size อยู่ใน `PosLayout::DENSITY_METRICS` และ `PosLayout::BUTTON_METRICS`

## ใครอ่านที่ไหน

- **หน้า Designer** `settings/pos-designer` — แก้ทั้ง components และ runtime, มี preview, validation และปุ่มคืนค่าเริ่มต้น
- **Web POS** `resources/views/pos/browser.blade.php` — server render ตัวแปร CSS ตั้งแต่ HTML ก้อนแรก
  แล้วทับด้วย `config.pos_layout.css` หลัง `/api/pos/ping` สำเร็จ (ฟังก์ชัน `applyPosLayout`)
- **หน้าพรีวิว** `pos/preview` — ใช้ตัวแปรชุดเดียวกัน
- **POS Python** — `provisioning.py` เก็บทั้งก้อนลง `device_settings.pos_layout` อยู่แล้ว
  `runtime` จึงเดินทางไปถึงเครื่องโดยไม่ต้องแก้ sync (ดู handoff ใน `docs/ai/task.md`)

## กติกา

- ค่าที่ผู้ใช้เพิ่งกรอก **ต้องผ่าน validation และเห็นข้อความเมื่อผิด** (`PosLayout::runtimeRules()`)
- ค่าที่อ่านจากฐานข้อมูล **ต้องไม่ทำให้หน้าขายล่ม** จึงถูก clamp เงียบๆ ด้วย `PosLayout::normalize()`
  และถ้าอ่าน AppSetting ไม่ได้เลยให้ถอยไปใช้ค่าเริ่มต้นแทนการโยน 500
- ห้าม hard-code สัดส่วน 55/45 หรือจำนวนแถวลงในกฎ CSS อีก ให้แก้ผ่านตัวแปรข้างบนเท่านั้น
