import json
import unittest

from jet_pos.api import PosApiClient


class PosApiClientTest(unittest.TestCase):
    def test_scan_posts_the_keyboard_barcode(self) -> None:
        def transport(method, url, headers, body):
            self.assertEqual(method, "POST")
            self.assertTrue(url.endswith("/api/pos/scan"))
            self.assertEqual(json.loads(body), {"branch_id": 7, "barcode": "885123", "source": "keyboard"})
            self.assertEqual(headers["Authorization"], "Bearer device-token")
            return 200, b'{"product":{"id":1,"sku_code":"P1"},"qty":1}'

        client = PosApiClient("http://127.0.0.1:8000", "device-token", transport)
        self.assertEqual(client.scan(7, "885123")["product"]["id"], 1)

    def test_member_search_encodes_the_query(self) -> None:
        def transport(method, url, headers, body):
            self.assertEqual(method, "GET")
            self.assertTrue(url.endswith("/api/pos/members/search?q=A%20%26%20B"))
            return 200, b'[{"id": 4, "member_code": "M004"}]'

        client = PosApiClient("http://127.0.0.1:8000", "device-token", transport)
        self.assertEqual(client.search_members("A & B")[0]["id"], 4)


if __name__ == "__main__":
    unittest.main()
