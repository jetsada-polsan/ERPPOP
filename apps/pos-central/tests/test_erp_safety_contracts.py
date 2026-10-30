from types import SimpleNamespace
import re

import pytest

from services.promptpay import promptpay_payload
from sync_worker import transfer_payment_is_confirmed


def test_promptpay_payload_uses_erp_merchant_reference_and_exact_amount():
    payload = promptpay_payload("0812345678", "10.25")

    assert "0066812345678" in payload
    assert "540510.25" in payload
    assert re.search(r"6304[0-9A-F]{4}$", payload)


def test_promptpay_payload_rejects_missing_or_invalid_erp_configuration():
    with pytest.raises(ValueError, match="PromptPay ID"):
        promptpay_payload("", "10.00")

    with pytest.raises(ValueError, match="PromptPay ID"):
        promptpay_payload("12345678", "10.00")


def test_transfer_retry_requires_matching_saved_confirmation_and_suffix():
    payment = SimpleNamespace(payment_confirmed=True, transfer_account_last4="4821")

    assert transfer_payment_is_confirmed(payment, {"transfer_account_last4": "4821"})
    assert not transfer_payment_is_confirmed(payment, {"transfer_account_last4": "0000"})
    assert not transfer_payment_is_confirmed(
        SimpleNamespace(payment_confirmed=False, transfer_account_last4="4821"),
        {"transfer_account_last4": "4821"},
    )
    assert not transfer_payment_is_confirmed(payment, {"transfer_account_last4": "48A1"})
