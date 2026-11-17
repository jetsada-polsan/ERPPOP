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
           t.DT_DOCCODE, x.ARD_G_SV, x.ARD_G_SNV, x.ARD_G_VAT, x.ARD_B_AMT, x.ARD_DUE_DA, a.AR_KEY, a.AR_CODE, a.AR_NAME
    FROM DOCINFO d JOIN DOCTYPE t ON t.DT_KEY = d.DI_DT
    LEFT JOIN ARDETAIL x ON x.ARD_DI = d.DI_KEY LEFT JOIN ARFILE a ON a.AR_KEY = x.ARD_AR
    WHERE d.DI_DATE >= %s AND d.DI_KEY IN ({ROUTE_SALES}) ORDER BY d.DI_KEY""", (since, route))
customer_by_booking = {}
for s in sales:
    lines = lines_of(s["DI_KEY"])
    booking_ref = next((l["_refer"] for l in lines if l["_refer"]), None)
    if booking_ref and s["AR_CODE"]:
        customer_by_booking[booking_ref] = (s["AR_KEY"], text(s["AR_CODE"]), text(s["AR_NAME"]))
    documents.append({
        "bplus_key": s["DI_KEY"], "kind": "credit_sale", "book_code": text(s["DT_DOCCODE"]), "ref": text(s["DI_REF"]),
        "doc_date": s["DI_DATE"].date().isoformat(), "is_cancelled": s["DI_ACTIVE"] != 0,
        "customer_key": s["AR_KEY"], "customer_code": text(s["AR_CODE"]), "customer_name": text(s["AR_NAME"]),
        "created_by": text(s["DI_CRE_BY"]), "booking_ref": booking_ref,
        "vatable_amount": num(s["ARD_G_SV"]), "exempt_amount": num(s["ARD_G_SNV"]), "vat_amount": num(s["ARD_G_VAT"]),
        "total_amount": num(s["DI_AMOUNT"]),
        "due_date": s["ARD_DUE_DA"].date().isoformat() if s["ARD_DUE_DA"] else None,
        "bplus_updated_at": stamp(s["DI_UPD_DATE"] or s["DI_CRE_DATE"]),
        "lines": [{k: v for k, v in l.items() if k != "_refer"} for l in lines],
    })

# ใบจองไม่มีรหัสลูกค้าใน BPlus: ใช้ลูกค้าจากใบขายเชื่อที่เปิดจากใบจองนั้น
for doc in documents:
    if doc["kind"] == "booking" and doc["ref"] in customer_by_booking:
        doc["customer_key"], doc["customer_code"], doc["customer_name"] = customer_by_booking[doc["ref"]]

# ใบเสร็จ: เก็บเฉพาะบรรทัดที่ตัดชำระใบขายเชื่อของสายนี้
# TPA_PAY_A ไม่ใช่เงินที่รับจริงเสมอไป: ใบที่รับชำระบางส่วน BPlus ใส่ยอดเต็มบิลไว้ในช่องนี้
# เงินที่รับจริงของบรรทัด = เงินสด + โอน + เช็ค + อื่นๆ + ตั๋ว ส่วน TPA_SHRT_A คือเงินขาดที่ตัดทิ้ง
# ใบเสร็จที่ไม่ได้แยกช่องเงินเลยสักบรรทัด ใช้ TPA_PAY_A (หักเงินขาด) เป็นเงินที่รับ ตามวิธีรับของเล่ม
# ตรวจทุกใบ: เงินที่รับรวมทุกบรรทัด (รวมบรรทัดของสายอื่น) ต้องเท่ากับยอดใบเสร็จ DI_AMOUNT
route_sale_keys = {r["TRH_DI"] for r in rows(ROUTE_SALES, (route,))}
receipt_keys = [r["TPH_DI"] for r in rows(f"""
    SELECT DISTINCT p.TPH_DI FROM TRANPAYA a JOIN TRANPAYH p ON p.TPH_KEY = a.TPA_TPH JOIN DOCINFO d ON d.DI_KEY = p.TPH_DI
    WHERE d.DI_DATE >= %s AND a.TPA_REFER_DI IN ({ROUTE_SALES})""", (since, route))]
receipt_rows = []
for start in range(0, len(receipt_keys), 500):
    chunk = ",".join(str(int(k)) for k in receipt_keys[start:start + 500])
    receipt_rows += rows(f"""
        SELECT d.DI_KEY, d.DI_REF, d.DI_DATE, d.DI_ACTIVE, d.DI_AMOUNT, d.DI_CRE_BY, d.DI_UPD_DATE, d.DI_CRE_DATE,
               t.DT_DOCCODE, t.DT_THAIDESC, a.TPA_REFER_DI, a.TPA_REFER_REF, a.TPA_PAY_A, a.TPA_CASH_A, a.TPA_TRFR_A,
               a.TPA_CHEQ_A, a.TPA_OTHR_A, a.TPA_NOTE_A, a.TPA_DISC_A, a.TPA_WHTX_A, a.TPA_SHRT_A, r.AR_KEY, r.AR_CODE, r.AR_NAME
        FROM TRANPAYA a JOIN TRANPAYH p ON p.TPH_KEY = a.TPA_TPH JOIN DOCINFO d ON d.DI_KEY = p.TPH_DI
        JOIN DOCTYPE t ON t.DT_KEY = d.DI_DT LEFT JOIN ARFILE r ON r.AR_KEY = p.TPH_AR
        WHERE d.DI_KEY IN ({chunk}) ORDER BY d.DI_KEY, a.TPA_KEY""")


def tagged(line) -> float:
    return num(line["TPA_CASH_A"]) + num(line["TPA_TRFR_A"]) + num(line["TPA_CHEQ_A"]) + num(line["TPA_OTHR_A"]) + num(line["TPA_NOTE_A"])


lines_by_receipt = {}
for r in receipt_rows:
    lines_by_receipt.setdefault(r["DI_KEY"], []).append(r)
receipts = {}
receipt_mismatch = []
for key, lines in lines_by_receipt.items():
    head = lines[0]
    book = text(head["DT_THAIDESC"]) or ""
    untagged_field = "cash_amount" if "เงินสด" in book else "transfer_amount" if "โอน" in book else "other_amount"
    any_tagged = any(tagged(l) > 0 for l in lines)
    money_all = 0.0
    doc = {
        "bplus_key": key, "kind": "receipt", "book_code": text(head["DT_DOCCODE"]), "ref": text(head["DI_REF"]),
        "doc_date": head["DI_DATE"].date().isoformat(), "is_cancelled": head["DI_ACTIVE"] != 0,
        "customer_key": head["AR_KEY"], "customer_code": text(head["AR_CODE"]), "customer_name": text(head["AR_NAME"]),
        "created_by": text(head["DI_CRE_BY"]), "total_amount": 0.0, "cash_amount": 0.0, "transfer_amount": 0.0,
        "other_amount": 0.0, "short_amount": 0.0, "bplus_amount": num(head["DI_AMOUNT"]),
        "bplus_updated_at": stamp(head["DI_UPD_DATE"] or head["DI_CRE_DATE"]), "allocations": [],
    }
    for l in lines:
        short = num(l["TPA_SHRT_A"])
        if any_tagged:
            money = tagged(l)
            parts = {"cash_amount": num(l["TPA_CASH_A"]), "transfer_amount": num(l["TPA_TRFR_A"]),
                     "other_amount": num(l["TPA_CHEQ_A"]) + num(l["TPA_OTHR_A"]) + num(l["TPA_NOTE_A"])}
        else:
            money = round(num(l["TPA_PAY_A"]) - short, 2)
            parts = {"cash_amount": 0.0, "transfer_amount": 0.0, "other_amount": 0.0}
            parts[untagged_field] = money
        money_all += money
        if l["TPA_REFER_DI"] not in route_sale_keys:
            continue
        for field, value in parts.items():
            doc[field] = round(doc[field] + value, 2)
        doc["short_amount"] = round(doc["short_amount"] + short, 2)
        doc["total_amount"] = round(doc["total_amount"] + money + short, 2)
        if money + short == 0 and num(l["TPA_DISC_A"]) == 0 and num(l["TPA_WHTX_A"]) == 0:
            continue  # บรรทัดที่ใบนี้ไม่ได้รับเงินจริง (บิลถูกชำระในใบอื่น)
        doc["allocations"].append({
            "sale_ref": text(l["TPA_REFER_REF"]), "paid_amount": round(money + short, 2),
            "cash_amount": parts["cash_amount"], "transfer_amount": parts["transfer_amount"],
            "discount_amount": num(l["TPA_DISC_A"]), "wht_amount": num(l["TPA_WHTX_A"]), "short_amount": short,
        })
    if doc["allocations"]:
        receipts[key] = doc
        if not doc["is_cancelled"] and abs(round(money_all, 2) - num(head["DI_AMOUNT"])) > 0.005:
            receipt_mismatch.append(f'{doc["ref"]} รับจริง {money_all:,.2f} / ยอดใบ {num(head["DI_AMOUNT"]):,.2f}')
documents.extend(receipts.values())

# ลูกหนี้ยกมา: บิลของสายนี้ที่ออกก่อนช่วงที่ดึงและยังค้าง ณ วันเริ่มช่วง ยอด = ยอดบิล - ที่รับชำระก่อนวันนั้น
# ใบเสร็จในช่วงจะได้ตัดหนี้บิลเก่าได้ และลูกหนี้ใน ERP ตรงกับ BPlus (ไม่ใช่ยอดขาย ERP จึงไม่นับเป็นยอดขายซ้ำ)
# bplus_key ของยอดยกมา = 9,000,000,000 + DI_KEY ของบิล เพื่อไม่ชนกับตัวบิลเองถ้าวันหลังดึงย้อนไปถึงบิลนั้น
OPENING_KEY_OFFSET = 9_000_000_000
opening_rows = rows(f"""
    SELECT d.DI_KEY, d.DI_REF, d.DI_DATE, d.DI_AMOUNT, d.DI_UPD_DATE, d.DI_CRE_DATE, t.DT_DOCCODE, x.ARD_DUE_DA,
           a.AR_KEY, a.AR_CODE, a.AR_NAME,
           (SELECT COALESCE(SUM(pa.TPA_DISC_A + pa.TPA_WHTX_A + CASE
                WHEN pa.TPA_CASH_A + pa.TPA_TRFR_A + pa.TPA_CHEQ_A + pa.TPA_OTHR_A + pa.TPA_NOTE_A > 0
                    THEN pa.TPA_CASH_A + pa.TPA_TRFR_A + pa.TPA_CHEQ_A + pa.TPA_OTHR_A + pa.TPA_NOTE_A + pa.TPA_SHRT_A
                WHEN EXISTS (SELECT 1 FROM TRANPAYA q WHERE q.TPA_TPH = pa.TPA_TPH
                    AND q.TPA_CASH_A + q.TPA_TRFR_A + q.TPA_CHEQ_A + q.TPA_OTHR_A + q.TPA_NOTE_A > 0) THEN 0
                ELSE pa.TPA_PAY_A END), 0)
            FROM TRANPAYA pa JOIN TRANPAYH ph ON ph.TPH_KEY = pa.TPA_TPH JOIN DOCINFO pd ON pd.DI_KEY = ph.TPH_DI
            WHERE pa.TPA_REFER_DI = d.DI_KEY AND pd.DI_ACTIVE = 0 AND pd.DI_DATE < %s) AS PAID_BEFORE
    FROM DOCINFO d JOIN DOCTYPE t ON t.DT_KEY = d.DI_DT
    LEFT JOIN ARDETAIL x ON x.ARD_DI = d.DI_KEY LEFT JOIN ARFILE a ON a.AR_KEY = x.ARD_AR
    WHERE d.DI_DATE < %s AND d.DI_ACTIVE = 0 AND d.DI_KEY IN ({ROUTE_SALES}) ORDER BY d.DI_KEY""", (since, since, route))
opening_total = 0.0
for o in opening_rows:
    outstanding = round(num(o["DI_AMOUNT"]) - num(o["PAID_BEFORE"]), 2)
    if outstanding <= 0:
        continue
    opening_total += outstanding
    documents.append({
        "bplus_key": OPENING_KEY_OFFSET + o["DI_KEY"], "kind": "opening_ar", "book_code": text(o["DT_DOCCODE"]),
        "ref": text(o["DI_REF"]), "doc_date": o["DI_DATE"].date().isoformat(), "is_cancelled": False,
        "customer_key": o["AR_KEY"], "customer_code": text(o["AR_CODE"]), "customer_name": text(o["AR_NAME"]),
        "total_amount": outstanding, "original_amount": num(o["DI_AMOUNT"]),
        "due_date": o["ARD_DUE_DA"].date().isoformat() if o["ARD_DUE_DA"] else None,
        "bplus_updated_at": stamp(o["DI_UPD_DATE"] or o["DI_CRE_DATE"]),
    })

# ลูกค้าที่มีเอกสารในไฟล์นี้
customer_keys = sorted({d["customer_key"] for d in documents if d.get("customer_key") is not None})
customer_data = []
if customer_keys:
    keys_placeholder = ",".join(str(int(k)) for k in customer_keys)
    customer_rows = rows(f"""
        SELECT ar.AR_KEY, ar.AR_CODE, ar.AR_NAME, ar.AR_ENABLE,
               ara.ARA_DEFAULT,
               addb.ADDB_PHONE,
               addb.ADDB_ADDB_1, addb.ADDB_ADDB_2, addb.ADDB_ADDB_3,
               addb.ADDB_PROVINCE, addb.ADDB_POST
        FROM ARFILE ar
        LEFT JOIN ARADDRESS ara ON ara.ARA_AR = ar.AR_KEY
        LEFT JOIN ADDRBOOK addb ON addb.ADDB_KEY = ara.ARA_ADDB
        WHERE ar.AR_KEY IN ({keys_placeholder})
        ORDER BY ar.AR_KEY, CASE WHEN ara.ARA_DEFAULT = 'Y' THEN 0 ELSE 1 END, ara.ARA_KEY""")

    seen_ar_keys = set()
    for cr in customer_rows:
        ar_key = cr["AR_KEY"]
        if ar_key in seen_ar_keys:
            continue
        seen_ar_keys.add(ar_key)

        addr_parts = [
            text(cr["ADDB_ADDB_1"]), text(cr["ADDB_ADDB_2"]), text(cr["ADDB_ADDB_3"]),
            text(cr["ADDB_PROVINCE"]), text(cr["ADDB_POST"])
        ]
        address = " ".join(p for p in addr_parts if p)

        customer_data.append({
            "ar_key": ar_key,
            "ar_code": text(cr["AR_CODE"]),
            "ar_name": text(cr["AR_NAME"]),
            # เลขผู้เสียภาษียังไม่ส่ง: ยังไม่ได้ยืนยันชื่อคอลัมน์ใน BPlus (ETL เดิมก็ไม่ได้ดึง)
            "address": address or None,
            "phone": text(cr["ADDB_PHONE"]) or None,
            "is_active": cr["AR_ENABLE"] == "Y",
        })

# สินค้าที่อยู่ในบรรทัดเอกสาร
sku_codes = sorted({line["sku_code"] for d in documents for line in d.get("lines", []) if line.get("sku_code")})
product_data = []
if sku_codes:
    placeholders = ",".join("%s" for _ in sku_codes)
    product_rows = rows(f"""
        SELECT sm.SKU_CODE, sm.SKU_NAME, u.UTQ_NAME
        FROM SKUMASTER sm
        LEFT JOIN UOFQTY u ON u.UTQ_KEY = sm.SKU_S_UTQ
        WHERE sm.SKU_CODE IN ({placeholders})
        ORDER BY sm.SKU_CODE""", tuple(sku_codes))

    found_skus = set()
    for pr in product_rows:
        sku = text(pr["SKU_CODE"])
        found_skus.add(sku)
        product_data.append({
            "sku_code": sku,
            "name": text(pr["SKU_NAME"]),
            "unit_name": text(pr["UTQ_NAME"]),
        })

    for sku in sku_codes:
        if sku not in found_skus:
            line_unit = next((l.get("unit_name") for d in documents for l in d.get("lines", []) if l.get("sku_code") == sku and l.get("unit_name")), None)
            product_data.append({
                "sku_code": sku,
                "name": sku,
                "unit_name": line_unit or "ชิ้น",
            })

connection.close()
name = f"bplus-{route}-{datetime.date.today().isoformat()}.json"
with open(name, "w", encoding="utf-8") as handle:
    json.dump({"route": route, "exported_at": datetime.datetime.now().isoformat(timespec="seconds"),
               "since": since, "documents": documents,
               "customers": customer_data, "products": product_data}, handle, ensure_ascii=False, indent=1,
              default=lambda v: float(v) if isinstance(v, decimal.Decimal) else str(v))

count = {k: sum(1 for d in documents if d["kind"] == k) for k in ("booking", "credit_sale", "opening_ar", "receipt")}
print(f"เสร็จ บันทึกที่ {name}: ใบจอง {count['booking']} · ใบขายเชื่อ {count['credit_sale']} · ใบเสร็จ {count['receipt']}"
      f" · ลูกหนี้ยกมา {count['opening_ar']} บิล ({opening_total:,.2f}) · ลูกค้า {len(customer_data)} · สินค้า {len(product_data)}")
if receipt_mismatch:
    print(f"เตือน: ใบเสร็จ {len(receipt_mismatch)} ใบ เงินที่รับรวมไม่เท่ายอดใบใน BPlus (ส่งรายการนี้ให้ Claude ดู):")
    for item in receipt_mismatch[:20]:
        print("  " + item)
else:
    print("ตรวจแล้ว: เงินที่รับของใบเสร็จทุกใบเท่ากับยอดใบใน BPlus")
print("อัปโหลดไฟล์นี้ที่ ERP เมนู เอกสารสายขาย (ระบบเดิม)")
