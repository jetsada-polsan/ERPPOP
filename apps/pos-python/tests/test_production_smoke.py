"""Production smoke must not use a different till/cashier or close an existing shift."""
from __future__ import annotations

import contextlib
import io
import unittest
from unittest.mock import Mock

from e2e import pos_smoke_production as smoke


class ProductionSmokeTest(unittest.TestCase):
    def setUp(self) -> None:
        self.output = io.StringIO()
        self.redirect = contextlib.redirect_stdout(self.output)
        self.redirect.__enter__()
        self.addCleanup(self.redirect.__exit__, None, None, None)

    def test_a_different_till_is_rejected(self) -> None:
        with self.assertRaises(SystemExit):
            smoke.verify_device({"branch_id": 3, "device": {"terminal_code": "POS-B002-01"}})

    def test_the_expected_till_returns_its_server_branch(self) -> None:
        self.assertEqual((3, "POS-B001-01"), smoke.verify_device({
            "branch_id": 3, "device": {"terminal_code": "POS-B001-01"},
        }))

    def test_selection_does_not_fall_back_to_another_cashier(self) -> None:
        prov = Mock()
        prov.online_cashier_login.return_value = {
            "selection_required": True, "cashiers": [{"id": 9, "code": "OTHER"}],
        }
        with self.assertRaises(SystemExit):
            smoke.login_cashier(prov, "test-only-pin", "B001")
        # A second login would select another person using the owner's PIN.
        prov.online_cashier_login.assert_called_once()

    def test_selection_uses_the_requested_code_even_when_it_is_not_first(self) -> None:
        prov = Mock()
        confirmed = {"cashier": {"id": 10, "code": "B001"}, "local_cashier_id": 1}
        prov.online_cashier_login.side_effect = [
            {"selection_required": True, "cashiers": [
                {"id": 9, "code": "OTHER"}, {"id": 10, "code": "B001"},
            ]}, confirmed,
        ]
        self.assertEqual(confirmed, smoke.login_cashier(prov, "test-only-pin", "B001"))
        prov.online_cashier_login.assert_called_with("test-only-pin", cashier_server_id=10)

    def test_a_mismatched_login_response_is_rejected(self) -> None:
        prov = Mock()
        prov.online_cashier_login.return_value = {"cashier": {"id": 9, "code": "OTHER"}}
        with self.assertRaises(SystemExit):
            smoke.login_cashier(prov, "test-only-pin", "B001")

    def test_required_pin_change_is_left_to_the_owner(self) -> None:
        prov = Mock()
        prov.online_cashier_login.return_value = {
            "cashier": {"id": 10, "code": "B001"}, "must_change_pin": True,
        }
        with self.assertRaises(SystemExit):
            smoke.login_cashier(prov, "test-only-pin", "B001")
        prov.change_cashier_pin.assert_not_called()

    def test_an_existing_shift_is_rejected_with_only_a_read_request(self) -> None:
        api = Mock()
        api.get.return_value = {"shift": {"id": 99, "status": "open"}}
        with self.assertRaises(SystemExit):
            smoke.verify_no_open_shift(api, 3, 10)
        api.get.assert_called_once_with("/api/pos/shift?branch_id=3&cashier_id=10")
        api.post.assert_not_called()

    def test_missing_shift_data_is_not_treated_as_an_idle_till(self) -> None:
        api = Mock()
        api.get.return_value = {}
        with self.assertRaises(SystemExit):
            smoke.verify_no_open_shift(api, 3, 10)

    def test_an_idle_till_passes_without_a_write_request(self) -> None:
        api = Mock()
        api.get.return_value = {"shift": None}
        smoke.verify_no_open_shift(api, 3, 10)
        api.post.assert_not_called()


if __name__ == "__main__":
    unittest.main()
