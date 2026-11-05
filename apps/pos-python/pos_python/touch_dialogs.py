"""Shared touch controls. No credentials or network calls are retained here."""
from decimal import Decimal, InvalidOperation
from PySide6.QtCore import Qt
from PySide6.QtGui import QRegularExpressionValidator
from PySide6.QtCore import QRegularExpression
from PySide6.QtWidgets import (QDialog, QVBoxLayout, QHBoxLayout, QGridLayout,
    QFrame, QLabel, QPushButton, QLineEdit, QWidget, QApplication)


class TouchDialog(QDialog):
    def __init__(self, parent=None, title='JET POS', subtitle='แตะหน้าจอเพื่อทำรายการ', palette=None):
        super().__init__(parent)
        from .ui import PALETTE
        p = palette or PALETTE
        self.setWindowFlags(Qt.Dialog | Qt.FramelessWindowHint)
        self.setWindowModality(Qt.WindowModal)
        self.setWindowTitle(title)
        self.setMinimumWidth(480)
        self.setStyleSheet(f'''QDialog {{ background:{p['surface']}; border:1px solid {p['border']}; border-radius:18px; }}
            QLabel {{ font-size:16px; }} QLineEdit {{ min-height:44px; font-size:24px; }}
            QPushButton {{ min-height:56px; font-size:18px; }}
            QFrame#touchHeader {{ background:{p['primary_dark']}; border-radius:12px; }}
            QFrame#touchHeader QLabel {{ color:{p['surface']}; background:transparent; }}''')
        self.body = QVBoxLayout(self)
        self.body.setSpacing(12)
        header = QFrame()
        header.setObjectName('touchHeader')
        row = QHBoxLayout(header)
        caption = QLabel(f'◆ {title}\n{subtitle}')
        caption.setWordWrap(True)
        row.addWidget(caption, 1)
        close = QPushButton('×')
        close.setFixedWidth(64)
        close.setAutoDefault(False)
        close.clicked.connect(self.reject)
        row.addWidget(close)
        self.body.addWidget(header)

    def showEvent(self, event):
        # Temporarily replace any existing touch workspace, including unrelated
        # top-level dialogs. Restore it after this child completes.
        if not getattr(self, '_restoring', False):
            self._replaced = [w for w in QApplication.topLevelWidgets()
                              if isinstance(w, QDialog) and w is not self and w.isVisible()]
            for dialog in self._replaced:
                dialog._being_replaced = True
                dialog.hide()
                dialog._being_replaced = False
        parent = self.parentWidget()
        if parent is not None and not hasattr(self, '_backdrop'):
            from .ui import PALETTE
            self._backdrop = QWidget(parent.window())
            self._backdrop.setGeometry(parent.window().rect())
            rgb = tuple(int(PALETTE['text'][i:i+2], 16) for i in (1,3,5))
            self._backdrop.setStyleSheet('background:rgba(%d,%d,%d,150);' % rgb)
            self._backdrop.show()
            self._backdrop.raise_()
        if hasattr(self, '_backdrop'):
            self._backdrop.show()
            self._backdrop.raise_()
        super().showEvent(event)

    def hideEvent(self, event):
        if hasattr(self, '_backdrop'):
            self._backdrop.hide()
        super().hideEvent(event)
        if getattr(self, '_being_replaced', False):
            return
        replaced, self._replaced = getattr(self, '_replaced', []), []
        for dialog in replaced:
            if not getattr(dialog, '_suppress_restore', False):
                dialog._restoring = True
                dialog.show()
                dialog._restoring = False

    def confirm(self, text='ยืนยัน', callback=None):
        button = QPushButton(text)
        button.setObjectName('primary')
        button.clicked.connect(callback or self.accept)
        self.body.addWidget(button)
        return button


class NumPad(QWidget):
    def __init__(self, target, parent=None, pin=False):
        super().__init__(parent)
        self.target = target
        self.pin = pin
        grid = QGridLayout(self)
        grid.setContentsMargins(0, 0, 0, 0)
        keys = ['1','2','3','4','5','6','7','8','9','ล้าง' if pin else '.','0','⌫']
        for i, key in enumerate(keys):
            button = QPushButton(key)
            button.setMinimumSize(64, 64)
            button.setStyleSheet('font-size:24px; padding:0; min-height:64px; min-width:64px;')
            button.setFocusPolicy(Qt.NoFocus)
            button.setAutoDefault(False)
            button.clicked.connect(lambda checked=False, k=key: self.press(k))
            grid.addWidget(button, i // 3, i % 3)

    def press(self, key):
        if key == 'ล้าง':
            self.target.clear()
        elif key == '⌫':
            self.target.backspace()
        elif key == '.' and not self.pin:
            if '.' not in self.target.text() or self.target.hasSelectedText():
                self.target.insert('0.' if not self.target.text() else '.')
        elif key.isascii() and key.isdigit():
            self.target.insert(key)
        self.target.setFocus()


class PinPad(NumPad):
    def __init__(self, target, parent=None):
        super().__init__(target, parent, pin=True)


class ValueDialog(TouchDialog):
    def __init__(self, parent=None, title='จำนวนเงิน', subtitle='แตะตัวเลข หรือพิมพ์ด้วยคีย์บอร์ด', value='', pin=False):
        super().__init__(parent, title, subtitle)
        self.entry = QLineEdit(str(value))
        self.entry.setObjectName('touchValue')
        self.entry.setValidator(QRegularExpressionValidator(QRegularExpression('[0-9]{0,20}' if pin else '[0-9]{0,12}(\\.[0-9]{0,3})?'), self.entry))
        if pin:
            self.entry.setEchoMode(QLineEdit.Password)
        if pin:
            from .branding import logo_label
            self.body.addWidget(logo_label(height=48))
        self.body.addWidget(self.entry)
        self.dots = QLabel('○ ○ ○ ○') if pin else None
        if self.dots:
            self.body.addWidget(self.dots)
        self.pad = PinPad(self.entry) if pin else NumPad(self.entry)
        self.body.addWidget(self.pad)
        self.submit = self.confirm()
        self.entry.textChanged.connect(self.validate)
        self.entry.returnPressed.connect(lambda: self.accept() if self.submit.isEnabled() else None)
        self.pin = pin
        self.validate()
        self.entry.selectAll()

    def validate(self):
        text = self.entry.text()
        if self.dots:
            self.dots.setText(' '.join('●' if i < len(text) else '○' for i in range(max(4, len(text)))))
        try:
            valid = len(text) >= 4 if self.pin else bool(text) and Decimal(text).is_finite() and Decimal(text) >= 0
        except InvalidOperation:
            valid = False
        self.submit.setEnabled(valid)

    @classmethod
    def get_value(cls, parent, title, subtitle, value='', pin=False):
        dialog = cls(parent, title, subtitle, value, pin)
        accepted = dialog.exec() == QDialog.Accepted
        return dialog.entry.text(), accepted

class ChoiceDialog(TouchDialog):
    def __init__(self, parent, title, choices):
        super().__init__(parent, title, 'เลือกรายการที่ต้องการ')
        from PySide6.QtWidgets import QScrollArea
        self.choice = None
        scroll = QScrollArea()
        scroll.setWidgetResizable(True)
        host = QWidget()
        rows = QVBoxLayout(host)
        for label, value in choices:
            button = QPushButton(str(label))
            button.clicked.connect(lambda checked=False, v=value: self.choose(v))
            rows.addWidget(button)
        scroll.setWidget(host)
        self.body.addWidget(scroll)
        self.resize(520, 600)

    def choose(self, value):
        self.choice = value
        self.accept()


class TextDialog(TouchDialog):
    def __init__(self, parent, title, hint, value=''):
        super().__init__(parent, title, hint)
        self.entry = QLineEdit(value)
        self.body.addWidget(self.entry)
        self.confirm()


class TouchMessage:
    """Short touch notices and explicit confirmations with safe keyboard defaults."""
    Yes = 1
    No = 0

    @staticmethod
    def information(parent, title, text):
        dialog = TouchDialog(parent, title)
        label = QLabel(text)
        label.setWordWrap(True)
        label.setMaximumWidth(700)
        dialog.body.addWidget(label)
        dialog.confirm('ตกลง').setDefault(True)
        return dialog.exec()

    warning = information
    critical = information

    @staticmethod
    def question(parent, title, text):
        dialog = TouchDialog(parent, title)
        label = QLabel(text)
        label.setWordWrap(True)
        label.setMaximumWidth(700)
        dialog.body.addWidget(label)
        cancel = dialog.confirm('กลับ', dialog.reject)
        cancel.setDefault(True)
        cancel.setFocus()
        confirm = dialog.confirm('ยืนยัน')
        confirm.setAutoDefault(False)
        return TouchMessage.Yes if dialog.exec() == QDialog.Accepted else TouchMessage.No
