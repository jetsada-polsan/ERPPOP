from __future__ import annotations

import json
from dataclasses import dataclass
from ipaddress import ip_address
from typing import Callable, Optional
from urllib.error import HTTPError, URLError
from urllib.parse import urlparse
from urllib.request import Request, urlopen


class PosApiError(RuntimeError):
    def __init__(self, message: str, status_code: int | None = None) -> None:
        super().__init__(message)
        self.status_code = status_code


def safe_server_url(value: str, allow_insecure: bool = False) -> str:
    url = value.strip().rstrip("/")
    parsed = urlparse(url if "://" in url else f"https://{url}")
    if not parsed.scheme or not parsed.hostname:
        raise ValueError("invalid ERP server URL")
    if parsed.scheme == "http" and not allow_insecure:
        try:
            is_private = ip_address(parsed.hostname).is_private or parsed.hostname == "127.0.0.1"
        except ValueError:
            is_private = parsed.hostname == "localhost"
        if not is_private:
            raise ValueError("public ERP endpoints must use HTTPS")
    if parsed.hostname == "erp.popstarcenter.com":
        raise ValueError("production is forbidden for the Python POS UAT candidate")
    return url


Transport = Callable[[str, str, dict[str, str], Optional[bytes]], tuple[int, bytes]]


def default_transport(method: str, url: str, headers: dict[str, str], body: bytes | None) -> tuple[int, bytes]:
    request = Request(url, data=body, headers=headers, method=method)
    try:
        with urlopen(request, timeout=15) as response:
            return response.status, response.read()
    except HTTPError as error:
        return error.code, error.read()
    except URLError as error:
        raise PosApiError("ERP is unreachable") from error


@dataclass
class PosApiClient:
    server_url: str
    device_token: str
    transport: Transport = default_transport

    def __post_init__(self) -> None:
        self.server_url = safe_server_url(self.server_url)

    def checkout(self, payload: dict[str, object], idempotency_key: str) -> dict[str, object]:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        status, raw = self.transport(
            "POST",
            f"{self.server_url}/api/pos/checkout",
            {
                "Authorization": f"Bearer {self.device_token}",
                "Content-Type": "application/json",
                "Accept": "application/json",
                "Idempotency-Key": idempotency_key,
            },
            body,
        )
        try:
            decoded = json.loads(raw.decode("utf-8") or "{}")
        except json.JSONDecodeError:
            decoded = {"message": "ERP returned an invalid response"}
        if status >= 400:
            raise PosApiError(str(decoded.get("message") or "checkout rejected"), status)
        if not isinstance(decoded, dict):
            raise PosApiError("ERP returned an invalid checkout response")
        return decoded
