from __future__ import annotations

import unittest
from unittest.mock import patch

import main as pos_main


class EntrypointTest(unittest.TestCase):
    def test_installed_application_starts_the_ui_without_arguments(self) -> None:
        app = object()
        with patch.object(pos_main, "create_application", return_value=app) as create_application, \
             patch.object(pos_main, "launch_ui", return_value=None) as launch_ui, \
             patch("sys.argv", ["PopCentral-POS.exe"]):
            pos_main.main()

        create_application.assert_called_once_with()
        launch_ui.assert_called_once_with(app)

    def test_packaged_self_check_does_not_open_the_ui(self) -> None:
        with patch.object(pos_main, "self_check") as self_check, \
             patch.object(pos_main, "create_application") as create_application, \
             patch("sys.argv", ["PopCentral-POS.exe", "--self-check"]):
            pos_main.main()

        self_check.assert_called_once_with()
        create_application.assert_not_called()


if __name__ == "__main__":
    unittest.main()
