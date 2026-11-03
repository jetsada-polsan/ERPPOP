#!/usr/bin/env python3
"""บาร์โค้ดที่ขายจริงใน BPlus เทียบกับ SKU: อ่านอย่างเดียว ใช้ SELECT เท่านั้น ไม่แก้ข้อมูล

นับรหัสที่ยิง/พิมพ์ตอนขายจริงปี 2026 (TRANSTKD.TRD_KEYIN) เทียบกับ SKU
ถามรหัสผ่านแบบซ่อนตัวอักษร ไม่บันทึกรหัสผ่านลงไฟล์ ผลอยู่ที่ bplus-barcode-sales.txt
"""
import getpass

import pymssql

host = input("MSSQL host [192.168.88.200]: ").strip() or "192.168.88.200"
database = input("ชื่อฐานข้อมูล (เว้นว่าง = แสดงรายชื่อฐาน): ").strip()
user = input("user: ").strip()
password = getpass.getpass("password (ไม่แสดงตัวอักษร): ")

if not database:
    # ไม่รู้ชื่อฐาน: แสดงรายชื่อฐานบนเครื่องนี้ให้เลือก แล้วจบ
    connection = pymssql.connect(host, user, password, "master", as_dict=False, read_only=True)
    cursor = connection.cursor()
    cursor.execute("SELECT name FROM sys.databases ORDER BY name")
    print("ฐานข้อมูลบนเครื่องนี้:")
    for (name,) in cursor.fetchall():
        print(" -", name)
    print("รันใหม่แล้วใส่ชื่อฐานที่ใช้งานปี 2026")
    raise SystemExit(0)

connection = pymssql.connect(host, user, password, database, as_dict=False, read_only=True)
cursor = connection.cursor()
output = open("bplus-barcode-sales.txt", "w", encoding="utf-8")


def show(title: str, sql: str, limit: int = 300) -> list:
    output.write(f"\n=== {title} ===\n")
    try:
        cursor.execute(sql)
    except pymssql.Error as error:
        output.write(f"ERROR: {error}\n")
        return []
    output.write(" | ".join(column[0] for column in cursor.description) + "\n")
    rows = cursor.fetchall()
    for row in rows[:limit]:
        output.write(" | ".join(str(value).strip() for value in row) + "\n")
    return rows


show("คอลัมน์ของ SKUMASTER", """
    SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'SKUMASTER' ORDER BY ORDINAL_POSITION""")

# ขั้น 2: รหัสที่ยิง/พิมพ์ตอนขายจริงปี 2026 (TRD_KEYIN) เทียบกับ SKU ของบรรทัดนั้น
# นับเฉพาะเอกสารขาย ไม่ดึงชื่อลูกค้าหรือรายละเอียดบิล
rows = show("รหัสที่ขายจริงปี 2026 เทียบ SKU", """
    SELECT s.SKU_CODE, d.TRD_KEYIN, t.DT_DOCCODE, COUNT(*) AS lines
    FROM TRANSTKD d
    JOIN TRANSTKH h ON h.TRH_KEY = d.TRD_TRH
    JOIN DOCINFO i ON i.DI_KEY = h.TRH_DI
    JOIN DOCTYPE t ON t.DT_KEY = i.DI_DT
    JOIN SKUMASTER s ON s.SKU_KEY = d.TRD_SKU
    WHERE i.DI_DATE >= '2026-01-01'
    GROUP BY s.SKU_CODE, d.TRD_KEYIN, t.DT_DOCCODE""", limit=0)
show("ประเภทเอกสารที่นับ", """
    SELECT t.DT_DOCCODE, COUNT(*) AS lines FROM TRANSTKD d
    JOIN TRANSTKH h ON h.TRH_KEY = d.TRD_TRH JOIN DOCINFO i ON i.DI_KEY = h.TRH_DI JOIN DOCTYPE t ON t.DT_KEY = i.DI_DT
    WHERE i.DI_DATE >= '2026-01-01' GROUP BY t.DT_DOCCODE ORDER BY 2 DESC""")


def ean_ok(code: str) -> bool:
    if len(code) != 13 or not code.isdigit():
        return False
    total = sum(int(code[i]) * (1 if i % 2 == 0 else 3) for i in range(12))
    return (10 - total % 10) % 10 == int(code[12])


def kind(sku: str, code: str) -> str:
    if code == sku:
        return "พิมพ์/ยิง SKU ตรง ๆ"
    if code.isdigit() and len(code) == 13 and sku.isdigit() and code[:12] == sku.zfill(12):
        return "0 เติมครบ 12 หลัก + check digit"
    if code.isdigit() and len(code) == 13 and code.startswith(sku) and set(code[len(sku):12]) <= {"0"}:
        return "SKU + 000000 + check digit (หน่วยใหญ่)"
    if code.isdigit() and len(code) == 13 and code.startswith("885"):
        return "EAN ผู้ผลิต 885"
    if code.isdigit() and len(code) == 13 and code.startswith("2"):
        return "13 หลักขึ้นต้น 2 (ป้ายชั่ง/ภายใน)"
    if code.isdigit() and len(code) == 13:
        return "13 หลักอื่น" + ("" if ean_ok(code) else " (check digit ผิด)")
    if code.isdigit() and len(code) == 12:
        return "12 หลัก (อาจโดนตัด 0 หน้า)"
    if code.isdigit() and len(code) == 6 and code.startswith("80"):
        return "PLU เครื่องชั่ง 80xxxx"
    return f"อื่น ๆ ({len(code)} ตัวอักษร)"


summary: dict = {}
examples: dict = {}
for sku, code, doc, lines in rows:
    sku, code = str(sku).strip(), str(code).strip()
    key = (str(doc).strip(), kind(sku, code))
    count, codes = summary.get(key, (0, 0))
    summary[key] = (count + int(lines), codes + 1)
    examples.setdefault(key, [])
    if len(examples[key]) < 5:
        examples[key].append(f"{sku}->{code}")

output.write("\n=== สรุปรูปแบบรหัสปี 2026 แยกประเภทเอกสาร (บรรทัด, จำนวนรหัส) ===\n")
for (doc, label), (count, codes) in sorted(summary.items(), key=lambda item: (item[0][0], -item[1][0])):
    output.write(f"{doc} | {count} บรรทัด | {codes} รหัส | {label} | เช่น {', '.join(examples[(doc, label)])}\n")

connection.close()
output.close()
print("เสร็จแล้ว: bplus-barcode-sales.txt (ตรวจแล้วส่งให้ Claude ดูต่อ)")
