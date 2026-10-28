"""Production smoke must not use a different till/cashier or close an existing shift."""
from __future__ import annotations

import contextlib
import io
import os
import subprocess
import unittest
from unittest.mock import Mock, patch

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


class ExistingDeviceTokenTest(unittest.TestCase):
    def test_token_is_captured_in_memory_without_being_printed_or_passed_in_argv(self) -> None:
        token = "x" * 48  # Synthetic fixture, never a real credential.
        output = io.StringIO()
        with patch.object(smoke.subprocess, "run", return_value=Mock(
            returncode=0, stdout='{"token":"' + token + '"}', stderr="",
        )) as run, contextlib.redirect_stdout(output):
            self.assertEqual(token, smoke.existing_device_token())
        self.assertNotIn(token, output.getvalue())
        args, kwargs = run.call_args
        self.assertNotIn(token, str(args))
        self.assertTrue(kwargs["capture_output"])
        self.assertEqual(30, kwargs["timeout"])
        self.assertIn("BatchMode=yes", args[0])

    def test_failed_ssh_never_exposes_its_output(self) -> None:
        secret = "do-not-echo-response-material"
        with patch.object(smoke.subprocess, "run", return_value=Mock(
            returncode=1, stdout=secret, stderr=secret,
        )), self.assertRaises(RuntimeError) as error:
            smoke.existing_device_token()
        self.assertNotIn(secret, str(error.exception))

    def test_ssh_timeout_never_exposes_partial_output(self) -> None:
        with patch.object(smoke.subprocess, "run", side_effect=subprocess.TimeoutExpired(
            "ssh", 30, output="sensitive-partial-output",
        )), self.assertRaises(RuntimeError) as error:
            smoke.existing_device_token()
        self.assertNotIn("sensitive-partial-output", str(error.exception))

    def test_invalid_token_response_is_rejected_without_echoing_it(self) -> None:
        with patch.object(smoke.subprocess, "run", return_value=Mock(
            returncode=0, stdout='{"token":"invalid-private-value"}', stderr="",
        )), self.assertRaises(RuntimeError) as error:
            smoke.existing_device_token()
        self.assertNotIn("invalid-private-value", str(error.exception))

    def test_production_token_cannot_be_requested_for_another_destination(self) -> None:
        with patch.dict(os.environ, {"POS_SMOKE_URL": "https://example.com"}), \
                patch.object(smoke, "existing_device_token") as load, \
                patch.object(smoke.getpass, "getpass") as prompt, \
                self.assertRaises(SystemExit):
            smoke.main(["--token-from-ssh"])
        load.assert_not_called()
        prompt.assert_not_called()

    def test_ssh_mode_prompts_only_for_the_owners_pin(self) -> None:
        token = "x" * 48
        output = io.StringIO()
        with patch.dict(os.environ, {"POS_SMOKE_URL": "https://erp.popstarcenter.com",
                                    "POS_SMOKE_CASHIER": "B001"}), \
                patch.object(smoke, "existing_device_token", return_value=token), \
                patch.object(smoke.getpass, "getpass", side_effect=KeyboardInterrupt) as prompt, \
                patch.object(smoke, "connect") as connect, \
                contextlib.redirect_stdout(output), self.assertRaises(KeyboardInterrupt):
            smoke.main(["--token-from-ssh"])
        prompt.assert_called_once_with("PIN ของ B001: ")
        connect.assert_not_called()
        self.assertNotIn(token, output.getvalue())


if __name__ == "__main__":
    unittest.main()
