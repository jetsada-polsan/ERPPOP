from __future__ import annotations

import json
from dataclasses import dataclass
from ipaddress import ip_address
from typing import Callable, Optional
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlparse
from urllib.request import Request, urlopen


class PosApiError(RuntimeError):
    def __init__(self, message: str, status_code: int | None = None) -> None:
        super().__init__(message)
        self.status_code = status_code


def safe_server_url(value: str, allow_insecure: bool = False, allow_production: bool = False) -> str:
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
    if parsed.hostname == "erp.popstarcenter.com" and not allow_production:
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
    allow_production: bool = False

    def __post_init__(self) -> None:
        self.server_url = safe_server_url(self.server_url, allow_production=self.allow_production)

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

    def get(self, path: str) -> object:
        status, raw = self.transport(
            "GET",
            f"{self.server_url}/api/pos{path}",
            {"Authorization": f"Bearer {self.device_token}", "Accept": "application/json"},
            None,
        )
        try:
            decoded = json.loads(raw.decode("utf-8") or "{}")
        except json.JSONDecodeError as error:
            raise PosApiError("ERP returned an invalid response", status) from error
        if status >= 400:
            message = decoded.get("message") if isinstance(decoded, dict) else None
            raise PosApiError(str(message or "ERP request rejected"), status)
        return decoded

    def post(self, path: str, payload: dict[str, object], idempotency_key: str | None = None) -> object:
        headers = {
            "Authorization": f"Bearer {self.device_token}",
            "Accept": "application/json",
            "Content-Type": "application/json",
        }
        if idempotency_key:
            headers["Idempotency-Key"] = idempotency_key
        status, raw = self.transport("POST", f"{self.server_url}/api/pos{path}", headers, json.dumps(payload, ensure_ascii=False).encode("utf-8"))
        try:
            decoded = json.loads(raw.decode("utf-8") or "{}")
        except json.JSONDecodeError as error:
            raise PosApiError("ERP returned an invalid response", status) from error
        if status >= 400 or (isinstance(decoded, dict) and decoded.get("success") is False):
            message = decoded.get("message") if isinstance(decoded, dict) else None
            raise PosApiError(str(message or "ERP request rejected"), status)
        return decoded

    def ping(self) -> dict[str, object]:
        response = self.get("/ping")
        if not isinstance(response, dict):
            raise PosApiError("ERP returned an invalid device profile")
        return response

    def products(self, branch_id: int) -> list[dict[str, object]]:
        response = self.get(f"/products?all=1&branch_id={branch_id}")
        if not isinstance(response, list):
            raise PosApiError("ERP returned an invalid product catalog")
        return [row for row in response if isinstance(row, dict)]

    def promotions(self, branch_id: int) -> list[dict[str, object]]:
        response = self.get(f"/promotions?branch_id={branch_id}&include_upcoming=1&bill_promotions=1&mix_promotions=1")
        if not isinstance(response, list):
            raise PosApiError("ERP returned an invalid promotion catalog")
        return [row for row in response if isinstance(row, dict)]

    def scan(self, branch_id: int, barcode: str) -> dict[str, object]:
        response = self.post("/scan", {"branch_id": branch_id, "barcode": barcode, "source": "keyboard"})
        if not isinstance(response, dict) or not isinstance(response.get("product"), dict):
            raise PosApiError("ERP returned an invalid scan result")
        return response

    def search_members(self, query: str) -> list[dict[str, object]]:
        response = self.get(f"/members/search?q={quote(query)}")
        if not isinstance(response, list):
            raise PosApiError("ERP returned an invalid member result")
        return [row for row in response if isinstance(row, dict)]
