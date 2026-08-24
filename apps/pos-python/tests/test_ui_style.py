"""หน้าตาต้องตรงกับภาพร่างที่อนุมัติแล้ว

ทดสอบสไตล์ได้แค่ระดับ "ค่าที่ตกลงกันไว้ยังอยู่ไหม" ซึ่งพอสำหรับกันคนเผลอเปลี่ยน
สีแบรนด์หรือรื้อหัวข้อเมนูทิ้งโดยไม่ตั้งใจ ส่วนหน้าตาจริงต้องเปิดโปรแกรมดู
"""
from __future__ import annotations

import unittest

from pos_python.ui import SECTION_HINTS, STYLE

BRAND_RED = "#c9212d"


class UiStyleTest(unittest.TestCase):
    def test_the_brand_colour_is_the_red_from_the_mockup(self) -> None:
        self.assertIn(BRAND_RED, STYLE)
        # เขียวเป็นสีที่ผมใส่ไว้ก่อนเห็นภาพร่าง ต้องไม่หลงเหลือ
        self.assertNotIn("#0f766e", STYLE)

    def test_the_selected_menu_item_is_marked_the_way_the_mockup_marks_it(self) -> None:
        self.assertIn("#navItem:checked", STYLE)
        self.assertIn("#ffe9eb", STYLE)
        self.assertIn(f"border-left: 4px solid {BRAND_RED}", STYLE)

    def test_the_receipt_sits_on_a_grey_backing_like_paper_on_a_desk(self) -> None:
        self.assertIn("#receiptBg", STYLE)
        self.assertIn("#d4dade", STYLE)
        self.assertIn("#receiptPaper", STYLE)

    def test_the_warning_note_keeps_its_amber_styling(self) -> None:
        self.assertIn("#note", STYLE)
        self.assertIn("#e38800", STYLE)
        self.assertIn("#fff4df", STYLE)

    def test_every_settings_section_has_a_line_explaining_it(self) -> None:
        expected = [
            "ข้อมูลเครื่อง POS", "เครื่องพิมพ์และใบเสร็จ", "เครื่องชั่ง / Barcode",
            "การ Sync และ API", "สำรองและกู้คืน SQLite", "ประวัติการพิมพ์ / Queue",
        ]

        self.assertEqual(list(SECTION_HINTS), expected, "หัวข้อต้องครบและเรียงตามภาพร่าง")
        for name, hint in SECTION_HINTS.items():
            self.assertTrue(hint.strip(), f"{name} ยังไม่มีคำอธิบาย")


if __name__ == "__main__":
    unittest.main()
