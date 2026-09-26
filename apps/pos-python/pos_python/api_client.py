from __future__ import annotations

import json
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request, urlopen

from .config import normalize_server_url


class LaravelApiError(RuntimeError):
    pass


# โฮสต์ที่ยอมให้ต่อแบบ http ได้ เพราะ traffic ไม่ออกนอกเครื่อง/นอกวงแลบ
_LOCAL_HOSTS = {"localhost", "127.0.0.1", "::1", "0.0.0.0"}


class LaravelPosClient:
    """Small stdlib client. The app talks only to Laravel HTTPS API, never PostgreSQL.

    บังคับ https กับปลายทางจริงเสมอ device token เดินทางในทุก request ถ้าหลุดไปวิ่ง
    บน http ใครดักสายก็ได้ token ไปสวมเป็นเครื่องขายได้ทันที ยอม http เฉพาะ localhost
    (เทสต์/รันเซิร์ฟเวอร์ในเครื่อง) หรือเมื่อสั่ง allow_insecure ตรง ๆ เท่านั้น
    """

    def __init__(self, base_url: str, device_token: str, timeout_seconds: int = 20, *, allow_insecure: bool = False):
        # Pair files from older installers may still contain the HTTP public
        # hostname. Normalize it here as a final guard, including callers that
        # construct the client directly instead of loading DeviceConfig.
        self.base_url = normalize_server_url(base_url)
        self.device_token = device_token
        self.timeout_seconds = timeout_seconds
        parts = urlsplit(self.base_url)
        host = (parts.hostname or "").lower()
        if parts.scheme.lower() != "https" and not (allow_insecure or host in _LOCAL_HOSTS):
            raise LaravelApiError(
                "ปลายทาง POS ต้องเป็น HTTPS เพื่อป้องกัน device token รั่วไหล "
                f"(ค่าที่อ่านได้: {self.base_url or 'ว่าง'}; production ใช้ https://erp.popstarcenter.com)"
            )

    def get(self, path: str) -> dict[str, Any]:
        return self._request("GET", path)

    def post(self, path: str, payload: dict[str, Any], *, idempotency_key: str | None = None) -> dict[str, Any]:
        headers = {"Content-Type": "application/json"}
        if idempotency_key:
            headers["Idempotency-Key"] = idempotency_key
        return self._request("POST", path, payload, headers)

    def _request(self, method: str, path: str, payload: dict[str, Any] | None = None, extra_headers: dict[str, str] | None = None) -> dict[str, Any]:
        headers = {"Accept": "application/json", "Authorization": f"Bearer {self.device_token}"}
        headers.update(extra_headers or {})
        request = Request(
            self.base_url + path, method=method, headers=headers,
            data=json.dumps(payload).encode("utf-8") if payload is not None else None,
        )
        try:
            with urlopen(request, timeout=self.timeout_seconds) as response:
                return json.loads(response.read().decode("utf-8"))
        except HTTPError as error:
            body = error.read().decode("utf-8", errors="replace")
            raise LaravelApiError(f"HTTP {error.code}: {body}") from error
        except URLError as error:
            raise LaravelApiError(f"network: {error.reason}") from error
