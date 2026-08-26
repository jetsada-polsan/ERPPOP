from __future__ import annotations

import unittest
from unittest.mock import patch

import main as pos_main


class EntrypointTest(unittest.TestCase):
    def test_installed_application_starts_the_ui_without_arguments(self) -> None:
        with patch.object(pos_main, "launch_ui") as launch_ui, patch("sys.argv", ["PopCentral-POS.exe"]):
            pos_main.main()

        launch_ui.assert_called_once_with()


if __name__ == "__main__":
    unittest.main()
