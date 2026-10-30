from ui.theme import apply_pos_theme
import requests
from PySide6.QtWidgets import (
    QDialog, QVBoxLayout, QHBoxLayout, QLabel, QLineEdit,
    QPushButton, QComboBox, QMessageBox, QFrame, QDoubleSpinBox
)
from PySide6.QtCore import Qt, QTimer
from database.models import init_db, Product, ProductBarcode
from services.scale_service import ScaleBarcodeParser
from services.money import line_amount, money

class ScaleWeightDialog(QDialog):
    """
    หน้าต่างสินค้าชั่งน้ำหนัก:
    - รองรับการยิงบาร์โค้ด / สแกน QR สินค้า
    - หรือค้นหา / เลือกจากรายชื่อสินค้า
    - ใส่จำนวนกิโลกรัม (กก.) ทศนิยม 3 ตำแหน่ง
    - คำนวณราคาและส่งเข้าตะกร้าสินค้า
    """
    def __init__(self, parent=None, db_path="pos.db", api_config=None):
        super().__init__(parent)
        self.parent_window = parent
        self.db_path = db_path
        self.api_config = api_config or {}
        self.selected_product = None
        self.entered_weight = 1.000
        self.source_barcode = None
        self.barcode_type = None
        self.price_override = None
        self._selecting_scanned_product = False
        self._scale_label_weight_locked = False

        self.setWindowTitle("สินค้าชั่งน้ำหนัก (สแกนบาร์โค้ด/QR และระบุกิโลกรัม)")
        self.setFixedWidth(520)
        self.setStyleSheet("""
            QDialog { background-color: #f8fafc; font-family: 'Segoe UI', Tahoma, sans-serif; }
            QLabel { color: #1e293b; font-size: 14px; }
            QLineEdit, QComboBox, QDoubleSpinBox {
                background: white; border: 1px solid #cbd5e1; border-radius: 8px;
                padding: 10px; font-size: 15px; color: #0f172a;
            }
            QLineEdit:focus, QComboBox:focus, QDoubleSpinBox:focus {
                border-color: #147da8;
            }
            QComboBox {
                background-color: #ffffff;
                color: #0f172a;
                border: 2px solid #cbd5e1;
            }
            QComboBox QAbstractItemView {
                background-color: #ffffff;
                color: #0f172a;
                selection-background-color: #147da8;
                selection-color: #ffffff;
                border: 2px solid #147da8;
                outline: none;
                font-size: 14px;
                padding: 4px;
            }
            QComboBox QAbstractItemView::item {
                min-height: 40px;
                color: #0f172a;
                padding: 6px 10px;
                border-bottom: 1px solid #f1f5f9;
            }
            QComboBox QAbstractItemView::item:hover {
                background-color: #e3f1f7;
                color: #0b5e83;
            }
            QComboBox QAbstractItemView::item:selected {
                background-color: #147da8;
                color: #ffffff;
                font-weight: bold;
            }
        """)

        self.setup_ui()
        self.load_products()
        apply_pos_theme(self)

    def setup_ui(self):
        layout = QVBoxLayout(self)
        layout.setSpacing(14)
        layout.setContentsMargins(20, 20, 20, 20)

        # Header Box
        header_frame = QFrame()
        header_frame.setStyleSheet("background: qlineargradient(x1:0, y1:0, x2:1, y2:1, stop:0 #147da8, stop:1 #0b5275); border-radius: 12px; padding: 12px;")
        h_layout = QVBoxLayout(header_frame)
        h_layout.setContentsMargins(10, 8, 10, 8)

        title_lbl = QLabel("ขายสินค้าชั่งน้ำหนัก (กิโลกรัม)")
        title_lbl.setStyleSheet("color: white; font-size: 18px; font-weight: bold;")
        sub_lbl = QLabel("ยิงฉลากเครื่องชั่งเพื่อใส่น้ำหนักอัตโนมัติ หรือสแกนสินค้าแล้วระบุกิโลกรัม")
        sub_lbl.setStyleSheet("color: #e3f1f7; font-size: 13px;")
        h_layout.addWidget(title_lbl)
        h_layout.addWidget(sub_lbl)
        layout.addWidget(header_frame)

        # 1. Barcode / QR Scan Field (Default Focused)
        scan_box = QVBoxLayout()
        scan_lbl = QLabel("สแกนบาร์โค้ด / QR สินค้า หรือพิมพ์รหัส:")
        scan_lbl.setStyleSheet("font-weight: bold; color: #0b5e83;")
        self.scan_input = QLineEdit()
        self.scan_input.setPlaceholderText("ยิงบาร์โค้ด / QR หรือ SKU ที่นี่แล้วกด Enter...")
        self.scan_input.setStyleSheet("QLineEdit { border: 2px dashed #147da8; background: #edf7fb; font-weight: bold; } QLineEdit:focus { border: 2px solid #0b5e83; background: white; }")
        self.scan_input.returnPressed.connect(self.on_scan_barcode)
        scan_box.addWidget(scan_lbl)
        scan_box.addWidget(self.scan_input)
        layout.addLayout(scan_box)

        # 2. Product Selector Dropdown
        prod_box = QVBoxLayout()
        prod_lbl = QLabel("หรือเลือกสินค้าจากรายการ:")
        prod_lbl.setStyleSheet("font-weight: bold; color: #334155;")
        self.prod_combo = QComboBox()
        self.prod_combo.setMaxVisibleItems(12)
        self.prod_combo.view().setVerticalScrollBarPolicy(Qt.ScrollBarPolicy.ScrollBarAsNeeded)
        self.prod_combo.currentIndexChanged.connect(self.on_product_combo_changed)
        prod_box.addWidget(prod_lbl)
        prod_box.addWidget(self.prod_combo)
        layout.addLayout(prod_box)

        # Product Info Banner
        self.info_card = QFrame()
        self.info_card.setStyleSheet("background: white; border: 1px solid #d8e5ed; border-radius: 12px; padding: 10px;")
        info_layout = QHBoxLayout(self.info_card)
        self.prod_name_lbl = QLabel("กรุณาเลือกหรือสแกนสินค้า")
        self.prod_name_lbl.setStyleSheet("font-weight: bold; font-size: 15px; color: #0f172a;")
        self.prod_price_lbl = QLabel("฿0.00 / กก.")
        self.prod_price_lbl.setStyleSheet("font-weight: bold; font-size: 16px; color: #0b5e83;")
        info_layout.addWidget(self.prod_name_lbl, 1)
        info_layout.addWidget(self.prod_price_lbl, 0)
        layout.addWidget(self.info_card)

        # 3. Weight Input Field (กิโลกรัม)
        weight_box = QVBoxLayout()
        self.weight_lbl = QLabel("น้ำหนักสินค้า (กิโลกรัม):")
        self.weight_lbl.setStyleSheet("font-weight: bold; font-size: 15px; color: #0b5e83;")

        weight_row = QHBoxLayout()
        self.weight_input = QDoubleSpinBox()
        self.weight_input.setDecimals(8)
        self.weight_input.setRange(0.00000001, 999999.99999999)
        self.weight_input.setSingleStep(0.100)
        self.weight_input.setValue(1.000)
        self.weight_input.setStyleSheet("font-size: 22px; font-weight: bold; color: #0f172a; height: 50px;")
        self.weight_input.valueChanged.connect(self.update_calc_total)

        kg_unit_lbl = QLabel("กก. (kg)")
        kg_unit_lbl.setStyleSheet("font-size: 18px; font-weight: bold; color: #475569;")

        weight_row.addWidget(self.weight_input, 1)
        weight_row.addWidget(kg_unit_lbl)
        weight_box.addWidget(self.weight_lbl)
        weight_box.addLayout(weight_row)
        layout.addLayout(weight_box)

        # Quick Weight Buttons (+100g, +250g, +500g, +1kg)
        quick_row = QHBoxLayout()
        self.quick_weight_buttons = []
        for q_label, q_val in [("0.5 กก.", 0.5), ("1.0 กก.", 1.0), ("1.5 กก.", 1.5), ("2.0 กก.", 2.0)]:
            btn = QPushButton(q_label)
            btn.setStyleSheet("background: #e8f1f6; color: #29465b; border: 1px solid #d5e3eb; border-radius: 8px; padding: 8px; font-weight: bold;")
            btn.clicked.connect(lambda checked=False, v=q_val: self.weight_input.setValue(v))
            quick_row.addWidget(btn)
            self.quick_weight_buttons.append(btn)
        layout.addLayout(quick_row)

        # Calculated Total Price Display
        total_frame = QFrame()
        total_frame.setStyleSheet("background: #eaf3f8; border: 1px solid #cfe2ed; border-radius: 12px; padding: 12px;")
        tot_layout = QHBoxLayout(total_frame)
        tot_label = QLabel("รวมเป็นเงิน:")
        tot_label.setStyleSheet("font-size: 16px; font-weight: bold; color: #29465b;")
        self.calc_total_lbl = QLabel("฿0.00")
        self.calc_total_lbl.setStyleSheet("font-size: 26px; font-weight: bold; color: #0b5275;")
        tot_layout.addWidget(tot_label)
        tot_layout.addStretch()
        tot_layout.addWidget(self.calc_total_lbl)
        layout.addWidget(total_frame)

        # Action Buttons
        btn_row = QHBoxLayout()
        cancel_btn = QPushButton("ยกเลิก")
        cancel_btn.setStyleSheet("background: #ffffff; color: #526578; border: 1px solid #d5e0e8; border-radius: 9px; padding: 12px; font-weight: bold; font-size: 15px;")
        cancel_btn.clicked.connect(self.reject)

        self.submit_btn = QPushButton("เพิ่มลงตะกร้าสินค้า (Enter)")
        self.submit_btn.setStyleSheet("background: #147da8; color: white; border: none; border-radius: 9px; padding: 12px; font-weight: bold; font-size: 16px;")
        self.submit_btn.clicked.connect(self.on_submit)

        btn_row.addWidget(cancel_btn, 1)
        btn_row.addWidget(self.submit_btn, 2)
        layout.addLayout(btn_row)

        # Focus scan input immediately
        QTimer.singleShot(100, self.scan_input.setFocus)

    def load_products(self):
        session = init_db(f"sqlite:///{self.db_path}")
        try:
            products = session.query(Product).filter(Product.is_active == True).all()
            if not products:
                return

            def sort_key(p):
                name = (p.name_th or '').lower()
                is_sc = getattr(p, 'is_scale_item', False) or ('กก' in name) or ('ชั่ง' in name) or ('สด' in name) or ('กิโล' in name)
                return (0 if is_sc else 1, p.name_th or '')

            self.products_list = sorted(products, key=sort_key)
            self.prod_combo.clear()
            for p in self.products_list:
                price = p.pos_price or p.default_price or 0.0
                self.prod_combo.addItem(f"{p.sku_code} - {p.name_th} (฿{price:,.2f}/กก.)", p.id)

            self._selecting_scanned_product = True
            self.prod_combo.setCurrentIndex(-1)
            self._selecting_scanned_product = False
            self.selected_product = None
            self.update_product_info()
        finally:
            session.close()

    def on_product_combo_changed(self, idx):
        if hasattr(self, 'products_list') and 0 <= idx < len(self.products_list):
            self.selected_product = self.products_list[idx]
            if not self._selecting_scanned_product:
                self.source_barcode = None
                self.barcode_type = None
                self.price_override = None
                self._set_scale_label_weight_locked(False)
                self.weight_input.setValue(1.000)
            self.update_product_info()

    def update_product_info(self):
        if not self.selected_product:
            self.prod_name_lbl.setText("ไม่พบสินค้า")
            self.prod_price_lbl.setText("฿0.00 / กก.")
            self.update_calc_total()
            return

        price = self._product_value(self.selected_product, 'pos_price') or self._product_value(self.selected_product, 'default_price') or 0.0
        price = float(self.price_override if self.price_override is not None else price)
        name = self._product_value(self.selected_product, 'name_th') or 'สินค้า'
        self.prod_name_lbl.setText(str(name))
        self.prod_price_lbl.setText(f"฿{price:,.2f} / กก.")
        self.update_calc_total()

    def update_calc_total(self):
        if not self.selected_product:
            self.calc_total_lbl.setText("฿0.00")
            return
        price = self._product_value(self.selected_product, 'pos_price') or self._product_value(self.selected_product, 'default_price') or 0.0
        price = float(self.price_override if self.price_override is not None else price)
        weight = float(self.weight_input.value())
        total = money(line_amount(weight, price))
        self.calc_total_lbl.setText(f"฿{total:,.2f}")

    def on_scan_barcode(self):
        code = self.scan_input.text().strip()
        if not code:
            return

        session = init_db(f"sqlite:///{self.db_path}")
        try:
            api_result = self._scan_with_erp(code)
            if api_result is not None and not isinstance(api_result, dict):
                QMessageBox.warning(self, 'สแกนสินค้าไม่สำเร็จ', 'ERP ส่งรูปแบบผลสแกนไม่ถูกต้อง')
                self._clear_selection()
                self.scan_input.selectAll()
                return
            if api_result and api_result.get('error'):
                self._clear_selection()
                self.scan_input.selectAll()
                return
            if api_result is not None:
                product_data = api_result.get('product')
                if not isinstance(product_data, dict):
                    QMessageBox.warning(self, 'สแกนสินค้าไม่สำเร็จ', 'ERP ส่งผลสแกนที่ไม่มีข้อมูลสินค้า')
                    self._clear_selection()
                    self.scan_input.selectAll()
                    return

                product = session.query(Product).filter_by(id=product_data.get('id')).first()
                # Product rows can be absent from a stale local cache while ERP is online.
                self._select_product(product or product_data)
                self.source_barcode = api_result.get('barcode') or code
                self.barcode_type = api_result.get('barcode_type')
                self.price_override = product_data.get('pos_price')
                if api_result.get('mode') == 'scale':
                    try:
                        qty = float(api_result.get('qty') or 0)
                    except (TypeError, ValueError):
                        qty = 0
                    if qty < 0.00000001 or qty > 999999.99999999:
                        QMessageBox.warning(self, 'น้ำหนักจากฉลากไม่ถูกต้อง', 'น้ำหนักจากฉลากอยู่นอกช่วงที่รองรับ')
                        self._clear_selection()
                        self.scan_input.selectAll()
                        return
                    self.weight_input.setValue(qty)
                    self._set_scale_label_weight_locked(True)
                else:
                    # A plain product code only chooses the item; manual kg entry
                    # must not be mistaken for a package/unit barcode at checkout.
                    self.source_barcode = None
                    self.barcode_type = None
                    self.weight_input.setValue(1.000)
                    self._set_scale_label_weight_locked(False)
                self._finish_scan()
                return

            # Offline: decode scale labels with the same ERP-synced profiles used by
            # the main scan field, then fall back to an exact local product barcode.
            scale_data = ScaleBarcodeParser.parse(code, session)
            if scale_data:
                item_code = scale_data['item_code']
                product = session.query(Product).filter(
                    (Product.sku_code == item_code) | (Product.barcode == item_code)
                ).first()
                if product is None:
                    cached = session.query(ProductBarcode).filter_by(barcode=item_code).first()
                    if cached:
                        product = session.query(Product).filter_by(id=cached.product_id).first()
                if product is None:
                    QMessageBox.warning(self, 'ไม่พบสินค้า', f"ไม่พบสินค้าสำหรับ PLU เครื่องชั่ง: '{item_code}'")
                    self._clear_selection()
                    self.scan_input.selectAll()
                    return
                qty = float(scale_data['weight'])
                if qty < 0.001 or qty > 999999.999:
                    QMessageBox.warning(self, 'น้ำหนักจากฉลากไม่ถูกต้อง', 'น้ำหนักจากฉลากอยู่นอกช่วงที่รองรับ')
                    self._clear_selection()
                    self.scan_input.selectAll()
                    return
                self._select_product(product)
                self.source_barcode = scale_data['raw_barcode']
                self.barcode_type = scale_data['barcode_type']
                self.price_override = scale_data['price'] / qty
                self.weight_input.setValue(qty)
                self._set_scale_label_weight_locked(True)
                self._finish_scan()
                return

            cached_barcode = session.query(ProductBarcode).filter_by(barcode=code).first()
            if cached_barcode:
                product = session.query(Product).filter_by(id=cached_barcode.product_id).first()
            else:
                product = session.query(Product).filter(
                    (Product.barcode == code) |
                    (Product.sku_code == code) |
                    (Product.sku_code.endswith(code))
                ).first()

            if product:
                self._select_product(product)
                self.source_barcode = None
                self.barcode_type = None
                self.price_override = None
                self.weight_input.setValue(1.000)
                self._set_scale_label_weight_locked(False)
                self._finish_scan()
            else:
                QMessageBox.warning(self, "ไม่พบสินค้า", f"ไม่พบสินค้าที่มีรหัสหรือบาร์โค้ด: '{code}'")
                self._clear_selection()
                self.scan_input.selectAll()
        finally:
            session.close()

    @staticmethod
    def _product_value(product, key):
        return product.get(key) if isinstance(product, dict) else getattr(product, key, None)

    def _scan_with_erp(self, code):
        token = self.api_config.get('pos_token')
        branch_id = self.api_config.get('branch_id')
        api_url = str(self.api_config.get('api_url') or '').rstrip('/')
        if not token or not branch_id or not api_url:
            return None

        try:
            response = requests.post(
                f'{api_url}/api/pos/scan',
                headers={'Authorization': f'Bearer {token}', 'Accept': 'application/json'},
                json={'branch_id': branch_id, 'barcode': code, 'source': 'desktop'},
                timeout=4,
            )
        except requests.exceptions.RequestException:
            return None

        if response.status_code == 200:
            try:
                return response.json()
            except ValueError as error:
                QMessageBox.warning(self, 'สแกนสินค้าไม่สำเร็จ', f'อ่านผลจาก ERP ไม่ได้: {error}')
                return {'error': True}
        if response.status_code < 500:
            try:
                message = response.json().get('message') or 'ERP ไม่อนุมัติบาร์โค้ดนี้'
            except (ValueError, AttributeError):
                message = 'ERP ไม่อนุมัติบาร์โค้ดนี้'
            QMessageBox.warning(self, 'สแกนสินค้าไม่สำเร็จ', message)
            return {'error': True}
        return None

    def _select_product(self, product):
        self._selecting_scanned_product = True
        self.selected_product = product
        product_id = self._product_value(product, 'id')
        for i in range(self.prod_combo.count()):
            if self.prod_combo.itemData(i) == product_id:
                self.prod_combo.setCurrentIndex(i)
                break
        self._selecting_scanned_product = False
        self.update_product_info()

    def _clear_selection(self):
        self._selecting_scanned_product = True
        self.prod_combo.setCurrentIndex(-1)
        self._selecting_scanned_product = False
        self.selected_product = None
        self.source_barcode = None
        self.barcode_type = None
        self.price_override = None
        self._set_scale_label_weight_locked(False)
        self.weight_input.setValue(1.000)
        self.update_product_info()

    def _set_scale_label_weight_locked(self, locked):
        self._scale_label_weight_locked = bool(locked)
        self.weight_input.setEnabled(not self._scale_label_weight_locked)
        self.weight_lbl.setText(
            'น้ำหนักตามฉลากเครื่องชั่ง (ยืนยันจาก ERP):' if self._scale_label_weight_locked
            else 'น้ำหนักสินค้า (กิโลกรัม):'
        )
        for button in self.quick_weight_buttons:
            button.setEnabled(not self._scale_label_weight_locked)

    def _finish_scan(self):
        self.scan_input.clear()
        self.weight_input.setFocus()
        self.weight_input.selectAll()

    def on_submit(self):
        if not self.selected_product:
            QMessageBox.warning(self, "แจ้งเตือน", "กรุณาเลือกสินค้าก่อนบันทึก")
            return

        weight = float(self.weight_input.value())
        if weight <= 0:
            QMessageBox.warning(self, "แจ้งเตือน", "กรุณาระบุน้ำหนักที่ถูกต้อง (มากกว่า 0)")
            return

        self.entered_weight = weight
        self.accept()
