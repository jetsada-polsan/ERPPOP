#!/usr/bin/env python3
"""ดึงข้อมูลสินค้าทั้งหมดจาก BPlus เป็นไฟล์ JSON เพื่อให้สินค้าใน ERP ตรงกับ BPlus

อ่านอย่างเดียว: SELECT เท่านั้น (read_only=True) ไม่แก้ข้อมูล BPlus
ถามรหัสผ่านแบบซ่อนตัวอักษร ไม่บันทึกรหัสผ่านลงไฟล์

ใช้: python3 scripts/bplus-master-export.py
ได้ไฟล์ bplus-products-<วันที่>.json แล้วส่งเข้า ERP ด้วย
  ssh root@27.254.143.219 "cd /var/www/jeterp && php artisan etl:master-data --from-file=- --dry-run" < bplus-products-<วันที่>.json

คิวรีเหมือน app/Services/Etl/MssqlMasterDataSourceService.php (ส่วนสินค้า) แต่เก็บเฉพาะคอลัมน์ที่ ERP ใช้
"""
import datetime
import decimal
import getpass
import json

import pymssql

host = "192.168.88.200"
database = "BPLUSERP_POPSTAR_2021"
print(f"ดึงข้อมูลสินค้าทั้งหมดจาก {database}")
user = input("user: ").strip()
password = getpass.getpass("password (ไม่แสดงตัวอักษร): ")

connection = pymssql.connect(host, user, password, database, as_dict=True, read_only=True)
cursor = connection.cursor()

# คอลัมน์ที่ MasterDataEtlService อ่าน (ถ้า BPlus ไม่มีคอลัมน์ไหน ก็ข้ามไป เพราะ SELECT * ไม่ล้ม)
SECTIONS = {
    "categories": ("SELECT * FROM ICCAT ORDER BY ICCAT_KEY", ["ICCAT_CODE", "ICCAT_NAME"]),
    "departments": ("SELECT * FROM ICDEPT ORDER BY ICDEPT_KEY", ["ICDEPT_CODE", "ICDEPT_THAIDESC", "ICDEPT_ENGDESC"]),
    "brands": ("SELECT * FROM BRAND ORDER BY BRN_KEY", ["BRN_CODE", "BRN_NAME"]),
    "units": ("SELECT * FROM UOFQTY ORDER BY UTQ_KEY", ["UTQ_KEY", "UTQ_NAME", "UTQ_QTY"]),
    "products": ("""SELECT sm.*, cat.ICCAT_CODE, dept.ICDEPT_CODE, brn.BRN_CODE
                    FROM SKUMASTER sm
                    LEFT JOIN ICCAT cat ON cat.ICCAT_KEY = sm.SKU_ICCAT
                    LEFT JOIN ICDEPT dept ON dept.ICDEPT_KEY = sm.SKU_ICDEPT
                    LEFT JOIN BRAND brn ON brn.BRN_KEY = sm.SKU_BRN
                    ORDER BY sm.SKU_KEY""",
                 ["SKU_CODE", "SKU_NAME", "SKU_E_NAME", "SKU_S_UTQ", "SKU_PRICE", "SKU_ENABLE",
                  "ICCAT_CODE", "ICDEPT_CODE", "BRN_CODE"]),
    "barcodes": ("""SELECT gm.*, sm.SKU_CODE
                    FROM GOODSMASTER gm
                    JOIN SKUMASTER sm ON sm.SKU_KEY = gm.GOODS_SKU
                    ORDER BY gm.GOODS_KEY""",
                 ["GOODS_CODE", "SKU_CODE", "GOODS_UTQ", "GOODS_PRICE", "GOODS_ENABLE"]),
}


def plain(value):
    if isinstance(value, decimal.Decimal):
        return float(value)
    if isinstance(value, (datetime.date, datetime.datetime)):
        return value.isoformat()
    return value


snapshot = {"exported_at": datetime.datetime.now().isoformat(timespec="seconds"), "database": database}
for section, (sql, keep) in SECTIONS.items():
    cursor.execute(sql)
    snapshot[section] = [{key: plain(row[key]) for key in keep if key in row} for row in cursor.fetchall()]

connection.close()
name = f"bplus-products-{datetime.date.today().isoformat()}.json"
with open(name, "w", encoding="utf-8") as handle:
    json.dump(snapshot, handle, ensure_ascii=False)

print(f"เสร็จ บันทึกที่ {name}: " + " · ".join(f"{s} {len(snapshot[s])}" for s in SECTIONS))
