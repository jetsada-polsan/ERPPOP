"""หน้าตาต้องตรงกับภาพร่างที่อนุมัติแล้ว

ทดสอบสไตล์ได้แค่ระดับ "ค่าที่ตกลงกันไว้ยังอยู่ไหม" ซึ่งพอสำหรับกันคนเผลอเปลี่ยน
สีแบรนด์หรือรื้อหัวข้อเมนูทิ้งโดยไม่ตั้งใจ ส่วนหน้าตาจริงต้องเปิดโปรแกรมดู
"""
from __future__ import annotations

import re
import inspect
import unittest

from pos_python.ui import PALETTE, SECTION_HINTS, STYLE, run_ui

JET_BLUE = "#1585c0"
JET_BLUE_DARK = "#0f4c75"
POPSTAR_RED = "#c9212d"


def contrast(fg: str, bg: str) -> float:
    """อัตราส่วนความต่างตาม WCAG 2.1 — ต้องได้ 4.5:1 ขึ้นไปถึงจะอ่านออก"""
    def luminance(colour: str) -> float:
        parts = [int(colour[i:i + 2], 16) / 255 for i in (1, 3, 5)]
        parts = [c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4 for c in parts]
        return 0.2126 * parts[0] + 0.7152 * parts[1] + 0.0722 * parts[2]

    light, dark = sorted((luminance(fg), luminance(bg)), reverse=True)
    return (light + 0.05) / (dark + 0.05)


class UiStyleTest(unittest.TestCase):
    def test_the_brand_colour_is_the_jet_blue_the_erp_uses(self) -> None:
        self.assertEqual(PALETTE["primary"], JET_BLUE)
        self.assertIn(f"#brandBar {{ background: {JET_BLUE_DARK}", STYLE)
        # เขียวเป็นสีที่ผมใส่ไว้ก่อนเห็นภาพร่าง ต้องไม่หลงเหลือ
        self.assertNotIn("#0f766e", STYLE)

    def test_popstar_red_only_marks_danger_and_never_dresses_the_chrome(self) -> None:
        """แดงเหลือหน้าที่เดียวคือบอกว่าอันตราย ไม่ใช่สีประจำเครื่อง"""
        self.assertEqual(PALETTE["danger"], POPSTAR_RED)
        for line in STYLE.splitlines():
            if POPSTAR_RED in line:
                self.assertIn("voidBtn", line, f"แดงไปโผล่ที่ไม่ใช่ปุ่มยกเลิก: {line}")

    def test_the_selected_menu_item_is_marked_the_way_the_mockup_marks_it(self) -> None:
        self.assertIn("#navItem:checked", STYLE)
        self.assertIn(PALETTE["primary_soft"], STYLE)
        self.assertIn(f"border-left: 4px solid {JET_BLUE}", STYLE)

    def test_the_receipt_sits_on_a_grey_backing_like_paper_on_a_desk(self) -> None:
        self.assertIn("#receiptBg", STYLE)
        self.assertIn(PALETTE["preview"], STYLE)
        self.assertIn("#receiptPaper", STYLE)

    def test_the_warning_note_keeps_its_amber_styling(self) -> None:
        self.assertIn("#note", STYLE)
        self.assertIn(PALETTE["warning"], STYLE)
        self.assertIn(PALETTE["warning_soft"], STYLE)

    def test_every_colour_on_screen_comes_from_the_palette(self) -> None:
        """กันสีดิบหลุดเข้ามาอีก — ครั้งก่อนเขียวหลุดรอดเพราะอยู่นอก STYLE"""
        strays = {c for c in re.findall(r"#[0-9a-fA-F]{6}", STYLE)} - set(PALETTE.values())
        self.assertEqual(strays, set(), f"สีที่ไม่ได้มาจาก PALETTE: {sorted(strays)}")

    def test_white_text_stays_readable_on_every_colour_it_sits_on(self) -> None:
        for name in ("primary_dark", "primary_ink", "text", "danger"):
            ratio = contrast("#ffffff", PALETTE[name])
            self.assertGreaterEqual(round(ratio, 2), 4.5, f"ขาวบน {name} ได้แค่ {ratio:.2f}:1")

    def test_the_field_border_is_visible_enough_to_aim_at(self) -> None:
        """WCAG 1.4.11 — ขอบช่องกรอกเป็น UI ไม่ใช่ตัวหนังสือ เกณฑ์คือ 3:1"""
        ratio = contrast(PALETTE["field"], PALETTE["surface"])
        self.assertGreaterEqual(round(ratio, 2), 3.0, f"ขอบช่องกรอกได้แค่ {ratio:.2f}:1")

    def test_every_settings_section_has_a_line_explaining_it(self) -> None:
        expected = [
            "ข้อมูลเครื่อง POS", "เครื่องพิมพ์และใบเสร็จ", "เครื่องชั่ง / Barcode",
            "การ Sync และ API", "สำรองและกู้คืน SQLite", "ประวัติการพิมพ์ / Queue",
        ]

        self.assertEqual(list(SECTION_HINTS), expected, "หัวข้อต้องครบและเรียงตามภาพร่าง")
        for name, hint in SECTION_HINTS.items():
            self.assertTrue(hint.strip(), f"{name} ยังไม่มีคำอธิบาย")

    def test_pos_opens_before_cashier_authentication(self) -> None:
        source = inspect.getsource(run_ui)
        self.assertIn("window = PosWindow()", source)
        self.assertIn("window.showMaximized()", source)
        self.assertNotIn("QApplication(", source)
        self.assertNotIn("app.exec()", source)

    def test_checkout_authenticates_before_opening_payment(self) -> None:
        source = inspect.getsource(run_ui)
        pay = source[source.index("        def pay(self)"):source.index("        def ensure_sale_session(self)")]
        self.assertLess(pay.index("ensure_sale_session"), pay.index("PaymentDialog"))

    def test_settings_remain_it_protected_without_cashier_login(self) -> None:
        source = inspect.getsource(run_ui)
        settings = source[source.index("        def open_settings(self) -> None", source.index("class PosWindow")):]
        self.assertIn("has_local_it_pin", settings)
        self.assertIn("AdminAuthDialog", settings)

    def test_pos_dialogs_are_compact_and_have_a_close_path(self) -> None:
        source = inspect.getsource(run_ui)
        self.assertIn("self.setMinimumSize(860, 560)", source)
        self.assertIn("self.setWindowFlag(Qt.WindowCloseButtonHint, True)", source)
        self.assertIn("close_button.clicked.connect(self.reject)", source)
        self.assertIn("QToolButton#dialogClose", STYLE)

    def test_cashier_can_pick_a_synced_name_and_tap_a_pin(self) -> None:
        source = inspect.getsource(run_ui)
        self.assertIn('f"{cashier[\'name\']}  ·  {cashier[\'code\']}"', source)
        self.assertIn('"server_id": cashier["server_id"]', source)
        self.assertIn('for index, key in enumerate(["1", "2", "3"', source)

    def test_bound_cashier_can_start_online_without_retyping_a_pin(self) -> None:
        source = inspect.getsource(run_ui)
        self.assertIn('device_user_id', source)
        self.assertIn('cashier_login_mode', source)
        self.assertIn('online_cashier_login(\n                        None', source)

    def test_opening_shift_requests_and_reuses_opening_cash(self) -> None:
        source = inspect.getsource(run_ui)
        start = source.index("def ensure_sale_session")
        session = source[start:source.index("def open_settings", start)]
        self.assertIn('"เงินทอนตั้งต้น (บาท)"', session)
        self.assertIn("service.open_shift(", session)
        self.assertIn("opening_cash", session)
        self.assertNotIn('opening_cash=Decimal("0")', session)

    def test_opening_shift_is_local_first_and_does_not_block_on_erp(self) -> None:
        source = inspect.getsource(run_ui)
        start = source.index("def ensure_sale_session")
        session = source[start:source.index("def open_settings", start)]
        self.assertIn("service.queue_shift_open(self.shift_id)", session)
        self.assertIn("online.worker.wake()", session)
        self.assertNotIn("online.provisioning.open_server_shift", session)
        self.assertIn("Qt.WindowStaysOnTopHint", session)
        self.assertIn("เปิดหน้าล็อกอินไม่ได้", session)

    def test_cashier_login_dialog_is_raised_above_windows_osk(self) -> None:
        source = inspect.getsource(run_ui)
        login = source[source.index("class LoginDialog"):source.index("class PaymentDialog")]
        self.assertIn("Qt.WindowStaysOnTopHint", login)
        self.assertIn("Qt.WindowModal", login)

    def test_transfer_requires_visible_money_received_confirmation(self) -> None:
        source = inspect.getsource(run_ui)
        self.assertIn("โอน / QR", source)
        self.assertIn("ตรวจสอบแล้วว่าเงินเข้าบัญชีครบตามยอด", source)
        self.assertIn("not self.transfer_confirmed.isChecked()", source)
        self.assertIn("self.transfer_confirmed.toggled.connect(self._confirm_transfer_checkbox)", source)
        self.assertIn("self.confirm()", source)

    def test_pos_layout_adapts_to_small_pos_displays(self) -> None:
        source = inspect.getsource(run_ui)
        self.assertIn("def resizeEvent", source)
        self.assertIn('self.width() <= 1450 or self.height() <= 900', source)
        self.assertIn("def _product_columns_for_width", source)
        self.assertIn("self.grid_host.width()", source)
        self.assertIn("window.setMinimumSize(960, 600)", source)


if __name__ == "__main__":
    unittest.main()
