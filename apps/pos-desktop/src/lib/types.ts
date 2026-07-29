export interface DeviceProfile {
  serverUrl: string
  deviceName: string
  terminalCode: string
  branchId: number
  branchName: string
  vatRate: number
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

export interface Cashier { id: number; code: string; name: string; branch_id?: number }
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
  unit_id?: number
  unit_factor: number
  price?: number
}

export interface Product {
  id: number
  sku_code: string
  name_th: string
  pos_price: number
  normal_price?: number
  stock_qty?: number | null
  is_promotion?: boolean
  is_flash_sale?: boolean
  margin_percent?: number
  margin_warning?: boolean
  barcodes: Barcode[]
}

export interface CartLine extends Product { qty: number; scannedBarcode?: string }
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
  cash_received?: number
  cash_amount?: number
  transfer_amount?: number
  change_amount?: number
  vat_mode: 'included'
  items: Array<{ product_id: number; qty: number; unit_price: number; barcode?: string }>
}

export interface QueueItem {
  id: string
  payload: CheckoutPayload
  status: 'pending' | 'syncing' | 'synced' | 'failed'
  attempts: number
  error?: string
  receiptNo?: string
  createdAt: string
}

export interface LocalSaleHistory {
  id: string
  receiptNo: string
  status: 'pending' | 'syncing' | 'synced' | 'failed'
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
