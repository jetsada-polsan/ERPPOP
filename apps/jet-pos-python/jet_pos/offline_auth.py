from __future__ import annotations

import base64
import hashlib
import hmac
from datetime import datetime, timezone


def verify_offline_pin(pin: str, credential: dict[str, object], now: datetime | None = None) -> bool:
    try:
        expires_at = datetime.fromisoformat(str(credential["expires_at"]).replace("Z", "+00:00"))
        current = now or datetime.now(timezone.utc)
        if expires_at <= current:
            return False
        salt = base64.b64decode(str(credential["salt"]), validate=True)
        expected = base64.b64decode(str(credential["verifier"]), validate=True)
        iterations = int(credential["iterations"])
        if iterations < 1:
            return False
        actual = hashlib.pbkdf2_hmac("sha256", pin.encode("utf-8"), salt, iterations, dklen=len(expected))
        return hmac.compare_digest(actual, expected)
    except (KeyError, TypeError, ValueError, UnicodeEncodeError):
        return False
