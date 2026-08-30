from __future__ import annotations

import base64
import hashlib
import hmac
import json
import sqlite3
import uuid
from dataclasses import dataclass
from datetime import date, datetime, time, timedelta, timezone
from decimal import Decimal, ROUND_HALF_UP


def now() -> str:
    return datetime.now(timezone.utc).isoformat()


def money(value: Decimal | float | str) -> Decimal:
    return Decimal(str(value)).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


DEFAULT_VAT_RATE = Decimal("7")

VAT_RATE_SETTING = "vat_rate"


def vat_from_inclusive(amount: Decimal, rate: Decimal) -> Decimal:
    """แยก VAT ออกจากยอดที่รวมภาษีอยู่แล้ว

    ราคาขายหน้าร้านในไทยรวม VAT ไว้แล้ว ยอดที่ลูกค้าจ่ายจึงไม่เปลี่ยนไม่ว่าสินค้า
    จะเสีย VAT หรือไม่ สิ่งที่เปลี่ยนคือการแยกยอดในบิลและตัวเลขที่ส่งเข้ารายงานภาษี
    """
    if rate <= 0 or amount <= 0:
        return Decimal("0.00")
    return money(amount * rate / (Decimal("100") + rate))


def pin_hash(pin: str) -> str:
    # ใช้กับ PIN ตั้งต้นตอน seed เครื่องใหม่ที่ยังไม่เคยต่อ ERP เท่านั้น
    # PIN จริงของพนักงานยืนยันผ่าน verify_offline_credential ด้วย credential ที่ ERP ออกให้
    return hashlib.sha256(pin.encode("utf-8")).hexdigest()


def verify_offline_credential(pin: str, salt_b64: str, verifier_b64: str, iterations: int) -> bool:
    """ตรวจ PIN ออฟไลน์ด้วย credential ที่ Laravel ออกให้ (ดู PosApiController::offlineCredential)

    Laravel: hash_pbkdf2('sha256', pin, salt_raw, iterations, 32, raw) แล้ว base64
    ฝั่งนี้จึงถอด base64 กลับเป็นไบต์ดิบ คำนวณซ้ำ แล้วเทียบแบบ constant-time
    เก็บแต่ salt+verifier ไว้ในเครื่อง ไม่มี PIN จริงหรือ hash เต็มถูกเก็บลง SQLite
    """
    try:
        salt = base64.b64decode(salt_b64)
        expected = base64.b64decode(verifier_b64)
    except (ValueError, TypeError):
        return False
    if not salt or not expected or iterations <= 0:
        return False
    actual = hashlib.pbkdf2_hmac("sha256", pin.encode("utf-8"), salt, int(iterations), dklen=len(expected))
    return hmac.compare_digest(actual, expected)


def _is_expired(value: str | None) -> bool:
    if not value:
        return False
    try:
        return datetime.fromisoformat(str(value).replace("Z", "+00:00")) <= datetime.now(timezone.utc)
    except ValueError:
        # A malformed expiry must never become an unlimited offline credential.
        return True


@dataclass(frozen=True)
class OfflineLoginResult:
    cashier: sqlite3.Row | None
    reason: str | None = None
    used_manager_override: bool = False

    @property
    def success(self) -> bool:
        return self.cashier is not None


@dataclass(frozen=True)
class CartLine:
    product_id: int
    qty: Decimal
    unit_price: Decimal
    barcode: str | None = None
    source_barcode: str | None = None
    barcode_type: str = "CUSTOM"
    discount: Decimal = Decimal("0")
    price_version: str | None = None


@dataclass(frozen=True)
class DailySalesSummary:
    report_date: date
    transaction_count: int
    void_count: int
    subtotal: Decimal
    discount_total: Decimal
    vat_total: Decimal
    grand_total: Decimal
    payments: tuple[tuple[str, Decimal], ...]
    pending_sync_count: int


class PosService:
    def __init__(self, connection: sqlite3.Connection):
        self.db = connection

    def daily_sales_summary(self, report_date: date | None = None) -> DailySalesSummary:
        """Return a terminal-local daily summary without contacting ERP.

        Sales timestamps are stored in UTC.  Reports are grouped by Thailand's
        business day so a sale shortly after midnight does not land in yesterday.
        """
        # Thailand has no DST.  A fixed offset also keeps Windows installers
        # independent from an optional system/tzdata timezone database.
        business_tz = timezone(timedelta(hours=7), name="ICT")
        report_date = report_date or datetime.now(business_tz).date()
        start = datetime.combine(report_date, time.min, tzinfo=business_tz).astimezone(timezone.utc).isoformat()
        end = datetime.combine(report_date, time.max, tzinfo=business_tz).astimezone(timezone.utc).isoformat()
        sales = self.db.execute(
            """SELECT id, subtotal, discount_total, vat_total, grand_total, is_void
               FROM sales WHERE sale_datetime >= ? AND sale_datetime <= ?""",
            (start, end),
        ).fetchall()
        valid_sales = [row for row in sales if not bool(row["is_void"])]
        payments = self.db.execute(
            """SELECT p.method, p.amount, coalesce(p.change_amount, '0') AS change_amount
               FROM payments p JOIN sales s ON s.id = p.sale_id
               WHERE s.is_void = 0 AND s.sale_datetime >= ? AND s.sale_datetime <= ?""",
            (start, end),
        ).fetchall()
        pending = self.db.execute(
            """SELECT count(*) FROM sales
               WHERE sync_status != 'synced' AND sale_datetime >= ? AND sale_datetime <= ?""",
            (start, end),
        ).fetchone()[0]
        by_method: dict[str, Decimal] = {}
        for payment in payments:
            method = str(payment["method"])
            by_method[method] = money(by_method.get(method, Decimal("0")) + Decimal(str(payment["amount"])) - Decimal(str(payment["change_amount"])))
        return DailySalesSummary(
            report_date=report_date,
            transaction_count=len(sales),
            void_count=len(sales) - len(valid_sales),
            subtotal=money(sum((Decimal(str(row["subtotal"])) for row in valid_sales), Decimal("0"))),
            discount_total=money(sum((Decimal(str(row["discount_total"])) for row in valid_sales), Decimal("0"))),
            vat_total=money(sum((Decimal(str(row["vat_total"])) for row in valid_sales), Decimal("0"))),
            grand_total=money(sum((Decimal(str(row["grand_total"])) for row in valid_sales), Decimal("0"))),
            payments=tuple(sorted(by_method.items())),
            pending_sync_count=int(pending),
        )

    def login(self, cashier_code: str, pin: str) -> sqlite3.Row | None:
        """Compatibility wrapper for older callers; records the same offline audit event."""
        return self.login_offline(cashier_code, pin).cashier

    def login_offline(self, cashier_code: str, pin: str, *, terminal_code: str | None = None,
                      branch_code: str | None = None) -> OfflineLoginResult:
        """Authenticate solely with SQLite, never requiring the ERP to be reachable.

        The last verified credential remains usable through offline_valid_until even
        if the server has announced a newer credential version.  This deliberately
        prevents a remote PIN reset from taking a disconnected checkout offline.
        """
        row = self.db.execute("SELECT * FROM local_cashiers WHERE code = ?", (cashier_code,)).fetchone()
        terminal_code = terminal_code or self._setting("terminal_code")
        branch_code = branch_code or self._setting("branch_code") or self._setting("branch_name")
        result = OfflineLoginResult(None, "ไม่พบแคชเชียร์ในเครื่องนี้")
        if not row:
            self.record_auth_event(cashier_code, "offline_login", False, result.reason, terminal_code, branch_code)
            return result
        if not bool(row["active"]) or row["revoked_at"]:
            result = OfflineLoginResult(None, "บัญชีแคชเชียร์ถูกปิดใช้งาน")
        elif row["local_override_pin_hash"]:
            if _is_expired(row["local_override_expires_at"]):
                result = OfflineLoginResult(None, "PIN ชั่วคราวหมดอายุแล้ว กรุณาเชื่อมต่อ ERP")
            elif hmac.compare_digest(str(row["local_override_pin_hash"]), pin_hash(pin)):
                result = OfflineLoginResult(row, None, True)
            else:
                result = OfflineLoginResult(None, "PIN ไม่ถูกต้อง")
        elif not (row["offline_valid_until"] or row["cred_expires_at"]):
            result = OfflineLoginResult(None, "ยังไม่มีสิทธิ์ใช้งานออฟไลน์ กรุณาเชื่อมต่อ ERP เพื่อ sync แคชเชียร์")
        elif _is_expired(row["offline_valid_until"] or row["cred_expires_at"]):
            result = OfflineLoginResult(None, "Offline login expired, please reconnect to server")
        elif row["cred_salt"] and row["cred_verifier"]:
            verified = verify_offline_credential(pin, row["cred_salt"], row["cred_verifier"], row["cred_iterations"] or 0)
            result = OfflineLoginResult(
                row if verified else None,
                None if verified else "PIN ไม่ถูกต้อง",
            )
        elif row["pin_hash"] and hmac.compare_digest(str(row["pin_hash"]), pin_hash(pin)):
            result = OfflineLoginResult(row)
        else:
            result = OfflineLoginResult(None, "PIN ไม่ถูกต้อง")
        self.record_auth_event(cashier_code, "offline_login", result.success, result.reason, terminal_code, branch_code)
        return result

    def record_auth_event(self, cashier_code: str, event_type: str, success: bool, reason: str | None,
                          terminal_code: str | None = None, branch_code: str | None = None) -> str:
        event_uuid = str(uuid.uuid4())
        with self.db:
            self.db.execute(
                """INSERT INTO auth_events_outbox
                (event_uuid, cashier_code, event_type, success, reason, terminal_code, branch_code, occurred_at, synced)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)""",
                (event_uuid, cashier_code, event_type, int(success), reason, terminal_code, branch_code, now()),
            )
        return event_uuid

    def manager_override_reset(self, *, manager_code: str, manager_pin: str, cashier_code: str,
                               temporary_pin: str, terminal_code: str | None = None,
                               branch_code: str | None = None, valid_minutes: int = 60) -> OfflineLoginResult:
        """Create a short-lived local recovery PIN after an offline supervisor check."""
        if not temporary_pin.isdigit() or not 4 <= len(temporary_pin) <= 20:
            raise ValueError("PIN ชั่วคราวต้องเป็นตัวเลข 4-20 หลัก")
        manager = self.login_offline(manager_code, manager_pin, terminal_code=terminal_code, branch_code=branch_code)
        if not manager.success or str(manager.cashier["role"]).lower() not in {"manager", "supervisor"}:
            self.record_auth_event(cashier_code, "manager_override_denied", False, "ผู้อนุมัติไม่มีสิทธิ์", terminal_code, branch_code)
            return OfflineLoginResult(None, "ผู้จัดการหรือ PIN ไม่ถูกต้อง")
        target = self.db.execute("SELECT * FROM local_cashiers WHERE code = ?", (cashier_code,)).fetchone()
        if not target or not bool(target["active"]) or target["revoked_at"]:
            self.record_auth_event(cashier_code, "manager_override_denied", False, "ไม่พบหรือปิดใช้งาน", terminal_code, branch_code)
            return OfflineLoginResult(None, "ไม่พบแคชเชียร์ที่เปิดใช้งาน")
        expires = datetime.now(timezone.utc).replace(microsecond=0)
        from datetime import timedelta
        expires = (expires + timedelta(minutes=max(1, min(valid_minutes, 240)))).isoformat()
        with self.db:
            self.db.execute(
                """UPDATE local_cashiers SET local_override_pin_hash = ?, local_override_expires_at = ?,
                local_override_set_by = ?, force_pin_change = 1 WHERE id = ?""",
                (pin_hash(temporary_pin), expires, manager.cashier["code"], target["id"]),
            )
        self.record_auth_event(cashier_code, "manager_override_reset", True,
                               f"approved_by:{manager.cashier['code']};expires:{expires}", terminal_code, branch_code)
        return OfflineLoginResult(target)

    def has_local_it_pin(self) -> bool:
        return bool(self._setting("local_it_pin_hash"))

    def set_local_it_pin(self, pin: str) -> None:
        if not pin.isdigit() or not 6 <= len(pin) <= 20:
            raise ValueError("Local IT PIN ต้องเป็นตัวเลข 6-20 หลัก")
        with self.db:
            self.db.execute(
                """INSERT INTO device_settings (key, value, updated_at) VALUES (?, ?, ?)
                ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at""",
                ("local_it_pin_hash", json.dumps(pin_hash(pin)), now()),
            )

    def verify_local_it_pin(self, pin: str) -> bool:
        stored = self._setting("local_it_pin_hash")
        return bool(stored) and hmac.compare_digest(stored, pin_hash(pin))

    def _setting(self, key: str) -> str | None:
        row = self.db.execute("SELECT value FROM device_settings WHERE key = ?", (key,)).fetchone()
        if not row:
            return None
        try:
            return str(json.loads(row["value"]))
        except (TypeError, ValueError, json.JSONDecodeError):
            return str(row["value"])

    def open_shift(self, branch_id: int, terminal_id: str, cashier_id: int, opening_cash: Decimal) -> int:
        existing = self.db.execute("SELECT id FROM shifts WHERE terminal_id = ? AND status = 'open'", (terminal_id,)).fetchone()
        if existing:
            return int(existing["id"])
        cursor = self.db.execute(
            """INSERT INTO shifts (uuid, branch_id, terminal_id, cashier_id, opened_at, opening_cash, status)
            VALUES (?, ?, ?, ?, ?, ?, 'open')""",
            (str(uuid.uuid4()), branch_id, terminal_id, cashier_id, now(), str(money(opening_cash))),
        )
        self.db.commit()
        return int(cursor.lastrowid)

    def vat_rate(self) -> Decimal:
        """อัตรา VAT ที่ sync มาจาก ERP — ยังไม่เคย sync ให้ใช้อัตราปัจจุบันของไทย

        ไม่ฝังอัตราไว้ในโค้ดเป็นค่าตายตัว เพราะวันที่อัตราเปลี่ยนจะต้องแก้แล้วออกรุ่นใหม่
        ให้ทุกเครื่องพร้อมกัน ซึ่งช้ากว่าการแก้ที่ ERP แล้วให้เครื่อง sync มา
        """
        row = self.db.execute("SELECT value FROM device_settings WHERE key = ?", (VAT_RATE_SETTING,)).fetchone()
        if not row:
            return DEFAULT_VAT_RATE
        try:
            return Decimal(str(json.loads(row["value"])))
        except (ValueError, TypeError, json.JSONDecodeError):
            return DEFAULT_VAT_RATE

    def lookup_barcode(self, barcode: str) -> sqlite3.Row | None:
        return self.db.execute(
            """SELECT p.*, b.barcode, b.barcode_type, b.unit_factor, b.price
            FROM product_barcodes b JOIN products p ON p.id = b.product_id
            WHERE b.barcode = ? AND p.active = 1""", (barcode,)
        ).fetchone()

    def bind_server_shift(self, local_shift_id: int, server_shift_id: int) -> None:
        self.db.execute("UPDATE shifts SET server_id = ? WHERE id = ?", (server_shift_id, local_shift_id))
        self.db.commit()

    def checkout(self, *, document_no: str, branch_id: int, terminal_id: str, shift_id: int,
                 cashier_id: int, lines: list[CartLine], payment_method: str, paid_amount: Decimal,
                 sale_uuid: str | None = None) -> int:
        if not lines:
            raise ValueError("ต้องมีสินค้าอย่างน้อยหนึ่งรายการ")
        shift = self.db.execute("SELECT status FROM shifts WHERE id = ?", (shift_id,)).fetchone()
        if not shift:
            raise ValueError("ไม่พบกะที่ระบุ")
        if shift["status"] != "open":
            # ขายเข้ากะที่ปิดไปแล้วแปลว่ายอดขายไปโผล่ในกะที่นับเงินจบแล้ว
            # เงินในลิ้นชักกับยอดในระบบจะไม่ตรงกันโดยไม่มีใครรู้ว่าเริ่มเพี้ยนตรงไหน
            raise ValueError("กะนี้ปิดแล้ว เปิดกะใหม่ก่อนขาย")
        sale_uuid = sale_uuid or str(uuid.uuid4())
        existing = self.db.execute("SELECT id FROM sales WHERE sale_uuid = ?", (sale_uuid,)).fetchone()
        if existing:
            return int(existing["id"])
        subtotal = sum((money(line.qty * line.unit_price) for line in lines), Decimal("0"))
        discount = sum((money(line.discount) for line in lines), Decimal("0"))
        grand_total = money(subtotal - discount)

        # VAT คิดจากยอดสุทธิของเฉพาะสินค้าที่เสีย VAT — อาหารสดหลายอย่างได้รับยกเว้น
        # คิดรวมทั้งบิลจะทำให้ยอดภาษีขายที่ยื่นสูงเกินจริง
        rate = self.vat_rate()
        vatable = Decimal("0")
        for line in lines:
            flag = self.db.execute("SELECT is_vat FROM products WHERE id = ?", (line.product_id,)).fetchone()
            if flag and int(flag["is_vat"]):
                vatable += money(line.qty * line.unit_price - line.discount)
        vat_total = vat_from_inclusive(vatable, rate)
        if money(paid_amount) < grand_total:
            raise ValueError("ยอดชำระไม่พอ")
        with self.db:
            cursor = self.db.execute(
                """INSERT INTO sales (sale_uuid, document_no, branch_id, terminal_id, shift_id, cashier_id,
                sale_datetime, subtotal, discount_total, vat_total, grand_total, payment_status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?)""",
                (sale_uuid, document_no, branch_id, terminal_id, shift_id, cashier_id, now(), str(subtotal),
                 str(discount), str(vat_total), str(grand_total), now()),
            )
            sale_id = int(cursor.lastrowid)
            for line in lines:
                product = self.db.execute("SELECT name, unit_name FROM products WHERE id = ? AND active = 1", (line.product_id,)).fetchone()
                if not product:
                    raise ValueError(f"ไม่พบสินค้าที่ใช้งานได้ id={line.product_id}")
                line_total = money(line.qty * line.unit_price - line.discount)
                self.db.execute(
                    """INSERT INTO sale_items (sale_id, product_id, barcode, source_barcode, barcode_type, product_name_snapshot,
                    unit_name_snapshot, qty, unit_price, discount, line_total, price_version)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
                    (sale_id, line.product_id, line.barcode, line.source_barcode, line.barcode_type, product["name"], product["unit_name"],
                     str(line.qty), str(line.unit_price), str(line.discount), str(line_total), line.price_version),
                )
            # เก็บทั้งเงินที่รับมาและเงินทอน — บันทึกแต่ยอดรับอย่างเดียว
            # แล้วยอดเงินสดที่ควรมีในลิ้นชักจะเกินจริงเท่ากับเงินทอนที่จ่ายออกไป
            change = money(paid_amount) - grand_total
            self.db.execute(
                "INSERT INTO payments (sale_id, method, amount, change_amount) VALUES (?, ?, ?, ?)",
                (sale_id, payment_method, str(money(paid_amount)), str(change)),
            )
            self.db.execute("INSERT INTO print_jobs (sale_id, created_at) VALUES (?, ?)", (sale_id, now()))
            payload = json.dumps({
                "sale_uuid": sale_uuid, "document_no": document_no,
                "grand_total": str(grand_total), "vat_total": str(vat_total), "vat_rate": str(rate),
                "vat_mode": "included",
            })
            self.db.execute("INSERT INTO sync_outbox (aggregate_type, aggregate_uuid, payload, created_at) VALUES ('sale', ?, ?, ?)", (sale_uuid, payload, now()))
        return sale_id

    def void_sale(self, sale_id: int, *, cashier_id: int, reason: str) -> None:
        """ยกเลิกบิลโดยไม่ลบ — บิลที่ออกไปแล้วต้องยังตรวจย้อนได้เสมอ

        เหตุผลกับผู้ยกเลิกเป็นข้อบังคับ เพราะการยกเลิกบิลเป็นช่องทางเอาเงินออก
        จากลิ้นชักที่ตรวจสอบยากที่สุดถ้าไม่มีใครต้องรับผิดชอบชื่อตัวเอง
        """
        reason = (reason or "").strip()
        if not reason:
            raise ValueError("ต้องระบุเหตุผลที่ยกเลิกบิล")
        sale = self.db.execute("SELECT id, is_void FROM sales WHERE id = ?", (sale_id,)).fetchone()
        if not sale:
            raise ValueError("ไม่พบบิลที่ต้องการยกเลิก")
        if sale["is_void"]:
            raise ValueError("บิลนี้ถูกยกเลิกไปแล้ว")

        with self.db:
            self.db.execute(
                "UPDATE sales SET is_void = 1, voided_at = ?, void_reason = ?, voided_by = ? WHERE id = ?",
                (now(), reason, cashier_id, sale_id),
            )
            sale_uuid = self.db.execute("SELECT sale_uuid FROM sales WHERE id = ?", (sale_id,)).fetchone()["sale_uuid"]
            payload = json.dumps({"sale_uuid": sale_uuid, "reason": reason, "voided_by": cashier_id})
            # ส่งการยกเลิกขึ้นเซิร์ฟเวอร์ด้วย ไม่งั้นบิลจะถูกยกเลิกแค่ในเครื่อง
            # ใช้คีย์ของตัวเองเพราะ aggregate_uuid เป็น unique — การยกเลิกเป็นคนละเหตุการณ์
            # กับการขาย ทั้งสองต้องอยู่ในคิวพร้อมกันได้ และต้องกันส่งซ้ำแยกกัน
            self.db.execute(
                "INSERT INTO sync_outbox (aggregate_type, aggregate_uuid, payload, created_at) VALUES ('sale_void', ?, ?, ?)",
                (f"{sale_uuid}:void", payload, now()),
            )

    def pending_sync_count(self) -> int:
        return int(self.db.execute("SELECT count(*) FROM sync_outbox WHERE status = 'pending'").fetchone()[0])
