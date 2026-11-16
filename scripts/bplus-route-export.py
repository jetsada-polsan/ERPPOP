#!/usr/bin/env python3
"""ดึงเอกสารสายขาย (ค่าเริ่มต้น B18) จาก BPlus ออกเป็นไฟล์ JSON ไว้อัปโหลดเข้า ERP หน้า "เอกสารสายขาย (ระบบเดิม)"

อ่านอย่างเดียว: SELECT เท่านั้น (read_only=True) ไม่แก้ข้อมูล BPlus
ถามรหัสผ่านแบบซ่อนตัวอักษร ไม่บันทึกรหัสผ่านลงไฟล์

ใช้: python3 scripts/bplus-route-export.py [จำนวนวันย้อนหลัง=3 หรือ month=ตั้งแต่วันที่ 1 ของเดือนนี้] [สาย=B18]
ได้ไฟล์ bplus-<สาย>-<วันที่>.json

ขอบเขต:
- ใบจอง: เล่มของสาย ตามวันที่เอกสารในช่วง
- ใบขายเชื่อ (DS/DSN): วันที่ในช่วง และมีรายการอ้างใบจองของสายนี้
- ใบเสร็จ: วันที่ในช่วง และตัดชำระใบขายเชื่อของสายนี้ (เก็บเฉพาะบรรทัดที่เป็นของสายนี้)
"""
import datetime
import decimal
import getpass
import json
import sys

import pymssql

period = sys.argv[1] if len(sys.argv) > 1 else "3"
route = sys.argv[2] if len(sys.argv) > 2 else "B18"
host = "192.168.88.200"
database = "BPLUSERP_POPSTAR_2021"
if period == "month":
    since = datetime.date.today().replace(day=1).isoformat()
else:
    since = (datetime.date.today() - datetime.timedelta(days=int(period))).isoformat()
print(f"ดึงสาย {route} ตั้งแต่ {since} จาก {database}")
user = input("user: ").strip()
password = getpass.getpass("password (ไม่แสดงตัวอักษร): ")

connection = pymssql.connect(host, user, password, database, as_dict=True, read_only=True)
cursor = connection.cursor()


def rows(sql: str, params: tuple = ()) -> list[dict]:
    cursor.execute(sql, params)
    return cursor.fetchall()


def num(value) -> float:
    return float(value or 0)


def text(value):
    return value.strip() if isinstance(value, str) else value


def stamp(value):
    return value.strftime("%Y-%m-%d %H:%M:%S") if value else None


ROUTE_BOOKINGS = """SELECT d.DI_KEY FROM DOCINFO d JOIN DOCTYPE t ON t.DT_KEY = d.DI_DT WHERE t.DT_DOCCODE = %s"""
ROUTE_SALES = f"""SELECT DISTINCT h.TRH_DI FROM TRANSTKD l JOIN TRANSTKH h ON h.TRH_KEY = l.TRD_TRH
    WHERE l.TRD_REFER_DI IN ({ROUTE_BOOKINGS})"""


def lines_of(di_key: int) -> list[dict]:
    result = []
    for line in rows("""
        SELECT l.TRD_SEQ, s.SKU_CODE, g.GOODS_CODE, l.TRD_UTQNAME, l.TRD_UTQQTY, l.TRD_QTY, l.TRD_Q_FREE,
               l.TRD_K_U_PRC, l.TRD_DSC_KEYINV, l.TRD_B_AMT, l.TRD_VAT_TY, l.TRD_REFER_REF
        FROM TRANSTKD l JOIN TRANSTKH h ON h.TRH_KEY = l.TRD_TRH
        LEFT JOIN SKUMASTER s ON s.SKU_KEY = l.TRD_SKU LEFT JOIN GOODSMASTER g ON g.GOODS_KEY = l.TRD_GOODS
        WHERE h.TRH_DI = %s ORDER BY l.TRD_SEQ""", (di_key,)):
        result.append({
            "seq": line["TRD_SEQ"], "sku_code": text(line["SKU_CODE"]), "barcode": text(line["GOODS_CODE"]),
            "unit_name": text(line["TRD_UTQNAME"]), "unit_factor": num(line["TRD_UTQQTY"]) or 1,
            "qty": num(line["TRD_QTY"]), "free_qty": num(line["TRD_Q_FREE"]),
            "unit_price": num(line["TRD_K_U_PRC"]), "discount": num(line["TRD_DSC_KEYINV"]),
            "amount": num(line["TRD_B_AMT"]),
            # TRD_VAT_TY 3 = ไม่มี VAT (ตรงกับใบที่ยอดอยู่ช่องยกเว้น VAT)
            "has_vat": line["TRD_VAT_TY"] != 3,
            "_refer": text(line["TRD_REFER_REF"]),
        })
    return result


documents = []

# ใบจอง
bookings = rows("""
    SELECT d.DI_KEY, d.DI_REF, d.DI_DATE, d.DI_ACTIVE, d.DI_AMOUNT, d.DI_CRE_BY, d.DI_UPD_DATE, d.DI_CRE_DATE
    FROM DOCINFO d JOIN DOCTYPE t ON t.DT_KEY = d.DI_DT
    WHERE t.DT_DOCCODE = %s AND d.DI_DATE >= %s ORDER BY d.DI_KEY""", (route, since))
for b in bookings:
    documents.append({
        "bplus_key": b["DI_KEY"], "kind": "booking", "book_code": route, "ref": text(b["DI_REF"]),
        "doc_date": b["DI_DATE"].date().isoformat(), "is_cancelled": b["DI_ACTIVE"] != 0,
        "created_by": text(b["DI_CRE_BY"]), "total_amount": num(b["DI_AMOUNT"]),
        "bplus_updated_at": stamp(b["DI_UPD_DATE"] or b["DI_CRE_DATE"]),
        "lines": [{k: v for k, v in l.items() if k != "_refer"} for l in lines_of(b["DI_KEY"])],
    })

# ใบขายเชื่อ
sales = rows(f"""
    SELECT d.DI_KEY, d.DI_REF, d.DI_DATE, d.DI_ACTIVE, d.DI_AMOUNT, d.DI_CRE_BY, d.DI_UPD_DATE, d.DI_CRE_DATE,
           t.DT_DOCCODE, x.ARD_G_SV, x.ARD_G_SNV, x.ARD_G_VAT, x.ARD_B_AMT, x.ARD_DUE_DA, a.AR_CODE, a.AR_NAME
    FROM DOCINFO d JOIN DOCTYPE t ON t.DT_KEY = d.DI_DT
    LEFT JOIN ARDETAIL x ON x.ARD_DI = d.DI_KEY LEFT JOIN ARFILE a ON a.AR_KEY = x.ARD_AR
    WHERE d.DI_DATE >= %s AND d.DI_KEY IN ({ROUTE_SALES}) ORDER BY d.DI_KEY""", (since, route))
customer_by_booking = {}
for s in sales:
    lines = lines_of(s["DI_KEY"])
    booking_ref = next((l["_refer"] for l in lines if l["_refer"]), None)
    if booking_ref and s["AR_CODE"]:
        customer_by_booking[booking_ref] = (text(s["AR_CODE"]), text(s["AR_NAME"]))
    documents.append({
        "bplus_key": s["DI_KEY"], "kind": "credit_sale", "book_code": text(s["DT_DOCCODE"]), "ref": text(s["DI_REF"]),
        "doc_date": s["DI_DATE"].date().isoformat(), "is_cancelled": s["DI_ACTIVE"] != 0,
        "customer_code": text(s["AR_CODE"]), "customer_name": text(s["AR_NAME"]), "created_by": text(s["DI_CRE_BY"]),
        "booking_ref": booking_ref,
        "vatable_amount": num(s["ARD_G_SV"]), "exempt_amount": num(s["ARD_G_SNV"]), "vat_amount": num(s["ARD_G_VAT"]),
        "total_amount": num(s["DI_AMOUNT"]),
        "due_date": s["ARD_DUE_DA"].date().isoformat() if s["ARD_DUE_DA"] else None,
        "bplus_updated_at": stamp(s["DI_UPD_DATE"] or s["DI_CRE_DATE"]),
        "lines": [{k: v for k, v in l.items() if k != "_refer"} for l in lines],
    })

# ใบจองไม่มีรหัสลูกค้าใน BPlus: ใช้ลูกค้าจากใบขายเชื่อที่เปิดจากใบจองนั้น
for doc in documents:
    if doc["kind"] == "booking" and doc["ref"] in customer_by_booking:
        doc["customer_code"], doc["customer_name"] = customer_by_booking[doc["ref"]]

# ใบเสร็จ: เก็บเฉพาะบรรทัดที่ตัดชำระใบขายเชื่อของสายนี้
receipt_rows = rows(f"""
    SELECT d.DI_KEY, d.DI_REF, d.DI_DATE, d.DI_ACTIVE, d.DI_CRE_BY, d.DI_UPD_DATE, d.DI_CRE_DATE, t.DT_DOCCODE, t.DT_THAIDESC,
           a.TPA_REFER_REF, a.TPA_PAY_A, a.TPA_CASH_A, a.TPA_TRFR_A, a.TPA_CHEQ_A, a.TPA_OTHR_A, a.TPA_NOTE_A,
           a.TPA_DISC_A, a.TPA_WHTX_A, a.TPA_SHRT_A, r.AR_CODE, r.AR_NAME
    FROM TRANPAYA a JOIN TRANPAYH p ON p.TPH_KEY = a.TPA_TPH JOIN DOCINFO d ON d.DI_KEY = p.TPH_DI
    JOIN DOCTYPE t ON t.DT_KEY = d.DI_DT LEFT JOIN ARFILE r ON r.AR_KEY = p.TPH_AR
    WHERE d.DI_DATE >= %s AND a.TPA_REFER_DI IN ({ROUTE_SALES}) ORDER BY d.DI_KEY, a.TPA_KEY""", (since, route))
receipts = {}
for r in receipt_rows:
    doc = receipts.setdefault(r["DI_KEY"], {
        "bplus_key": r["DI_KEY"], "kind": "receipt", "book_code": text(r["DT_DOCCODE"]), "ref": text(r["DI_REF"]),
        "doc_date": r["DI_DATE"].date().isoformat(), "is_cancelled": r["DI_ACTIVE"] != 0,
        "customer_code": text(r["AR_CODE"]), "customer_name": text(r["AR_NAME"]), "created_by": text(r["DI_CRE_BY"]),
        "total_amount": 0.0, "cash_amount": 0.0, "transfer_amount": 0.0, "other_amount": 0.0, "short_amount": 0.0,
        "bplus_updated_at": stamp(r["DI_UPD_DATE"] or r["DI_CRE_DATE"]), "allocations": [],
    })
    other = num(r["TPA_CHEQ_A"]) + num(r["TPA_OTHR_A"]) + num(r["TPA_NOTE_A"])
    doc["total_amount"] = round(doc["total_amount"] + num(r["TPA_PAY_A"]), 2)
    doc["cash_amount"] = round(doc["cash_amount"] + num(r["TPA_CASH_A"]), 2)
    doc["transfer_amount"] = round(doc["transfer_amount"] + num(r["TPA_TRFR_A"]), 2)
    doc["other_amount"] = round(doc["other_amount"] + other, 2)
    doc["short_amount"] = round(doc["short_amount"] + num(r["TPA_SHRT_A"]), 2)
    doc["allocations"].append({
        "sale_ref": text(r["TPA_REFER_REF"]), "paid_amount": num(r["TPA_PAY_A"]),
        "cash_amount": num(r["TPA_CASH_A"]), "transfer_amount": num(r["TPA_TRFR_A"]),
        "discount_amount": num(r["TPA_DISC_A"]), "wht_amount": num(r["TPA_WHTX_A"]), "short_amount": num(r["TPA_SHRT_A"]),
    })
# บางใบเสร็จ BPlus ไม่ได้แยกช่องเงินสด/โอนรายบรรทัด ส่วนที่เหลือ (ไม่นับเงินขาด)
# ถือเป็นวิธีรับเงินของเล่มนั้น: เล่มชื่อ "เงินสด" = เงินสด, "เงินโอน" = โอน
book_names = {r["DI_KEY"]: text(r["DT_THAIDESC"]) or "" for r in receipt_rows}
for key, doc in receipts.items():
    rest = round(doc["total_amount"] - doc["short_amount"] - doc["cash_amount"] - doc["transfer_amount"] - doc["other_amount"], 2)
    if rest > 0:
        field = "cash_amount" if "เงินสด" in book_names[key] else "transfer_amount" if "โอน" in book_names[key] else "other_amount"
        doc[field] = round(doc[field] + rest, 2)
documents.extend(receipts.values())

connection.close()
name = f"bplus-{route}-{datetime.date.today().isoformat()}.json"
with open(name, "w", encoding="utf-8") as handle:
    json.dump({"route": route, "exported_at": datetime.datetime.now().isoformat(timespec="seconds"),
               "since": since, "documents": documents}, handle, ensure_ascii=False, indent=1,
              default=lambda v: float(v) if isinstance(v, decimal.Decimal) else str(v))

count = {k: sum(1 for d in documents if d["kind"] == k) for k in ("booking", "credit_sale", "receipt")}
print(f"เสร็จ บันทึกที่ {name}: ใบจอง {count['booking']} · ใบขายเชื่อ {count['credit_sale']} · ใบเสร็จ {count['receipt']}")
print("อัปโหลดไฟล์นี้ที่ ERP เมนู เอกสารสายขาย (ระบบเดิม)")
