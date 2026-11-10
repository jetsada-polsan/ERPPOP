"""Script to capture offscreen screenshots of all POS dialogs at 1024x768 and 1366x768."""
import os
import sys
import tempfile
from decimal import Decimal
from pathlib import Path

os.environ['QT_QPA_PLATFORM'] = 'offscreen'

import pytest
from PySide6.QtCore import Qt, QRect, QPoint
from PySide6.QtGui import QColor, QFont, QPainter, QPen, QPixmap
from PySide6.QtWidgets import (QApplication, QDialog, QWidget, QFrame, QLabel,
    QScrollArea, QHBoxLayout, QVBoxLayout, QLineEdit, QPushButton,
    QTableWidget, QTableWidgetItem, QHeaderView, QDialogButtonBox)

# Ensure pos_python is in path
sys.path.insert(0, str(Path(__file__).parent))

from pos_python.database import connect
from pos_python.order import Order, OrderLine
from pos_python.services import PosService, now
from pos_python.shift_float import FloatClose
from pos_python.touch_dialogs import ChoiceDialog, TextDialog, TouchDialog, TouchMessage, ValueDialog
from pos_python.ui import APP_VERSION, PALETTE, run_ui

APP = QApplication.instance() or QApplication([])

OUTPUT_DIR = Path(__file__).resolve().parent.parent.parent / "docs" / "ai" / "pos-popup-shots"
OUTPUT_DIR.mkdir(parents=True, exist_ok=True)


class AdminAuthDialog(TouchDialog):
    def __init__(self, parent):
        super().__init__(parent)
        from PySide6.QtWidgets import QFormLayout, QLineEdit, QDialogButtonBox, QLabel
        self.setWindowTitle("ยืนยันผู้ดูแลก่อนตั้งค่า POS")
        self.setMinimumWidth(390)
        layout = self.body
        hint = QLabel("เมนูตั้งค่าใช้ได้เฉพาะผู้ดูแลที่มีสิทธิ์ตั้งค่า POS")
        hint.setWordWrap(True)
        layout.addWidget(hint)
        form = QFormLayout()
        self.username = QLineEdit()
        self.username.setPlaceholderText("username / email / เบอร์โทร")
        self.password = QLineEdit()
        self.password.setEchoMode(QLineEdit.Password)
        self.password.setPlaceholderText("รหัสผ่าน ERP")
        form.addRow("ผู้ดูแล", self.username)
        form.addRow("รหัสผ่าน", self.password)
        layout.addLayout(form)
        buttons = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
        buttons.button(QDialogButtonBox.Ok).setText("ยืนยัน")
        buttons.accepted.connect(self.accept)
        buttons.rejected.connect(self.reject)
        layout.addWidget(buttons)


def get_settings_dialog_cls(window):
    for cell in window.open_settings.__closure__:
        if hasattr(cell.cell_contents, "__name__") and cell.cell_contents.__name__ == "SettingsDialog":
            return cell.cell_contents
    return None


def create_mock_environment():
    tmp_path = Path(tempfile.mkdtemp())
    db = connect(tmp_path / 'session.db')
    db.execute("INSERT INTO local_cashiers(id,code,name,pin_hash,synced_at,is_seller) VALUES(1,'SESSION','สมชาย ขายดี','',?,1)", (now(),))
    db.execute("INSERT INTO products(id,sku,name,unit_name,price,updated_at) VALUES(1,'ITEM1','ข้าวหมูแดงพิเศษ','จาน','65.00',?)", (now(),))
    db.execute("INSERT INTO products(id,sku,name,unit_name,price,updated_at) VALUES(2,'ITEM2','เป็ดย่างจานใหญ่','จาน','250.00',?)", (now(),))
    db.commit()

    service = PosService(db)
    shift_id = service.open_shift(terminal_id='POS-01', branch_id=1, cashier_id=1, opening_cash=Decimal('1000.00'))

    # Insert a sample sale and cash movement via service
    order_sample = Order()
    order_sample.add_product(OrderLine(1, 'ข้าวหมูแดงพิเศษ', 'จาน', Decimal('2'), Decimal('65.00')))
    sale_id = service.checkout(
        document_no='INV202611100001',
        branch_id=1,
        terminal_id='POS-01',
        shift_id=shift_id,
        cashier_id=1,
        lines=order_sample.to_cart_lines(),
        payment_method='cash',
        paid_amount=Decimal('130.00'),
    )
    service.record_cash_movement(shift_id=shift_id, movement_type='drop', amount=Decimal('3000.00'), reference_no='BAG-01', reason='นำส่งเงินรอบบ่าย')

    window = run_ui(service, app=APP, data_dir=tmp_path)
    window.shift_id = shift_id
    window.last_sale_id = 1
    window.cashier = {'id': 1, 'name': 'สมชาย ขายดี', 'code': 'C01'}
    window.order.add_product(OrderLine(1, 'ข้าวหมูแดงพิเศษ', 'จาน', Decimal('2'), Decimal('65.00')))
    window.order.add_product(OrderLine(2, 'เป็ดย่างจานใหญ่', 'จาน', Decimal('1'), Decimal('250.00')))
    window.refresh_order()

    return db, service, window, tmp_path


def render_shot(dialog, parent, screen_w, screen_h, output_path):
    parent.resize(screen_w, screen_h)
    parent.show()
    APP.processEvents()

    dialog.show()
    APP.processEvents()

    # Capture dialog
    dialog_pix = dialog.grab()
    dw = dialog_pix.width()
    dh = dialog_pix.height()

    # Create full screen composite
    canvas = QPixmap(screen_w, screen_h)
    canvas.fill(QColor(15, 23, 42))

    painter = QPainter(canvas)
    parent_pix = parent.grab()
    painter.drawPixmap(0, 0, parent_pix)

    # Semi-transparent backdrop overlay
    painter.fillRect(QRect(0, 0, screen_w, screen_h), QColor(15, 23, 42, 140))

    # Draw 90% boundary guidelines
    max_w = int(screen_w * 0.90)
    max_h = int(screen_h * 0.90)
    bx = (screen_w - max_w) // 2
    by = (screen_h - max_h) // 2

    pen = QPen(QColor(56, 189, 248, 180), 2, Qt.DashLine)
    painter.setPen(pen)
    painter.drawRect(bx, by, max_w, max_h)

    # Label the 90% boundary
    painter.setFont(QFont("sans-serif", 11))
    painter.setPen(QColor(56, 189, 248))
    painter.drawText(bx + 8, by + 18, f"90% Limit ({max_w}x{max_h})")

    # Center dialog on screen
    dx = (screen_w - dw) // 2
    dy = (screen_h - dh) // 2
    painter.drawPixmap(dx, dy, dialog_pix)

    # Draw popup size info tag
    is_overflow_w = dw > max_w
    is_overflow_h = dh > max_h
    tag_bg = QColor(220, 38, 38, 220) if (is_overflow_w or is_overflow_h) else QColor(16, 185, 129, 220)
    painter.fillRect(QRect(dx, dy - 26 if dy >= 26 else dy + dh + 4, 260, 24), tag_bg)
    painter.setPen(QColor(255, 255, 255))
    painter.setFont(QFont("sans-serif", 11, QFont.Bold))
    status_text = f"Size: {dw}x{dh} ({'OVERFLOW' if (is_overflow_w or is_overflow_h) else 'FITS'})"
    painter.drawText(dx + 6, (dy - 9 if dy >= 26 else dy + dh + 20), status_text)

    painter.end()

    canvas.save(str(output_path), "PNG")
    dialog.hide()
    APP.processEvents()
    print(f"  [SAVED] {output_path.name} (dialog: {dw}x{dh}, max: {max_w}x{max_h}, status: {'EXCEEDED' if (is_overflow_w or is_overflow_h) else 'OK'})")


def capture_all_popups(prefix="before"):
    db, service, window, tmp_path = create_mock_environment()

    screen_sizes = [(1024, 768), (1366, 768)]

    # 1. Open Shift
    print(f"Capturing open_shift ({prefix})...")
    for sw, sh in screen_sizes:
        dlg = window.dialog_types['opening'](window)
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"01_open_shift_{prefix}_{sw}x{sh}.png")

    # 2. Existing Shift
    print(f"Capturing existing_shift ({prefix})...")
    for sw, sh in screen_sizes:
        shift_row = {'opened_at': '2026-11-10 08:00:00', 'opening_cash': 1000.0}
        dlg = window.dialog_types['existing'](window, shift_row)
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"02_existing_shift_{prefix}_{sw}x{sh}.png")

    # 3. Seller Selection
    print(f"Capturing seller_selection ({prefix})...")
    for sw, sh in screen_sizes:
        dlg = window.dialog_types['seller']()
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"03_seller_selection_{prefix}_{sw}x{sh}.png")

    # 4. Close Shift (Simple)
    print(f"Capturing close_shift ({prefix})...")
    for sw, sh in screen_sizes:
        dlg = window.dialog_types['closing'](window, Decimal('4500.00'))
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"04_close_shift_{prefix}_{sw}x{sh}.png")

    # 5. Close Shift Wizard (FloatCloseDialog)
    print(f"Capturing close_shift_wizard ({prefix})...")
    policy = {'default_float': '1000.00', 'carried_float': '1000.00'}
    class DummyApi:
        pass
    protocol = FloatClose(DummyApi(), policy, 1)
    for step_idx in range(4):
        for sw, sh in screen_sizes:
            dlg = window.dialog_types['float_closing'](window, protocol, policy)
            dlg.navigate(step_idx)
            render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"05_close_wizard_step{step_idx+1}_{prefix}_{sw}x{sh}.png")

    # 6. PIN Dialog
    print(f"Capturing pin_dialog ({prefix})...")
    for sw, sh in screen_sizes:
        dlg = ValueDialog(window, title='กรอก PIN แคชเชียร์', subtitle='ใส่รหัส PIN 4 หลัก', pin=True, auto_pin=True)
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"06_pin_dialog_{prefix}_{sw}x{sh}.png")

    # 7. Admin Auth Dialog
    print(f"Capturing admin_auth ({prefix})...")
    for sw, sh in screen_sizes:
        dlg = AdminAuthDialog(window)
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"07_admin_auth_{prefix}_{sw}x{sh}.png")

    # 8. Payment Dialog
    print(f"Capturing payment_dialog ({prefix})...")
    for sw, sh in screen_sizes:
        dlg = window.dialog_types['payment'](window, window.order)
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"08_payment_{prefix}_{sw}x{sh}.png")

    # 9. Weight Keypad
    print(f"Capturing weight_keypad ({prefix})...")
    for sw, sh in screen_sizes:
        dlg = ValueDialog(window, title='น้ำหนักสินค้า (กก.)', subtitle='วางสินค้าบนเครื่องชั่ง หรือกดตัวเลข', value='1.250')
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"09_weight_keypad_{prefix}_{sw}x{sh}.png")

    # 10. Hold / Recall Bills
    print(f"Capturing hold_recall ({prefix})...")
    choices = [
        ("เครื่องนี้ · โต๊ะ 3 · ยอด 380.00 บาท", ('local', 0)),
        ("เครื่องนี้ · สั่งกลับบ้าน คุณนิด · ยอด 160.00 บาท", ('local', 1)),
        ("ส่วนกลาง · บิล 004 · ยอด 520.00 บาท", ('remote', 0)),
    ]
    for sw, sh in screen_sizes:
        dlg = ChoiceDialog(window, 'ดึงบิลที่พักไว้', choices, searchable=True)
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"10_hold_recall_{prefix}_{sw}x{sh}.png")

    # 11. Bill History List
    print(f"Capturing bill_history_list ({prefix})...")
    hist_choices = [
        ("REC-202611-0001 · 10/11/2026 14:30\nยอด 315.00 บาท · ซิงค์แล้ว", 1),
        ("REC-202611-0002 · 10/11/2026 15:10\nยอด 120.00 บาท · ซิงค์แล้ว · ยกเลิกแล้ว", 2),
        ("[กะก่อน] REC-202611-0000 · 10/11/2026 12:00\nยอด 890.00 บาท · ซิงค์แล้ว", 3),
    ]
    for sw, sh in screen_sizes:
        dlg = ChoiceDialog(window, 'ประวัติบิล · เลือกบิลเพื่อยกเลิก/รับคืน', hist_choices, searchable=True)
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"11_bill_history_list_{prefix}_{sw}x{sh}.png")

    # 12. Bill Detail / Refund
    print(f"Capturing bill_detail ({prefix})...")
    for sw, sh in screen_sizes:
        dlg = TouchDialog(window, "รายละเอียดบิล", "เลือกยกเลิกบิล หรือรับคืนสินค้า")
        from pos_python.mock_printer import receipt_for
        from PySide6.QtWidgets import QLabel, QScrollArea
        body = QLabel(receipt_for(service.db, 1))
        scroll = QScrollArea()
        scroll.setWidget(body)
        scroll.setWidgetResizable(True)
        dlg.body.addWidget(scroll)
        if prefix == "before":
            dlg.resize(560, 700)
        else:
            dlg.setMaximumSize(900, 680)
            dlg.resize(560, 660)
        btn1 = dlg.confirm("ยกเลิกบิล")
        btn2 = dlg.confirm("รับคืนสินค้า")
        if prefix != "before":
            btn1.setMinimumHeight(48)
            btn2.setMinimumHeight(48)
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"12_bill_detail_{prefix}_{sw}x{sh}.png")

    # 13. Last Receipt
    print(f"Capturing last_receipt ({prefix})...")
    for sw, sh in screen_sizes:
        dlg = TouchDialog(window, "ใบเสร็จล่าสุด", "ตรวจสอบและพิมพ์ใบเสร็จ")
        from pos_python.mock_printer import receipt_for
        if prefix == "before":
            body = QLabel(receipt_for(service.db, 1))
            body.setStyleSheet("background:%s;padding:16px;font-family:'Menlo','Courier New';font-size:12px;" % PALETTE["surface"])
            dlg.body.addWidget(body)
            close_btn = QDialogButtonBox(QDialogButtonBox.Close)
            dlg.body.addWidget(close_btn)
        else:
            dlg.setMaximumSize(900, 680)
            dlg.resize(480, 660)
            scroll = QScrollArea()
            scroll.setWidgetResizable(True)
            scroll.setFrameShape(QFrame.NoFrame)
            body = QLabel(receipt_for(service.db, 1))
            body.setStyleSheet("background:%s;padding:16px;font-family:'Menlo','Courier New';font-size:14px;" % PALETTE["surface"])
            scroll.setWidget(body)
            dlg.body.addWidget(scroll)
            close_btn = dlg.confirm("ปิด")
            close_btn.setMinimumHeight(48)
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"13_last_receipt_{prefix}_{sw}x{sh}.png")

    # 14. Cash Movement
    print(f"Capturing cash_movement ({prefix})...")
    for sw, sh in screen_sizes:
        label = "นำส่งเงินระหว่างกะ"
        dlg = TouchDialog(window, label, "จำนวนเงิน · เลขซอง · เหตุผล")
        if prefix == "before":
            dlg.resize(940, 700)
        else:
            dlg.setMaximumSize(920, 680)
            dlg.resize(900, 660)
        from pos_python.touch_dialogs import NumPad
        cols = QHBoxLayout()
        dlg.body.addLayout(cols, 1)
        f_card = QFrame()
        f_card.setObjectName("card")
        form = QVBoxLayout(f_card)
        cols.addWidget(f_card, 1)
        amt = QLineEdit("3000.00")
        ref = QLineEdit("BAG-02")
        rsn = QLineEdit("นำส่งเงินยอดขายรอบบ่าย")
        if prefix != "before":
            ref.setStyleSheet("min-height:48px; font-size:18px;")
            rsn.setStyleSheet("min-height:48px; font-size:18px;")
        for cap, fld in [('จำนวนเงิน', amt), ('เลขซอง', ref), ('เหตุผล', rsn)]:
            form.addWidget(QLabel(cap))
            form.addWidget(fld)
        form.addStretch(1)
        cols.addWidget(NumPad(amt), 1)
        c_btn = dlg.confirm('ยืนยัน')
        if prefix != "before":
            c_btn.setMinimumHeight(48)
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"14_cash_movement_{prefix}_{sw}x{sh}.png")

    # 15. Settings Dialog
    print(f"Capturing settings ({prefix})...")
    SettingsCls = get_settings_dialog_cls(window)
    for sw, sh in screen_sizes:
        dlg = SettingsCls(window, sample_sale_id=1)
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"15_settings_{prefix}_{sw}x{sh}.png")

    # 16. Message Dialogs (Info, Warning, Question)
    print(f"Capturing messages ({prefix})...")
    for sw, sh in screen_sizes:
        # Information
        dlg_info = TouchDialog(window, "ข้อมูลแจ้งเตือนจากระบบ", "ข้อมูลแจ้งเตือนจากระบบ")
        box = QFrame()
        box.setObjectName('dialogNoticeBox')
        box_l = QVBoxLayout(box)
        msg_lbl = QLabel("ทำการซิงค์ข้อมูลสินค้าและโปรโมชั่นกับ ERP สำเร็จแล้ว (พบ 24 รายการ)")
        msg_lbl.setWordWrap(True)
        box_l.addWidget(msg_lbl)
        if prefix != "before":
            scroll_i = QScrollArea()
            scroll_i.setWidgetResizable(True)
            scroll_i.setFrameShape(QFrame.NoFrame)
            scroll_i.setWidget(box)
            dlg_info.body.addWidget(scroll_i)
        else:
            dlg_info.body.addWidget(box)
        btn_i = dlg_info.confirm("ตกลง")
        if prefix != "before":
            btn_i.setMinimumHeight(48)
        render_shot(dlg_info, window, sw, sh, OUTPUT_DIR / f"16a_message_info_{prefix}_{sw}x{sh}.png")

        # Question
        dlg_q = TouchDialog(window, "ยืนยันยกเลิกบิล", "กรุณาตรวจสอบก่อนยืนยันรายการ")
        box2 = QFrame()
        box2.setObjectName('dialogNoticeBox')
        box2.setProperty('tone', 'warning')
        box_l2 = QVBoxLayout(box2)
        q_lbl = QLabel("ต้องการยกเลิกบิล REC-202611-0001 ยอด 315.00 บาท ใช่หรือไม่?\nรายการในสต็อกจะถูกปรับคืนอัตโนมัติ")
        q_lbl.setWordWrap(True)
        box_l2.addWidget(q_lbl)
        if prefix != "before":
            scroll_q = QScrollArea()
            scroll_q.setWidgetResizable(True)
            scroll_q.setFrameShape(QFrame.NoFrame)
            scroll_q.setWidget(box2)
            dlg_q.body.addWidget(scroll_q)
        else:
            dlg_q.body.addWidget(box2)
        btn_q1 = dlg_q.confirm("กลับ")
        btn_q2 = dlg_q.confirm("ยืนยันยกเลิก")
        if prefix != "before":
            btn_q1.setMinimumHeight(48)
            btn_q2.setMinimumHeight(48)
        render_shot(dlg_q, window, sw, sh, OUTPUT_DIR / f"16b_message_question_{prefix}_{sw}x{sh}.png")

    # 17. Line Editor Dialog
    print(f"Capturing line_editor ({prefix})...")
    line = window.order.lines[0]
    window.line_editor_title.setText(f"{line.name}\nเลือกโหมด แล้วกดตัวเลข")
    for sw, sh in screen_sizes:
        dlg = window.line_editor
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"17_line_editor_{prefix}_{sw}x{sh}.png")

    # 18. Daily Sales
    print(f"Capturing daily_sales ({prefix})...")
    for sw, sh in screen_sizes:
        dlg = TouchDialog(window, "ยอดขายวันนี้ · เครื่องนี้", "สรุปจากฐานข้อมูลในเครื่อง")
        t_tot = QTableWidget(4, 2)
        t_tot.setHorizontalHeaderLabels(["รายการ", "ยอด (บาท)"])
        t_tot.verticalHeader().setVisible(False)
        t_tot.horizontalHeader().setSectionResizeMode(QHeaderView.Stretch)
        t_rec = QTableWidget(3, 4)
        t_rec.setHorizontalHeaderLabels(["บิลล่าสุด", "เวลา", "ยอด", "การส่งบิล"])
        t_rec.verticalHeader().setVisible(False)
        t_rec.horizontalHeader().setSectionResizeMode(QHeaderView.Stretch)
        if prefix == "before":
            dlg.resize(560, 560)
            dlg.body.addWidget(t_tot)
            dlg.body.addWidget(t_rec)
        else:
            dlg.setMaximumSize(900, 680)
            dlg.resize(580, 660)
            scroll = QScrollArea()
            scroll.setWidgetResizable(True)
            scroll.setFrameShape(QFrame.NoFrame)
            c_host = QWidget()
            c_lay = QVBoxLayout(c_host)
            c_lay.addWidget(t_tot)
            c_lay.addWidget(t_rec)
            scroll.setWidget(c_host)
            dlg.body.addWidget(scroll)
        act_btn = dlg.confirm("ยกเลิกบิล / รับคืนสินค้า")
        if prefix != "before":
            act_btn.setMinimumHeight(48)
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"18_daily_sales_{prefix}_{sw}x{sh}.png")

    # 19. Z Report Dialog
    print(f"Capturing z_report ({prefix})...")
    for sw, sh in screen_sizes:
        dlg = TouchDialog(window, "ใบปิดกะ", "สรุปกะ 1 · ปิดกะแล้ว")
        scroll = QScrollArea()
        scroll.setWidgetResizable(True)
        content = QLabel("ใบปิดกะประจำวัน\nเปิด: 10/11/2026 08:00\nปิด: 10/11/2026 17:00\nยอดขายรวม 12,450.00 บาท\nเงินทอน 1,000.00 บาท\nเงินนำส่ง 11,450.00 บาท")
        scroll.setWidget(content)
        dlg.body.addWidget(scroll)
        if prefix == "before":
            dlg.resize(880, 700)
        else:
            dlg.setMaximumSize(920, 680)
            dlg.resize(880, 660)
        z_btn = dlg.confirm("พิมพ์ Z Report ซ้ำ")
        if prefix != "before":
            z_btn.setMinimumHeight(48)
        render_shot(dlg, window, sw, sh, OUTPUT_DIR / f"19_z_report_{prefix}_{sw}x{sh}.png")

    print(f"\nAll popups captured successfully for prefix='{prefix}'!")


if __name__ == "__main__":
    prefix = sys.argv[1] if len(sys.argv) > 1 else "before"
    capture_all_popups(prefix)
