"""รูปแบบป้ายเครื่องชั่งมาจาก ERP ไม่ใช่จากการเดาในเครื่อง

เครื่องชั่งคนละรุ่นออกป้ายคนละแบบ เดารูปแบบผิดคือคิดเงินผิดที่หน้าเคาน์เตอร์ทันที
และเครื่องที่ยังไม่ได้ sync ต้องบอกให้ชัดว่ายังไม่มีกฎ ไม่ใช่บอกว่าป้ายผิด
"""
from __future__ import annotations

import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from pos_python.barcode import (
    decode_scale_label,
    ean13_check_digit,
    load_scale_profiles,
    replace_scale_profiles,
    scale_cart_line,
)
from pos_python.database import connect

POPSTAR_800 = {
    "code": "POPSTAR-800", "prefix": "800", "plu_length": 6, "value_length": 6,
    "value_type": "price", "check_digit": "ean13", "total_length": 13,
}
POPSTAR_800_12 = {
    "code": "POPSTAR-800-12", "prefix": "800", "plu_length": 6, "value_length": 5,
    "value_type": "price", "check_digit": "none", "total_length": 12,
}


def label(plu: str, value: str) -> str:
    twelve = plu + value
    return twelve + str(ean13_check_digit(twelve))


class ScaleProfileTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / "pos.db")

    def tearDown(self) -> None:
        self.db.close()
        self.tmp.cleanup()

    def test_without_synced_profiles_nothing_is_read_as_a_scale_label(self) -> None:
        self.assertEqual(load_scale_profiles(self.db), [])
        self.assertIsNone(decode_scale_label(self.db, label("800123", "012550")))

    def test_a_till_that_has_not_synced_says_so_instead_of_blaming_the_label(self) -> None:
        with self.assertRaises(ValueError) as raised:
            scale_cart_line(self.db, label("800123", "012550"))

        # ทางแก้ของ "ยังไม่ sync" กับ "ป้ายผิด" คนละเรื่องกัน ข้อความจึงต้องแยก
        self.assertIn("ยังไม่ได้รับรูปแบบป้าย", str(raised.exception))

    def test_the_profile_decides_how_the_digits_are_split(self) -> None:
        replace_scale_profiles(self.db, [POPSTAR_800])

        decoded = decode_scale_label(self.db, label("800123", "012550"))

        self.assertEqual(decoded.plu, "800123")
        self.assertEqual(decoded.total_price, Decimal("125.50"))

    def test_a_new_scale_format_needs_no_code_change(self) -> None:
        # เครื่องรุ่นใหม่: ขึ้นต้น 27 · PLU 5 หลัก · น้ำหนักเป็นกรัม 5 หลัก · ไม่ตรวจ check digit
        replace_scale_profiles(self.db, [{
            "code": "NEW-SCALE", "prefix": "27", "plu_length": 5, "value_length": 5,
            "value_type": "weight", "check_digit": "none", "total_length": 10,
        }])

        decoded = decode_scale_label(self.db, "2700101250")

        self.assertEqual(decoded.plu, "27001")
        self.assertEqual(decoded.total_price, Decimal("1250"), "ประเภท weight ต้องคืนค่าดิบ ไม่หารร้อย")

    def test_a_tampered_label_is_refused_when_the_profile_checks_the_digit(self) -> None:
        replace_scale_profiles(self.db, [POPSTAR_800])
        valid = label("800123", "012550")

        self.assertIsNone(decode_scale_label(self.db, "800124" + valid[6:]))

    def test_the_checking_profile_wins_over_the_one_that_does_not_check(self) -> None:
        # ป้าย 13 หลักไม่ควรถูกอ่านด้วยกฎ 12 หลัก แต่ต้องแน่ใจว่าลำดับกฎเป็นตัวตัดสิน
        replace_scale_profiles(self.db, [POPSTAR_800_12, POPSTAR_800])
        profiles = load_scale_profiles(self.db)

        self.assertEqual(profiles[0]["check_digit"], "ean13",
                         "กฎที่ตรวจ check digit ต้องถูกลองก่อน ไม่งั้นป้ายปลอมจะผ่าน")

    def test_replacing_profiles_never_mixes_old_rules_with_new(self) -> None:
        replace_scale_profiles(self.db, [POPSTAR_800])
        replace_scale_profiles(self.db, [{
            "code": "ONLY-801", "prefix": "801", "plu_length": 6, "value_length": 6,
            "value_type": "price", "check_digit": "ean13", "total_length": 13,
        }])

        codes = [row["code"] for row in load_scale_profiles(self.db)]
        self.assertEqual(codes, ["ONLY-801"])
        self.assertIsNone(decode_scale_label(self.db, label("800123", "012550")),
                          "กฎเก่าที่ถูกถอดออกต้องไม่ค้างอยู่ในเครื่อง")


if __name__ == "__main__":
    unittest.main()
