from __future__ import annotations

import json
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


class LaravelApiError(RuntimeError):
    pass


class LaravelPosClient:
    """Small stdlib client. The app talks only to Laravel HTTPS API, never PostgreSQL."""

    def __init__(self, base_url: str, device_token: str, timeout_seconds: int = 20):
        self.base_url = base_url.rstrip("/")
        self.device_token = device_token
        self.timeout_seconds = timeout_seconds

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
