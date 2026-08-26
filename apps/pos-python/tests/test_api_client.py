"""API client ต้องบังคับ https กับปลายทางจริง — device token เดินทางทุก request"""
from __future__ import annotations

import unittest

from pos_python.api_client import LaravelApiError, LaravelPosClient


class ApiClientSecurityTest(unittest.TestCase):
    def test_http_to_a_real_host_is_refused(self) -> None:
        with self.assertRaises(LaravelApiError):
            LaravelPosClient("http://erp.popstar.example", "tok")

    def test_https_is_accepted(self) -> None:
        client = LaravelPosClient("https://erp.popstar.example", "tok")
        self.assertEqual(client.base_url, "https://erp.popstar.example")

    def test_http_localhost_is_allowed_for_local_dev(self) -> None:
        for url in ("http://127.0.0.1:8000", "http://localhost:8123"):
            self.assertTrue(LaravelPosClient(url, "tok").base_url)

    def test_insecure_can_be_opted_into_explicitly(self) -> None:
        client = LaravelPosClient("http://10.0.0.5", "tok", allow_insecure=True)
        self.assertEqual(client.base_url, "http://10.0.0.5")


if __name__ == "__main__":
    unittest.main()
