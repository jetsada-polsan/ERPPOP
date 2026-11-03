#!/usr/bin/env python3
"""ส่งออกบาร์โค้ดที่ขายจริงใน BPlus ปี 2026 พร้อมหน่วยและตัวคูณ: อ่านอย่างเดียว ใช้ SELECT เท่านั้น

ผลเป็น data/bplus-barcodes-2026.csv คอลัมน์ sku_code, barcode, unit_name, unit_factor, lines, last_sold
ไม่ดึงชื่อลูกค้าหรือรายละเอียดบิล ถามรหัสผ่านแบบซ่อนตัวอักษร ไม่บันทึกรหัสผ่าน
"""
import csv
import getpass

import pymssql

host = input("MSSQL host [192.168.88.200]: ").strip() or "192.168.88.200"
database = input("ชื่อฐานข้อมูล [BPLUSERP_POPSTAR_2021]: ").strip() or "BPLUSERP_POPSTAR_2021"
user = input("user [jetsada]: ").strip() or "jetsada"
password = getpass.getpass("password (ไม่แสดงตัวอักษร): ")

connection = pymssql.connect(host, user, password, database, as_dict=False, read_only=True)
cursor = connection.cursor()

# รหัสที่ยิง/พิมพ์ตอนขาย (TRD_KEYIN) กับหน่วยและตัวคูณที่ใช้ขายจริงในบรรทัดนั้น
cursor.execute("""
    SELECT s.SKU_CODE, LTRIM(RTRIM(d.TRD_KEYIN)) AS barcode, LTRIM(RTRIM(d.TRD_UTQNAME)) AS unit_name,
           d.TRD_UTQQTY AS unit_factor, COUNT(*) AS lines, MAX(i.DI_DATE) AS last_sold
    FROM TRANSTKD d
    JOIN TRANSTKH h ON h.TRH_KEY = d.TRD_TRH
    JOIN DOCINFO i ON i.DI_KEY = h.TRH_DI
    JOIN SKUMASTER s ON s.SKU_KEY = d.TRD_SKU
    WHERE i.DI_DATE >= '2026-01-01' AND LTRIM(RTRIM(ISNULL(d.TRD_KEYIN, ''))) <> ''
    GROUP BY s.SKU_CODE, LTRIM(RTRIM(d.TRD_KEYIN)), LTRIM(RTRIM(d.TRD_UTQNAME)), d.TRD_UTQQTY
    ORDER BY s.SKU_CODE, barcode""")
rows = cursor.fetchall()
connection.close()

with open("data/bplus-barcodes-2026.csv", "w", encoding="utf-8-sig", newline="") as handle:
    writer = csv.writer(handle)
    writer.writerow(["sku_code", "barcode", "unit_name", "unit_factor", "lines", "last_sold"])
    for sku, barcode, unit, factor, lines, last in rows:
        writer.writerow([str(sku).strip(), barcode, unit, f"{float(factor):g}", lines, last.date() if last else ""])

print(f"เสร็จแล้ว: data/bplus-barcodes-2026.csv ({len(rows)} แถว)")
