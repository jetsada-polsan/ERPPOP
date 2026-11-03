import type { BarcodeType } from './barcode'

export interface DeviceProfile {
  serverUrl: string
  deviceName: string
  terminalCode: string
  branchId: number
  branchName: string
  vatRate: number
  cashierLoginMode?: 'pin' | 'selection'
  company: { name?: string; tax_id?: string; address?: string; phone?: string; logo_url?: string }
  receiptTemplate?: ReceiptTemplate
  hardwareProfile?: HardwareProfile
}

export interface HardwareProfile {
  printer_driver: 'browser' | 'windows' | 'escpos_usb' | 'escpos_network'
  printer_name?: string
  printer_address?: string
  paper_width: '58mm' | '80mm'
  scanner_mode: 'keyboard' | 'serial'
  scale_mode: 'none' | 'keyboard' | 'serial'
  customer_display: 'none' | 'browser' | 'serial' | 'network'
  cash_drawer_enabled: boolean
  auto_print: boolean
  print_copies: number
}

export interface ReceiptBlock {
  id: string
  type: 'logo' | 'company' | 'title' | 'meta' | 'divider' | 'items' | 'tax-summary' | 'totals' | 'payment' | 'custom' | 'footer'
  align: 'left' | 'center' | 'right'
  size: 'small' | 'medium' | 'large'
  bold: boolean
  text?: string
  show_sku?: boolean
  show_unit_price?: boolean
}

export interface ReceiptTemplate {
  paper_width: 58 | 80
  blocks: ReceiptBlock[]
}

export interface OfflineCredential {
  salt: string
  verifier: string
  iterations: number
  expires_at: string
}

export interface Cashier {
  id: number
  code: string
  name: string
  branch_id?: number
  role?: 'manager' | 'cashier'
  offline_credential?: OfflineCredential
}

export interface Member {
  id: number
  member_code: string
  name: string
  phone?: string | null
  points?: number
}
export interface Shift {
  id: number
  shift_no: string
  status: 'open' | 'closed'
  opening_cash: number
  expected_cash: number
  receipt_count: number
}

export interface Barcode {
  barcode: string
  /** ไม่มีค่าได้ ถ้าเครื่องยัง sync ไม่ถึงรุ่นที่ส่งฟิลด์นี้มา — ถือเป็น CUSTOM */
  barcode_type?: BarcodeType
  unit_id?: number
  unit_factor: number
  price?: number
}

export interface Product {
  id: number
  sku_code: string
  name_th: string
  pos_price: number
  base_pos_price?: number
  scheduled_prices?: ScheduledPrice[]
  normal_price?: number
  stock_qty?: number | null
  is_promotion?: boolean
  is_flash_sale?: boolean
  margin_percent?: number
  margin_warning?: boolean
  /** สินค้าชั่ง: เลือกจากการค้นหาแล้วต้องใส่น้ำหนัก */
  is_scale?: boolean
  scale_plu?: string | null
  barcodes: Barcode[]
}

export interface ScheduledPrice {
  id: number
  unit_id?: number | null
  price: number
  effective_from: string
  effective_to?: string | null
}

export interface QtyPromotion {
  id: number
  code: string
  name: string
  promo_type: 'free_item' | 'discount' | 'bundle_price' | 'bill_discount' | 'bill_free_item'
  /** โปรต่อบิลไม่มีสินค้าที่ต้องซื้อ */
  product_id: number | null
  min_qty: number
  min_amount?: number | null
  max_discount?: number | null
  free_product_id?: number | null
  free_qty?: number | null
  discount_type?: 'percent' | 'baht' | null
  discount_value?: number | null
  bundle_price?: number | null
  starts_at?: string | null
  ends_at?: string | null
  free_product?: Pick<Product, 'id' | 'sku_code' | 'name_th'> | null
}

export interface CartLine extends Product {
  qty: number
  scannedBarcode?: string
  /** ส่งขึ้นเซิร์ฟเวอร์ไปด้วย เพื่อให้ตรวจซ้ำได้ว่าตีความบาร์โค้ดตรงกัน */
  scannedBarcodeType?: BarcodeType
}
export interface HeldBill {
  id: number
  hold_no: string
  label: string
  createdAt: string
  cashier_name?: string
  terminal_name?: string
  total_amount: number
  cart: CartLine[]
}
export type PaymentMethod = 'cash' | 'transfer' | 'credit_card' | 'cheque' | 'mixed'

export interface CheckoutPayload {
  branch_id: number
  shift_id: number
  cashier_id: number
  method: PaymentMethod
  payment_ref?: string
  payment_confirmed: boolean
  member_id?: number
  /** บอกเซิร์ฟเวอร์ว่าเครื่องนี้คิดโปรต่อบิลแล้ว */
  bill_promotions?: boolean
  cash_received?: number
  cash_amount?: number
  transfer_amount?: number
  change_amount?: number
  manual_discount_amount?: number
  discount_approved_by?: string
  net_items?: boolean
  vat_mode: 'included'
  items: Array<{ product_id: number; qty: number; unit_price: number; barcode?: string; barcode_type?: BarcodeType }>
}

export interface QueueItem {
  id: string
  payload: CheckoutPayload
  status: 'pending' | 'syncing' | 'synced' | 'failed' | 'needs_review'
  attempts: number
  error?: string
  receiptNo?: string
  createdAt: string
}

export interface LocalSaleHistory {
  id: string
  receiptNo: string
  status: 'pending' | 'syncing' | 'synced' | 'failed' | 'needs_review' | 'void'
  total: number
  method: PaymentMethod
  paid: number
  change: number
  items: CartLine[]
  printedAt: string
  syncedAt?: string
  error?: string
}

export interface LocalStorageStatus {
  location: 'd-drive' | 'c-fallback' | 'app-config'
  database_path: string
  warning?: string
}
