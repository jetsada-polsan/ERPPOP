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
  `normalize_pos_layout()` ใน `pos_python/ui.py` normalize ซ้ำด้วยกติกาเดียวกัน และอ่าน `css`
  ก่อนถอยไปใช้ตารางในเครื่อง ค่า density/button ที่แก้ฝั่ง ERP จึงมีผลโดยไม่ต้อง build installer ใหม่

## กติกา

- ค่าที่ผู้ใช้เพิ่งกรอก **ต้องผ่าน validation และเห็นข้อความเมื่อผิด** (`PosLayout::runtimeRules()`)
- ค่าที่อ่านจากฐานข้อมูล **ต้องไม่ทำให้หน้าขายล่ม** จึงถูก clamp เงียบๆ ด้วย `PosLayout::normalize()`
  และถ้าอ่าน AppSetting ไม่ได้เลยให้ถอยไปใช้ค่าเริ่มต้นแทนการโยน 500
- ห้าม hard-code สัดส่วน 55/45 หรือจำนวนแถวลงในกฎ CSS อีก ให้แก้ผ่านตัวแปรข้างบนเท่านั้น

## กติกา normalize ที่สองฝั่งต้องเหมือนกัน

`PosLayout::normalizeRuntime()` (PHP) กับ `normalize_pos_layout()` (Python) ต้องให้ผลเดียวกันทุกบิต
ถูกล็อกด้วย fixture ไฟล์เดียว `tests/Fixtures/pos-layout-runtime-parity.json` ที่
`tests/Unit/PosLayoutParityTest.php` และ `apps/pos-python/tests/test_pos_layout_parity.py` อ่านร่วมกัน
**แก้กติกาต้องแก้ fixture ก่อน แล้วรันเทสต์ทั้งสองชุด** ค่า expected ใน fixture คิดด้วยมือ ห้าม generate จากโค้ดฝั่งใดฝั่งหนึ่ง

| เรื่อง | กติกา |
|---|---|
| ตัวเลข | รับ int, float หรือสตริงที่ `is_numeric()` ของ PHP 8 ยอมรับทั้งก้อน (`"7.5"`, `"1e1"`, `" 5 "`, `"+45"`) |
| ไม่ใช่ตัวเลข | `null`, bool, array, `""`, `"6abc"`, `"NaN"`, `"1e400"`, `"๕"`, `"1_0"` → **ใช้ค่า default ของช่องนั้น** ไม่ใช่ขอบล่าง |
| ช่วงค่า | clamp ในโดเมน float ก่อน แล้วค่อยตัดทศนิยม (9.9 → 8 เมื่อ max = 8) |
| สองฝั่งรวมไม่ครบ 100 | `product = (product × 200 + total) ÷ (total × 2)` แบบหารจำนวนเต็ม = ปัดครึ่งขึ้น แล้ว `cart = 100 − product` |
| สวิตช์ `show_*` | ตาม `filter_var(FILTER_VALIDATE_BOOLEAN)` — `"0"`, `"off"`, `"no"`, `""` เป็น false; `null` ใช้ default |

เหตุที่ไม่ใช้ `(int)` cast ตรงๆ: PHP อ่าน `"6abc"` เป็น 6 และ `"bad"` เป็น 0 ซึ่ง Python เลียนแบบให้ตรงทุกกรณีไม่ได้
เหตุที่ไม่ใช้ `round()`: Python ปัดแบบ banker's และ float อาจได้ 62.4999 แทน 62.5

กติกานี้ใช้กับ components (`x`/`y`/`w`/`h`) ด้วย ค่าพังจะได้ default 1/1/3/2 ไม่ใช่ถูกบีบไปชิดขอบ

## พฤติกรรมที่ตั้งใจให้ต่างกันระหว่างเว็บกับ Python

- **คอลัมน์สินค้า** — เว็บใช้ `minmax(0, 1fr)` การ์ดแคบลงเรื่อยๆ ไม่ล้น ส่วน Qt ลดจำนวนคอลัมน์ลงเมื่อการ์ด
  จะแคบกว่า 100–110px เพื่อไม่ให้มี horizontal scrollbar บนจอสัมผัส (`_product_columns_for_width()`)
- **ความกว้างขั้นต่ำของแผง** — แผงบิลมีพื้น 360px ทั้งสองฝั่ง (`--pos-cart-min` บนเว็บ,
  `setMinimumWidth(360)` ใน Qt) แต่แผงสินค้าบนเว็บไม่มีพื้น (`minmax(0, …)`) ส่วน Qt มีพื้น 360px เหมือนแผงบิล
  บนจอแคบมากที่ตั้งฝั่งสินค้าไว้ต่ำ Qt จึงอาจกันพื้นที่ให้สินค้ามากกว่าเว็บเล็กน้อย
