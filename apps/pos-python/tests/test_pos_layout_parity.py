"""Laravel and the Python POS must normalize pos_layout.runtime identically.

Both suites read the same fixture. The Laravel side is
tests/Unit/PosLayoutParityTest.php — change the fixture, run both.
"""

from __future__ import annotations

import json
import unittest
from pathlib import Path

from pos_python.ui import POS_LAYOUT_DEFAULT_RUNTIME, normalize_pos_layout, run_ui
import inspect

FIXTURE = Path(__file__).resolve().parents[3] / "tests" / "Fixtures" / "pos-layout-runtime-parity.json"


def _fixture() -> dict:
    if not FIXTURE.is_file():
        # Fail loudly: a skipped parity check is how the two runtimes drift apart.
        raise AssertionError(f"shared parity fixture is missing: {FIXTURE}")
    return json.loads(FIXTURE.read_text(encoding="utf-8"))


class PosLayoutParityTest(unittest.TestCase):
    def test_the_defaults_match_the_shared_contract(self) -> None:
        self.assertEqual(_fixture()["defaults"], POS_LAYOUT_DEFAULT_RUNTIME)

    def test_runtime_normalizes_exactly_like_laravel(self) -> None:
        fixture = _fixture()
        for case in fixture["cases"]:
            with self.subTest(case["name"]):
                expected = {**fixture["defaults"], **case["expected"]}
                got = normalize_pos_layout({"runtime": case["input"]})["runtime"]
                self.assertEqual(expected, got)
                # int vs bool matters: True == 1 in Python but not in PHP's assertSame
                for key, value in expected.items():
                    self.assertIs(type(got[key]), type(value), key)

    def test_a_malformed_component_x_cannot_crash_the_window(self) -> None:
        source = inspect.getsource(run_ui)
        layout_x = source[source.index("def _layout_x"):source.index("def build_numpad")]
        self.assertIn("_layout_int(", layout_x)
        self.assertNotIn('(int(item.get("x"', layout_x)


if __name__ == "__main__":
    unittest.main()
