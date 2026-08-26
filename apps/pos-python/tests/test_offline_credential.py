"""ตรวจ PIN ออฟไลน์ต้องได้ผลตรงกับ credential ที่ Laravel ออกให้

vector ด้านล่างสร้างจาก PHP hash_pbkdf2('sha256', pin, salt, 120000, 32, true) จริง
(ดู PosApiController::offlineCredential) ถ้าฝั่ง Python คำนวณต่างไปแม้นิดเดียว
พนักงานจะ login ออฟไลน์ไม่ได้ทั้งที่ PIN ถูก เทสต์นี้จับ regression ตรงนั้น
"""
from __future__ import annotations

import unittest

from pos_python.services import verify_offline_credential

# ค่าจาก PHP: pin=4821, salt=hmac_sha256("pos-offline:7:42", key), 120000 รอบ
PIN = "4821"
SALT_B64 = "4Ds111iiPI0bhfW4Btks8kMTMk5zDYXHsjHLPuo8jQ0="
VERIFIER_B64 = "r9QEOu9jc1PqdD3KL+g7KIaZ3/7DfaUzapj3UqtjbmI="
ITERATIONS = 120000


class OfflineCredentialTest(unittest.TestCase):
    def test_the_right_pin_matches_the_laravel_verifier(self) -> None:
        self.assertTrue(verify_offline_credential(PIN, SALT_B64, VERIFIER_B64, ITERATIONS))

    def test_a_wrong_pin_is_rejected(self) -> None:
        self.assertFalse(verify_offline_credential("0000", SALT_B64, VERIFIER_B64, ITERATIONS))
        self.assertFalse(verify_offline_credential("48210", SALT_B64, VERIFIER_B64, ITERATIONS))

    def test_broken_credential_never_passes(self) -> None:
        self.assertFalse(verify_offline_credential(PIN, "not-base64!!", VERIFIER_B64, ITERATIONS))
        self.assertFalse(verify_offline_credential(PIN, SALT_B64, VERIFIER_B64, 0))
        self.assertFalse(verify_offline_credential(PIN, "", "", ITERATIONS))


if __name__ == "__main__":
    unittest.main()
