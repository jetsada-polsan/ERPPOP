<script setup lang="ts">
import mpmLogo from './assets/mpm-logo.jpg'
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { AlertTriangle, Banknote, CheckCircle2, ChevronRight, CircleHelp, Cloud, CloudOff, CreditCard, FileText, FolderOpen, History, Lock, LogOut, Minus, PackageSearch, PauseCircle, Plus, Printer, QrCode, ReceiptText, RefreshCw, RotateCcw, Scale, ScanLine, Search, Settings, ShoppingCart, Trash2, UserRound, Wifi, X } from 'lucide-vue-next'
import { check } from '@tauri-apps/plugin-updater'
import { invoke, isTauri } from '@tauri-apps/api/core'
import { api, connect, pairDevice, PosUnreachableError, setServerUrl } from './lib/api'
import { backupTo, closeLocalDb, enqueue, loadOfflineCashiers, loadOfflineCashiersByPin, loadProducts, loadProfile, loadPromotions, loadSession, localDbHealth, markQueue, queueItems, replaceOfflineCashiers, replaceProducts, replacePromotions, saleHistory, saveOfflineCredential, saveProfile, saveSaleHistory, saveSession, type LocalDbHealth, markSaleVoided, saveAppState, loadAppState, patchQueuePayload, discardQueuedSale, clearUnsentSales } from './lib/db'
import { productAtSaleTime } from './lib/catalog'
import { resolveScan, type BarcodeType } from './lib/barcode'
import { priceCart } from './lib/pricing'
import { syncCheckoutQueue } from './lib/sync'
import { getCurrentWindow } from '@tauri-apps/api/window'
import type { ZReport, Member, CartLine, Cashier, DeviceProfile, HeldBill, LocalSaleHistory, LocalStorageStatus, PaymentMethod, Product, QtyPromotion, QueueItem, ReceiptBlock, ReceiptTemplate, Shift } from './lib/types'

const DEFAULT_RECEIPT_TEMPLATE: ReceiptTemplate = {
  paper_width: 80,
  blocks: [
    { id: 'logo', type: 'logo', align: 'center', size: 'medium', bold: false },
    { id: 'company', type: 'company', align: 'center', size: 'small', bold: false },
    { id: 'title', type: 'title', align: 'center', size: 'medium', bold: true },
    { id: 'meta', type: 'meta', align: 'left', size: 'small', bold: false },
    { id: 'divider', type: 'divider', align: 'left', size: 'small', bold: false },
    { id: 'items', type: 'items', align: 'left', size: 'small', bold: false, show_sku: false, show_unit_price: true },
    { id: 'tax-summary', type: 'tax-summary', align: 'left', size: 'small', bold: false },
    { id: 'totals', type: 'totals', align: 'left', size: 'large', bold: true },
    { id: 'payment', type: 'payment', align: 'left', size: 'small', bold: false },
    { id: 'footer', type: 'footer', align: 'center', size: 'small', bold: false, text: 'ขอบคุณที่ใช้บริการ' },
  ],
}

const BRANCH_NOT_LINKED = 'เครื่องนี้ยังไม่ได้ผูกสาขาใน ERP — ให้ผู้ดูแลกำหนดสาขาให้บัญชีของเครื่อง แล้วออก Token ใหม่'

const profile = ref<DeviceProfile | null>(null)
const products = ref<Product[]>([])
const promotions = ref<QtyPromotion[]>([])
const cart = ref<CartLine[]>([])
const cashier = ref<Cashier | null>(null)
const shift = ref<Shift | null>(null)
const search = ref('')
const scanner = ref<HTMLInputElement | null>(null)
const online = ref(navigator.onLine)
const syncing = ref(false)
const catalogSyncError = ref('')
const pendingCount = ref(0)
const queueError = ref('')
const error = ref('')
const notice = ref('')
const busy = ref(false)
const modal = ref<'cashier' | 'selectCashier' | 'changePin' | 'shift' | 'closeShift' | 'payment' | 'settings' | 'holdBill' | 'heldBills' | 'history' | 'guide' | 'weight' | 'void' | 'closeApp' | null>(null)
const setupUrl = ref('https://erp.popstarcenter.com')
const setupToken = ref('')
const pairCode = ref('')
const storageFolder = ref('D:\\POPSTAR-POS\\data')
const setupError = ref('')   // ค้างไว้จนกว่าจะลองเชื่อมใหม่ ต่างจาก toast ที่หายใน 6 วิ
const cashierPin = ref('')
const pendingCashier = ref<Cashier | null>(null)
const cashierChoices = ref<Cashier[]>([])
const offlineSelection = ref(false)
const newPin = ref('')
const confirmPin = ref('')
const openingCash = ref(0)
const countedCash = ref<number | ''>('')
const openingNote = ref('')
const carriedFloat = ref<number | null>(null)
const floatKept = ref(0)
const depositReference = ref('')
const closePreview = ref<ZReport | null>(null)
const denominationValues = [1000, 500, 100, 50, 20, 10, 5, 2, 1, 0.50, 0.25]
const denominationCounts = ref<number[]>(denominationValues.map(() => 0))
const zCopies = ['ใบนำส่ง', 'ใบเก็บ']
function totalDenominations() {
  countedCash.value = denominationValues.reduce((sum, value, i) => sum + Math.round(value * 100) * (denominationCounts.value[i] || 0), 0) / 100
}
watch([countedCash, floatKept, depositReference], () => { closePreview.value = null })
watch(countedCash, (value) => {
  if (value !== '' && value >= 0) floatKept.value = Math.min(value, shift.value?.default_float ?? 0)
})
watch(modal, async (value) => {
  if (value !== 'shift' || !profile.value || !cashier.value) return
  carriedFloat.value = null
  openingNote.value = ''
  try {
    const settings = await api.shiftSettings(profile.value.branchId, cashier.value.id)
    // null = no recorded float from the previous shift (first shift on this till): suggest the
    // till's default float and do not ask for a variance reason, since nothing was handed over.
    if (settings.carried_float != null) {
      carriedFloat.value = settings.carried_float
      openingCash.value = settings.carried_float
    } else if (settings.default_float != null) {
      openingCash.value = settings.default_float
    }
  } catch (e) { showError(e) }
})
const paymentMethod = ref<PaymentMethod>('cash')
const cashReceived = ref(0)
const paymentRef = ref('')
// ERP บังคับเลขท้ายบัญชีผู้โอน 4 หลักทุกบิลโอน/จ่ายผสม ใช้จับคู่กับ statement
const transferLast4 = ref('')
const lastReceipt = ref<{ no: string; items: CartLine[]; discount?: number; total: number; method: PaymentMethod; paid: number; change: number; printedAt: string; provisional: boolean } | null>(null)
const heldBills = ref<HeldBill[]>([])
const holdLabel = ref('')
const cashDropAmount = ref(0)
// ยกเลิกบิล / รับคืน ต้องให้หัวหน้าใส่ PIN เซิร์ฟเวอร์ตรวจซ้ำทุกครั้ง
const voidMode = ref<'void' | 'return'>('void')
const voidReason = ref('')
const voidApproverId = ref<number | null>(null)
const voidApproverPin = ref('')
const managers = ref<Cashier[]>([])
const returnQty = ref<Record<string, number>>({})
// สมาชิก: ค้นด้วยเบอร์โทรหรือรหัสตอนรับชำระ (ต้องออนไลน์)
const memberQuery = ref('')
const member = ref<Member | null>(null)
// สินค้าชั่งที่เลือกจากการค้นหา/พิมพ์ SKU ต้องใส่น้ำหนักก่อน (ป้ายตาชั่งมีราคาในตัวแล้ว ไม่ต้องถาม)
const weighProduct = ref<Product | null>(null)
const weighQty = ref<number | null>(null)
const cashDropReference = ref('')
const localHealth = ref<LocalDbHealth | null>(null)
const localHealthBusy = ref(false)
const localBackupBusy = ref(false)
const localRestoreBusy = ref(false)
const saleHistoryRows = ref<LocalSaleHistory[]>([])
const recentSales = ref<LocalSaleHistory[]>([])
const historyDays = ref(90)
const selectedHistory = ref<LocalSaleHistory | null>(null)
const storageStatus = ref<LocalStorageStatus | null>(null)
let syncTimer: number | null = null
const catalogClock = ref(Date.now())
const browserPreview = import.meta.env.VITE_POS_PREVIEW === '1'

const filteredProducts = computed(() => {
  catalogClock.value
  const q = search.value.trim().toLocaleLowerCase('th')
  const live = products.value.map((product) => productAtSaleTime(product, new Date()))
  if (!q) return live.slice(0, 80)
  return live.filter((p) => p.sku_code.toLowerCase().includes(q) || p.name_th.toLocaleLowerCase('th').includes(q) || p.barcodes?.some((b) => b.barcode.includes(q))).slice(0, 80)
})
// D: เป็นตำแหน่งที่แนะนำ แต่เครื่องที่ไม่มี D: หรือสร้าง junction ไม่ได้
// ยังต้องขายออฟไลน์ได้ด้วยฐานข้อมูล app-config ที่ตรวจพร้อมแล้ว
const storageReady = computed(() => !isTauri() || ['d-drive', 'c-fallback', 'app-config'].includes(storageStatus.value?.location || ''))
const connectionLabel = computed(() => {
  if (!profile.value) return 'ยังไม่เชื่อม ERP'
  if (syncing.value) return 'กำลังซิงก์'
  if (!online.value) return 'เชื่อม ERP ไม่ได้'
  // ERP ตอบ ping แล้ว แต่ดึง/บันทึกข้อมูลไม่ครบ — คนละปัญหากับเน็ตหลุด
  return catalogSyncError.value ? 'ซิงก์ข้อมูลไม่ครบ' : 'ออนไลน์'
})
const connectionClass = computed(() => profile.value && online.value ? 'online' : 'offline')
const billDiscount = ref(0)
const pricing = computed(() => priceCart(cart.value, promotions.value, profile.value?.vatRate || 7, new Date(), billDiscount.value))
// เปิดโปรแกรมแล้วขึ้นช่อง PIN ทันที และครบ 4 หลักเข้าใช้งานเลย
const stopAskingPin = watch(profile, (value) => {
  if (value && !cashier.value && !modal.value) modal.value = 'cashier'
  if (value) stopAskingPin()
})
// PIN มี 4–20 หลัก (PIN ชั่วคราวจาก ERP มี 6 หลัก) ห้ามส่งทันทีที่ครบ 4 หลัก
// ส่งเองเมื่อหยุดพิมพ์ 0.9 วินาทีหลังครบ 4 หลักขึ้นไป หรือกดปุ่มเข้าใช้งาน
let pinTimer: number | undefined
watch(cashierPin, (value) => {
  window.clearTimeout(pinTimer)
  if (modal.value !== 'cashier' || busy.value || !/^\d{4,20}$/.test(value)) return
  pinTimer = window.setTimeout(() => {
    if (modal.value === 'cashier' && !busy.value && cashierPin.value === value) loginCashier()
  }, 900)
})
const subtotal = computed(() => pricing.value.total)
const totalQty = computed(() => cart.value.reduce((sum, line) => sum + Number(line.qty), 0))
const vat = computed(() => pricing.value.vat)
const change = computed(() => Math.max(0, cashReceived.value - subtotal.value))
const todayLocalSales = computed(() => {
  const today = new Date().toLocaleDateString('en-CA')
  return recentSales.value.filter((sale) => sale.status !== 'failed' && sale.status !== 'void' && new Date(sale.printedAt).toLocaleDateString('en-CA') === today)
})
const todayLocalTotal = computed(() => todayLocalSales.value.reduce((sum, sale) => sum + sale.total, 0))
const todayLocalAverage = computed(() => todayLocalSales.value.length ? todayLocalTotal.value / todayLocalSales.value.length : 0)
const quickProducts = computed(() => {
  const sold = new Map<number, number>()
  for (const sale of recentSales.value) {
    if (sale.status === 'failed') continue
    for (const item of sale.items) sold.set(item.id, (sold.get(item.id) || 0) + Number(item.qty || 0))
  }
  return [...sold.entries()]
    .sort((a, b) => b[1] - a[1])
    .map(([id]) => products.value.find((product) => product.id === id))
    .filter((product): product is Product => Boolean(product))
    .slice(0, 8)
    .map((product) => productAtSaleTime(product, new Date()))
})
const receiptTemplate = computed(() => profile.value?.receiptTemplate?.blocks?.length ? profile.value.receiptTemplate : DEFAULT_RECEIPT_TEMPLATE)
const receiptVat = computed(() => {
  const rate = profile.value?.vatRate || 7
  return (lastReceipt.value?.total || 0) * rate / (100 + rate)
})

function optionalMoney(value?: number | null) { return value == null ? '-' : money(value) }
function money(value: number) { return new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value) }
// ใบที่กำลังพิมพ์ (ต้นฉบับ/สำเนา) แสดงบนหัวใบเสร็จ
const printCopyLabel = ref('')
/** จำนวนใบตามวิธีจ่าย: เงินสด/บัตร 2 ใบ (ลูกค้า+ร้าน), โอน 3 ใบ (+สำเนาตรวจยอดโอน) */
function receiptCopies(method: PaymentMethod): string[] {
  const base = Math.max(1, Number(profile.value?.hardwareProfile?.print_copies || 2))
  const labels = ['ต้นฉบับ (ลูกค้า)', 'สำเนา (ร้าน)'].slice(0, base)
  while (labels.length < base) labels.push(`สำเนา ${labels.length}`)
  if (method === 'transfer' || method === 'mixed') labels.push('สำเนาตรวจยอดโอน (บัญชี)')
  return labels
}
/**
 * สั่งพิมพ์แล้วรอจนเบราว์เซอร์แจ้งว่าพิมพ์เสร็จ (afterprint) ค่อยทำต่อ
 * ห้ามถอดเนื้อหาออกก่อน: เครื่องพิมพ์ที่ช้าหรือ Print to PDF ที่ถามชื่อไฟล์จะพิมพ์ไม่ออก (Print failed)
 */
function printAndWait(): Promise<void> {
  return new Promise((resolve) => {
    let done = false
    const finish = () => { if (done) return; done = true; window.removeEventListener('afterprint', finish); window.setTimeout(resolve, 200) }
    window.addEventListener('afterprint', finish)
    window.setTimeout(finish, 120000)
    window.print()
  })
}

async function printLastReceipt(labels: string[] = ['สำเนา (พิมพ์ซ้ำ)']) {
  // เครื่องติดตั้งเปิด --kiosk-printing ไว้ print() จะส่งเข้าเครื่องพิมพ์หลักทันทีไม่ถามซ้ำ
  for (const label of labels) {
    printCopyLabel.value = label
    await nextTick()
    await printAndWait()
  }
  printCopyLabel.value = ''
}
function flash(message: string) { notice.value = message; window.setTimeout(() => { notice.value = '' }, 3500) }
function errorText(value: unknown) { return value instanceof Error ? value.message : String(value) }
function showError(value: unknown) { error.value = errorText(value); window.setTimeout(() => { error.value = '' }, 6000) }
function receiptBlockClasses(block: ReceiptBlock) { return [`align-${block.align}`, `size-${block.size}`, { 'is-bold': block.bold }] }
function paymentName(method: PaymentMethod) { return ({ cash: 'เงินสด', transfer: 'โอน/QR', credit_card: 'บัตรเครดิต', cheque: 'เช็ค', mixed: 'เงินสด+โอน' })[method] }
function historyStatus(status: LocalSaleHistory['status']) { return ({ pending: 'รอส่ง', syncing: 'กำลังส่ง', synced: 'ส่งแล้ว', failed: 'ส่งไม่สำเร็จ', needs_review: 'รอตรวจสอบ', void: 'ยกเลิกแล้ว' })[status] }
function historyReceipt(sale: LocalSaleHistory) {
  lastReceipt.value = { no: sale.receiptNo, items: sale.items, total: sale.total, method: sale.method, paid: sale.paid, change: sale.change, printedAt: new Date(sale.printedAt).toLocaleString('th-TH'), provisional: sale.status !== 'synced' }
  selectedHistory.value = sale
  discardOpen.value = false
}
async function openHistory() {
  try {
    saleHistoryRows.value = await saleHistory(historyDays.value)
    selectedHistory.value = null
    modal.value = 'history'
  } catch (e) { showError(e) }
}
async function refreshHistory() { saleHistoryRows.value = await saleHistory(historyDays.value) }
async function refreshLocalSales() { recentSales.value = await saleHistory(30) }

function addProduct(product: Product, barcode?: string, barcodeType?: BarcodeType) {
  if (product.is_scale && (!barcode || barcode === product.sku_code || barcode === product.scale_plu)) {
    weighProduct.value = productAtSaleTime(product, new Date(), barcode)
    weighQty.value = null
    search.value = ''
    modal.value = 'weight'
    return
  }
  const existing = cart.value.find((line) => line.id === product.id && line.scannedBarcode === barcode)
  if (existing) existing.qty = Number((existing.qty + 1).toFixed(4))
  else cart.value.push({ ...product, qty: 1, scannedBarcode: barcode, scannedBarcodeType: barcodeType })
  search.value = ''
  nextTick(() => scanner.value?.focus())
}

function addWeighed() {
  const product = weighProduct.value
  const qty = Number(weighQty.value)
  if (!product || !(qty > 0)) return flash('ใส่น้ำหนักมากกว่า 0')
  if (!(Number(product.pos_price) > 0)) return flash(`สินค้าชั่ง ${product.sku_code} ยังไม่ได้ตั้งราคาต่อหน่วย`)
  // ชั่งหนึ่งครั้ง = หนึ่งบรรทัด ไม่รวมกับถุงก่อนหน้า เหมือนยิงป้ายตาชั่ง
  cart.value.push({ ...product, qty: Number(qty.toFixed(4)) })
  modal.value = null
  weighProduct.value = null
  nextTick(() => scanner.value?.focus())
}

async function openVoid(mode: 'void' | 'return') {
  if (!shift.value) return flash('ต้องเปิดกะก่อนถึงจะยกเลิกหรือคืนสินค้าได้')
  if (!selectedHistory.value || selectedHistory.value.status !== 'synced') return flash('บิลนี้ยังไม่ส่งเข้า ERP ยกเลิกหรือคืนไม่ได้')
  if (!navigator.onLine) return flash('ต้องออนไลน์ก่อนถึงจะยกเลิกหรือคืนสินค้าได้')
  try { managers.value = (await api.cashiers()).filter((row) => row.role === 'manager') } catch (e) { return showError(e) }
  if (!managers.value.length) return flash('สาขานี้ยังไม่มีหัวหน้าที่อนุมัติได้')
  voidMode.value = mode
  voidReason.value = ''
  voidApproverId.value = managers.value.length === 1 ? managers.value[0].id : null
  voidApproverPin.value = ''
  returnQty.value = {}
  modal.value = 'void'
}

async function submitVoid() {
  const sale = selectedHistory.value
  if (!sale) return
  if (!shift.value) return flash('ต้องเปิดกะก่อนถึงจะยกเลิกหรือคืนสินค้าได้')
  if (!voidApproverId.value) return flash('เลือกหัวหน้าที่อนุมัติก่อน')
  const approval = { receipt_no: sale.receiptNo, reason: voidReason.value.trim(), approver_id: voidApproverId.value, approver_pin: voidApproverPin.value, terminal_code: profile.value?.terminalCode || undefined }
  busy.value = true
  try {
    if (voidMode.value === 'void') {
      const result = await api.voidReceipt({ ...approval, shift_id: shift.value.id })
      flash(result.message || `ยกเลิกบิล ${sale.receiptNo} แล้ว`)
      await markSaleVoided(sale.id)
      sale.status = 'void'
      await refreshLocalSales()
    } else {
      const items = sale.items
        .map((line, index) => ({ product_id: line.id, qty: Number(returnQty.value[index] || 0) }))
        .filter((line) => line.qty > 0)
      if (!items.length) { flash('ใส่จำนวนที่คืนอย่างน้อย 1 รายการ'); return }
      const result = await api.returnReceipt({ ...approval, shift_id: shift.value.id, refund_method: 'cash', items })
      flash(`${result.message} (${result.return_no})`)
    }
    modal.value = null
  } catch (e) { showError(e) } finally {
    voidApproverPin.value = ''
    busy.value = false
  }
}

const fixLast4 = ref('')
/** บิลโอนที่ส่งไม่ผ่านเพราะไม่มีเลขท้ายบัญชี (เครื่องรุ่นก่อน 1.7.2) ใส่เลขแล้วส่งใหม่ได้ */
async function resendWithLast4() {
  const sale = selectedHistory.value
  if (!sale || !/^\d{4}$/.test(fixLast4.value)) return flash('ใส่เลขท้ายบัญชี 4 หลัก')
  busy.value = true
  try {
    await patchQueuePayload(sale.id, { transfer_account_last4: fixLast4.value })
    fixLast4.value = ''
    await syncCheckoutQueue(refreshQueue)
    await refreshHistory()
    selectedHistory.value = saleHistoryRows.value.find((row) => row.id === sale.id) || null
    flash(selectedHistory.value?.status === 'synced' ? `ส่งบิล ${selectedHistory.value.receiptNo} เข้า ERP แล้ว` : 'ยังส่งไม่ผ่าน ดูข้อความที่แถบล่าง')
  } catch (e) { showError(e) } finally { busy.value = false }
}

const discardOpen = ref(false)
const discardApproverId = ref<number | null>(null)
const discardPin = ref('')
async function openDiscard() {
  try { managers.value = (await api.cashiers()).filter((row) => row.role === 'manager') } catch (e) { return showError(e) }
  discardApproverId.value = managers.value.length === 1 ? managers.value[0].id : null
  discardPin.value = ''
  discardOpen.value = true
}
/** บิลค้างที่ ERP ไม่รับแล้ว: หัวหน้าใส่ PIN อนุมัติ แล้วเอาออกจากคิว (บันทึกเหตุผลไว้ในเครื่อง) */
async function discardStuckSale() {
  const sale = selectedHistory.value
  if (!sale || !discardApproverId.value) return
  busy.value = true
  try {
    const result = await api.verifyApprover({ cashier_id: discardApproverId.value, pin: discardPin.value, reason: `ยกเลิกบิลค้าง ${sale.receiptNo}: ${sale.error || ''}`.slice(0, 200) })
    await discardQueuedSale(sale.id, `${result.approver.name} อนุมัติ · ${sale.error || ''}`)
    discardOpen.value = false
    await refreshQueue()
    await refreshHistory()
    selectedHistory.value = saleHistoryRows.value.find((row) => row.id === sale.id) || null
    flash(`เอาบิล ${sale.receiptNo} ออกจากคิวแล้ว`)
  } catch (e) { showError(e) } finally { discardPin.value = ''; busy.value = false }
}

/** ล้างบิลค้างทั้งหมดในเครื่อง: สำรอง SQLite ก่อนเสมอ แล้วถามยืนยันด้วยการพิมพ์คำว่า ล้าง */
async function clearStuckSales() {
  if (!pendingCount.value) return flash('ไม่มีบิลค้างในเครื่อง')
  const answer = window.prompt(`บิลที่ยังไม่ได้ส่ง ERP ${pendingCount.value} บิลจะถูกล้างออกจากเครื่อง และจะไม่ถูกนับในยอดขาย\nพิมพ์คำว่า ล้าง เพื่อยืนยัน`)
  if (answer?.trim() !== 'ล้าง') return flash('ยกเลิกแล้ว ไม่ได้ล้างบิล')
  try {
    // สำรองไม่สำเร็จ = ไม่ล้าง ข้อมูลขายต้องย้อนกลับได้เสมอ
    if (!(await backupLocalDb())) return
    const cleared = await clearUnsentSales()
    await refreshQueue(); await refreshLocalSales()
    flash(`ล้างบิลค้าง ${cleared} บิลแล้ว (สำรองข้อมูลไว้ก่อนล้าง) เริ่มขายใหม่ได้`)
  } catch (e) { showError(e) }
}

async function findMember() {
  const q = memberQuery.value.trim()
  if (!q) { member.value = null; return }
  try {
    const rows = await api.searchMembers(q)
    member.value = rows[0] || null
    if (!member.value) flash(`ไม่พบสมาชิก ${q}`)
  } catch (e) { showError(e) }
}

async function persistCart() { await saveAppState('cart', JSON.stringify({ cart: cart.value, billDiscount: billDiscount.value })) }
watch([cart, billDiscount], () => { void persistCart().catch(() => undefined) }, { deep: true })

function repriceCartForCurrentSchedule() {
  const now = new Date()
  cart.value = cart.value.map((line) => {
    const master = products.value.find((product) => product.id === line.id)
    return master
      ? { ...line, ...productAtSaleTime(master, now, line.scannedBarcode), qty: line.qty, scannedBarcode: line.scannedBarcode, scannedBarcodeType: line.scannedBarcodeType }
      : line
  })
}


function scan() {
  const code = search.value.trim()
  if (!code) return

  // การตีความอยู่ใน lib/barcode.ts เพื่อให้เทสต์ได้โดยไม่ต้องยกทั้งหน้าจอขึ้นมา
  const resolved = resolveScan(code, products.value)

  if (resolved.kind === 'not-found') {
    if (filteredProducts.value.length === 1) return addProduct(filteredProducts.value[0])
    return flash(filteredProducts.value.length ? `พบ ${filteredProducts.value.length} รายการ กดเลือกสินค้า` : `ไม่พบรหัส ${code}`)
  }
  if (resolved.kind === 'scale-unknown') return flash(`ไม่พบสินค้าชั่งรหัส ${resolved.plu}`)

  if (resolved.kind === 'exact') {
    if (resolved.warning) flash(resolved.warning)
    const priced = productAtSaleTime(resolved.product, new Date(), resolved.barcode)
    addProduct(priced, resolved.barcode, resolved.barcodeType)
    return
  }

  const priced = productAtSaleTime(resolved.product, new Date(), resolved.plu)
  const perUnit = Number(priced.pos_price)
  if (!(perUnit > 0)) return flash(`สินค้าชั่ง ${resolved.plu} ยังไม่ได้ตั้งราคาต่อหน่วย`)

  // ป้ายหนึ่งใบ = ถุงจริงหนึ่งถุง จึงแยกบรรทัดเสมอ ไม่รวมยอดกับถุงก่อนหน้า
  // (server จะถอดบาร์โค้ดและคำนวณน้ำหนักซ้ำอีกครั้งจากราคาต่อหน่วยของตัวเอง)
  cart.value.push({
    ...priced,
    qty: Number((resolved.totalPrice / perUnit).toFixed(4)),
    scannedBarcode: resolved.barcode,
    scannedBarcodeType: resolved.barcodeType,
  })
  search.value = ''
  nextTick(() => scanner.value?.focus())
}

async function refreshQueue() {
  const waiting = (await queueItems()).filter((item) => item.status !== 'synced')
  pendingCount.value = waiting.length
  queueError.value = waiting.find((item) => item.error)?.error?.slice(0, 160) || ''
  // ERP ไม่รับบิลเพราะยืนยันตัวแคชเชียร์ไม่ตรง/หมดอายุ: ให้ใส่ PIN ใหม่ทันที ไม่ปล่อยบิลค้างทั้งกะ
  // บิลในคิวกับสินค้าในตะกร้ายังอยู่ ใส่ PIN แล้วระบบส่งซ้ำเอง
  if (cashier.value && !modal.value && /รหัสพนักงานขาย|ยืนยัน PIN|ยืนยันตัวตน/.test(queueError.value)) {
    cashierPin.value = ''
    modal.value = 'cashier'
    flash('ERP ขอให้ยืนยัน PIN อีกครั้ง บิลที่ค้างจะส่งให้หลังเข้าระบบ')
  }
}

async function syncAll() {
  if (!profile.value || !navigator.onLine || syncing.value) return
  syncing.value = true
  try {
    // ขั้นที่ 1 — ping คือตัวชี้ขาดว่า "เชื่อม ERP ได้ไหม" อย่างเดียว
    let serverProfile: any
    try {
      serverProfile = await api.ping()
      online.value = true
    } catch (e) {
      online.value = false
      catalogSyncError.value = errorText(e)
      showError(e)
      return
    }

    profile.value = {
      ...profile.value,
      deviceName: serverProfile.device?.name || profile.value.deviceName,
      terminalCode: serverProfile.device?.terminal_code || profile.value.terminalCode,
      branchId: serverProfile.branch_id || profile.value.branchId,
      branchName: serverProfile.branch_name || profile.value.branchName,
      vatRate: Number(serverProfile.vat_rate || profile.value.vatRate),
      cashierLoginMode: serverProfile.cashier_login_mode === 'selection' ? 'selection' : 'pin',
      company: serverProfile.company || profile.value.company,
      receiptTemplate: serverProfile.receipt_template || profile.value.receiptTemplate,
      hardwareProfile: serverProfile.hardware_profile || profile.value.hardwareProfile,
    }
    await saveProfile(profile.value)
    if (!profile.value.branchId) {
      // เปิดกะ/ดึงบิลพักต้องใช้ branch_id ถ้าไม่มีจะพังทีหลังแบบงง ๆ จึงบอกตั้งแต่ตอนนี้
      catalogSyncError.value = BRANCH_NOT_LINKED
      showError(BRANCH_NOT_LINKED)
      return
    }

    // ขั้นที่ 2 — ดึงและบันทึกข้อมูลลงเครื่อง ล้มที่นี่ไม่ใช่ "เชื่อม ERP ไม่ได้"
    try {
      const [fresh, freshPromotions, freshCashiers] = await Promise.all([api.products(profile.value.branchId), api.promotions(profile.value.branchId), api.cashiers()])
      products.value = fresh
      promotions.value = freshPromotions
      await replaceProducts(fresh)
      await replacePromotions(freshPromotions)
      await replaceOfflineCashiers(freshCashiers)
      await reconcileShiftWithErp()
      await syncCheckoutQueue(refreshQueue)
      await refreshQueue()
      catalogSyncError.value = ''
    } catch (e) {
      if (e instanceof PosUnreachableError) online.value = false
      catalogSyncError.value = errorText(e)
      showError(`ซิงก์ข้อมูลไม่สำเร็จ: ${errorText(e)}`)
    }
  } finally { syncing.value = false }
}

async function openSettings() {
  modal.value = 'settings'
  await inspectLocalDb()
}

function openGuide() { modal.value = 'guide' }

async function inspectLocalDb() {
  if (!isTauri()) {
    localHealth.value = null
    return
  }
  localHealthBusy.value = true
  try {
    localHealth.value = await localDbHealth()
  } catch (e) {
    showError(`ตรวจ SQLite ไม่สำเร็จ: ${e instanceof Error ? e.message : String(e)}`)
  } finally {
    localHealthBusy.value = false
  }
}

async function backupLocalDb(): Promise<boolean> {
  if (!isTauri()) { showError('ฟังก์ชัน Backup POS ใช้ได้ในโปรแกรม Windows เท่านั้น'); return false }
  localBackupBusy.value = true
  try {
    // ไม่ต้องปิดฐานก่อน VACUUM INTO อ่านสถานะที่สอดคล้องกันได้ทั้งที่ยังเปิดอยู่
    const path = await invoke<string>('next_backup_path')
    await backupTo(path)
    flash(`สำรองข้อมูล POS แล้ว: ${path}`)
    await inspectLocalDb()
    return true
  } catch (e) {
    showError(`สำรองข้อมูลไม่สำเร็จ: ${e instanceof Error ? e.message : String(e)}`)
    return false
  } finally {
    localBackupBusy.value = false
  }
}

async function restoreLocalDb() {
  if (!isTauri()) return showError('ฟังก์ชัน Restore POS ใช้ได้ในโปรแกรม Windows เท่านั้น')
  if ((localHealth.value?.pending || 0) > 0 || (localHealth.value?.failed || 0) > 0) {
    return showError('ยังมีบิลรอส่งหรือส่งไม่สำเร็จ ห้าม Restore จนกว่าจะตรวจสอบคิวให้เรียบร้อย')
  }
  if (!window.confirm('ระบบจะสำรองฐานปัจจุบันเป็น pre-restore แล้วกู้คืน Backup ล่าสุด จากนั้นจะเปิด POS ใหม่ ยืนยันหรือไม่?')) return
  localRestoreBusy.value = true
  try {
    await closeLocalDb()
    const latest = await invoke<string>('restore_latest_database')
    flash(`กู้คืนจาก Backup แล้ว: ${latest}`)
    window.setTimeout(() => window.location.reload(), 700)
  } catch (e) {
    showError(`กู้คืนข้อมูลไม่สำเร็จ: ${e instanceof Error ? e.message : String(e)}`)
  } finally {
    localRestoreBusy.value = false
  }
}

async function configure() {
  busy.value = true
  setupError.value = ''
  try {
    if (isTauri()) await invoke('set_storage_folder', { folder: storageFolder.value.trim() })
    const paired = pairCode.value.trim() ? await pairDevice(setupUrl.value, pairCode.value.trim()) : null
    const token = paired?.token || setupToken.value.trim()
    if (!token) throw new Error('กรุณาใส่รหัสประจำเครื่องจากหน้า จัดการเครื่อง POS')
    const previousTerminal = profile.value?.terminalCode
    if (paired && pendingCount.value && previousTerminal && previousTerminal !== paired.terminalCode) {
      throw new Error(`มีบิลค้างของเครื่อง ${previousTerminal} ส่งบิลให้ครบก่อนเปลี่ยนเครื่อง`)
    }
    const connected = await connect(setupUrl.value, token)
    setupUrl.value = connected.serverUrl
    profile.value = connected
    await saveProfile(connected)
    if (!connected.branchId) {
      // ยังอยู่หน้าตั้งค่า จะได้เห็นข้อความและเรียกผู้ดูแลได้ทันที ไม่หลุดไปพังตอนเปิดกะ
      catalogSyncError.value = BRANCH_NOT_LINKED
      setupError.value = BRANCH_NOT_LINKED
      showError(BRANCH_NOT_LINKED)
      return
    }
    modal.value = 'cashier'
    await syncAll()
  } catch (e) { setupError.value = errorText(e); showError(e) } finally { busy.value = false }
}

async function loginCashier() {
  busy.value = true
  try {
    if (profile.value?.cashierLoginMode === 'selection') {
      cashierChoices.value = navigator.onLine
        ? await api.cashiers()
        : await loadOfflineCashiers(profile.value.branchId)
      if (!cashierChoices.value.length) throw new Error('ไม่พบรายชื่อพนักงานในเครื่องนี้ กรุณาเชื่อมต่ออินเทอร์เน็ตเพื่อซิงก์ข้อมูล')
      offlineSelection.value = !navigator.onLine
      modal.value = 'selectCashier'
      return
    }
    if (!navigator.onLine) {
      const matches = await loadOfflineCashiersByPin(cashierPin.value, profile.value?.branchId)
      if (!matches.length) {
        throw new Error('ออฟไลน์: ไม่พบแคชเชียร์หรือ PIN หมดอายุ ต้องเชื่อมต่ออินเทอร์เน็ตเพื่อล็อกอินใหม่')
      }
      if (matches.length > 1) {
        cashierChoices.value = matches
        offlineSelection.value = true
        modal.value = 'selectCashier'
        return
      }
      await startCashierSession(matches[0])
      flash('เข้าแคชเชียร์แบบออฟไลน์แล้ว')
      return
    }

    const result = await api.cashierLogin(cashierPin.value)
    if (result.selection_required) {
      cashierChoices.value = result.cashiers || []
      offlineSelection.value = false
      modal.value = 'selectCashier'
      return
    }
    if (!result.cashier) throw new Error('ไม่พบข้อมูลแคชเชียร์')
    const authenticatedCashier: Cashier = { ...result.cashier, offline_credential: result.offline_credential }
    if (!result.must_change_pin) await saveOfflineCredential(authenticatedCashier)
    // PIN ที่แอดมินตั้งให้ยังไม่ถือว่าเป็นความลับ ต้องเปลี่ยนก่อนจึงจะเริ่มขายได้
    if (result.must_change_pin) {
      pendingCashier.value = authenticatedCashier
      modal.value = 'changePin'
      return
    }
    await startCashierSession(authenticatedCashier)
  } catch (e) {
    // PIN ผิด: ล้างช่องให้ใส่ใหม่ได้ทันที ไม่ต้องกดลบทีละหลัก
    cashierPin.value = ''
    showError(e)
  } finally { busy.value = false }
}

async function selectCashier(next: Cashier) {
  busy.value = true
  try {
    if (offlineSelection.value) {
      await startCashierSession(next)
      flash('เข้าแคชเชียร์แบบออฟไลน์แล้ว')
      return
    }
    const result = await api.cashierLogin(profile.value?.cashierLoginMode === 'selection' ? '' : cashierPin.value, next.id)
    if (!result.cashier) throw new Error('ไม่พบข้อมูลแคชเชียร์')
    const authenticatedCashier: Cashier = { ...result.cashier, offline_credential: result.offline_credential }
    if (result.must_change_pin) {
      pendingCashier.value = authenticatedCashier
      modal.value = 'changePin'
      return
    }
    await saveOfflineCredential(authenticatedCashier)
    await startCashierSession(authenticatedCashier)
  } catch (e) { showError(e) } finally { busy.value = false }
}

async function changePin() {
  if (newPin.value !== confirmPin.value) { showError('PIN ใหม่ทั้งสองช่องไม่ตรงกัน'); return }
  if (newPin.value === cashierPin.value) { showError('PIN ใหม่ต้องไม่ซ้ำกับ PIN เดิม'); return }
  busy.value = true
  try {
    const code = pendingCashier.value?.code
    if (!code) throw new Error('ไม่พบข้อมูลแคชเชียร์ กรุณาล็อกอินใหม่')
    await api.changeCashierPin(code, cashierPin.value, newPin.value)
    const result = await api.cashierLogin(newPin.value)
    if (!result.cashier) throw new Error('ไม่พบข้อมูลแคชเชียร์')
    const authenticatedCashier: Cashier = { ...result.cashier, offline_credential: result.offline_credential }
    await saveOfflineCredential(authenticatedCashier)
    newPin.value = ''
    confirmPin.value = ''
    pendingCashier.value = null
    await startCashierSession(authenticatedCashier)
    flash('เปลี่ยน PIN เรียบร้อย')
  } catch (e) { showError(e) } finally { busy.value = false }
}

// ออกจากระบบแค่เปลี่ยนคนขาย กะยังอยู่ที่ ERP คนเดิมใส่ PIN แล้วขายต่อได้
// ล็อกอยู่ = ต้องใส่ PIN ก่อนเท่านั้น ปิดหน้าต่างอื่น (ตั้งค่า/ปิดโปรแกรม) แล้วต้องกลับมาหน้า PIN
const screenLocked = ref(false)
watch(modal, (value) => { if (screenLocked.value && value === null) modal.value = 'cashier' })
let modalBeforeClose: typeof modal.value = null
function cancelCloseApp() { modal.value = modalBeforeClose; modalBeforeClose = null }

/** ล็อกหน้าจอ: พักหรือเปลี่ยนคน กะยังเปิด ตะกร้ายังอยู่ ใส่ PIN แล้วขายต่อ */
function lockScreen() {
  screenLocked.value = true
  cashierPin.value = ''
  modal.value = 'cashier'
}

async function confirmCloseApp() {
  // ตะกร้าเก็บในเครื่องอยู่แล้ว ปิดแล้วเปิดใหม่ของกลับมา บิลที่ชำระแล้วอยู่ในคิวส่ง ERP
  await persistCart().catch(() => undefined)
  if (isTauri()) await getCurrentWindow().destroy()
}

async function logoutCashier() {
  if (cart.value.length && !window.confirm('ยังมีสินค้าในบิล ออกจากระบบแล้วบิลนี้จะหาย ยืนยันไหม')) return
  cart.value = []
  billDiscount.value = 0
  cashier.value = null
  shift.value = null
  await saveSession(null, null)
  cashierPin.value = ''
  modal.value = 'cashier'
}

async function startCashierSession(next: Cashier) {
  screenLocked.value = false
  cashier.value = next
  if (navigator.onLine) {
    shift.value = await api.activeShift(profile.value!.branchId, next.id)
  } else {
    shift.value = (await loadSession()).shift
  }
  await saveSession(cashier.value, shift.value)
  modal.value = shift.value ? null : 'shift'
  cashierPin.value = ''
}

/**
 * กะในเครื่องต้องเป็นกะที่ยังเปิดอยู่ใน ERP (ถูกปิดจากหลังบ้าน หรือถูกล้างข้อมูลไปแล้ว)
 * ไม่ตรง = ปิดกะในเครื่องแล้วให้เปิดกะใหม่ ไม่งั้นขายต่อแล้วทุกบิลจะส่งเข้า ERP ไม่ได้
 */
async function reconcileShiftWithErp() {
  if (!profile.value || !cashier.value || !shift.value) return
  const live = await api.activeShift(profile.value.branchId, cashier.value.id)
  if (live?.id === shift.value.id) {
    shift.value = live
    await saveSession(cashier.value, live)
    return
  }
  const stale = shift.value.shift_no
  shift.value = live
  await saveSession(cashier.value, live)
  if (!live) {
    flash(`กะ ${stale} ไม่ได้เปิดอยู่ใน ERP แล้ว กรุณาเปิดกะใหม่`)
    if (!modal.value) modal.value = 'shift'
  }
}

/** บิลวันนี้ที่ค้างเพราะกะเดิมไม่มีแล้ว ย้ายเข้ากะที่เพิ่งเปิดแล้วส่งใหม่ (บิลวันก่อนไม่ย้าย ยอดกะเก่าจะได้ไม่ปน) */
async function moveStrandedSalesToShift(shiftId: number) {
  const today = new Date().toDateString()
  for (const item of await queueItems()) {
    if (item.status === 'synced' || !/เปิดกะ/.test(item.error || '')) continue
    if (new Date(item.createdAt).toDateString() !== today) continue
    await patchQueuePayload(item.id, { shift_id: shiftId })
  }
}

async function openShift() {
  if (!cashier.value || !profile.value) return
  busy.value = true
  try {
    shift.value = await api.openShift(profile.value.branchId, cashier.value.id, openingCash.value, openingNote.value)
    await saveSession(cashier.value, shift.value)
    await moveStrandedSalesToShift(shift.value.id)
    void syncCheckoutQueue(refreshQueue)
    modal.value = null
    flash(`เปิดกะ ${shift.value.shift_no} แล้ว`)
  } catch (e) { showError(e) } finally { busy.value = false }
}

const lastZReport = ref<ZReport | null>(null)
const printingZ = ref(false)
async function printZReport() {
  if (!lastZReport.value) {
    try { lastZReport.value = JSON.parse((await loadAppState('last_z_report')) || 'null') } catch { /* ไม่มี */ }
  }
  if (!lastZReport.value) return flash('ยังไม่มี Z Report ในเครื่อง ปิดกะก่อน')
  printingZ.value = true
  await nextTick()
  await printAndWait()
  printingZ.value = false
}

/** ยอดเงินที่ควรมีของกะต้องมาจาก ERP ล่าสุด ค่าในเครื่องเป็นค่าตอนเปิดกะ ยังไม่รวมยอดขาย */
async function openCloseShift() {
  if (!shift.value || !profile.value || !cashier.value) return
  try {
    const live = await api.activeShift(profile.value.branchId, cashier.value.id)
    if (live) { shift.value = live; await saveSession(cashier.value, live) }
  } catch (e) { showError(e); return }
  countedCash.value = ''
  floatKept.value = shift.value?.default_float ?? 0
  depositReference.value = ''
  closePreview.value = null
  denominationCounts.value = denominationValues.map(() => 0)
  modal.value = 'closeShift'
}

async function previewCloseShift() {
  if (!shift.value || countedCash.value === '') return
  busy.value = true
  try {
    closePreview.value = await api.closeShift(shift.value.id, countedCash.value, {
      preview: true, float_kept: floatKept.value, deposit_reference: depositReference.value,
    })
  } catch (e) { showError(e) } finally { busy.value = false }
}

async function closeShift() {
  if (busy.value) return
  if (!shift.value || countedCash.value === '') return
  if (shift.value.default_float !== undefined && !closePreview.value) { showError('ตรวจยอดก่อนยืนยันปิดกะ'); return }
  if (pendingCount.value) { showError('ต้องส่งบิลค้างขึ้น ERP ให้ครบก่อนปิดกะ'); return }
  busy.value = true
  try {
    const closed = await api.closeShift(shift.value.id, countedCash.value, shift.value.default_float === undefined ? {} : { float_kept: floatKept.value, deposit_reference: depositReference.value })
    shift.value = null
    // Z Report: เก็บไว้พิมพ์ซ้ำได้ แล้วพิมพ์ทันที (เครื่องพิมพ์หลักของ Windows)
    lastZReport.value = closed
    await saveAppState('last_z_report', JSON.stringify(closed)).catch(() => undefined)
    void printZReport()
    await saveSession(cashier.value, null)
    modal.value = 'shift'
    flash('ปิดกะเรียบร้อยแล้ว')
  } catch (e) { showError(e) } finally { busy.value = false }
}

function openHoldBill() {
  if (!cart.value.length || !shift.value) return
  holdLabel.value = `บิล ${new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })}`
  modal.value = 'holdBill'
}

async function holdBill() {
  if (!profile.value || !cashier.value || !shift.value || !cart.value.length) return
  busy.value = true
  try {
    await api.holdBill({
      branch_id: profile.value.branchId,
      shift_id: shift.value.id,
      cashier_id: cashier.value.id,
      label: holdLabel.value.trim(),
      total_amount: subtotal.value,
      payload: { cart: cart.value },
    })
    cart.value = []
    billDiscount.value = 0
    member.value = null
    memberQuery.value = ''
    transferLast4.value = ''
    modal.value = null
    flash('พักบิลไว้ส่วนกลางแล้ว')
  } catch (e) { showError(e) } finally { busy.value = false }
}

async function openHeldBills() {
  if (!profile.value || !cashier.value || !navigator.onLine) {
    showError('ต้องออนไลน์และเข้าแคชเชียร์ก่อนเรียกบิลส่วนกลาง')
    return
  }
  busy.value = true
  try {
    heldBills.value = await api.heldBills(profile.value.branchId, cashier.value.id)
    modal.value = 'heldBills'
  } catch (e) { showError(e) } finally { busy.value = false }
}

async function resumeHeldBill(id: number) {
  if (cart.value.length && !window.confirm('แทนที่บิลปัจจุบันด้วยบิลพักที่เลือก?')) return
  busy.value = true
  try {
    const bill = await api.resumeHeldBill(id)
    // บิลที่พักจากรุ่นก่อนเหลือแค่รหัสกับจำนวน เติมชื่อและราคาจากสินค้าในเครื่อง
    cart.value = (bill.cart || []).map((line) => ({ ...products.value.find((product) => product.id === line.id), ...line }))
    heldBills.value = heldBills.value.filter((item) => item.id !== id)
    modal.value = null
    flash(`เรียกบิล ${bill.hold_no} แล้ว`)
  } catch (e) { showError(e) } finally { busy.value = false }
}

async function recordCashDrop() {
  if (!shift.value || cashDropAmount.value <= 0) return
  busy.value = true
  try {
    const result = await api.cashMovement({
      shift_id: shift.value.id,
      movement_type: 'drop',
      amount: cashDropAmount.value,
      reference_no: cashDropReference.value || undefined,
      reason: 'นำส่งเงินระหว่างกะ',
    })
    shift.value = result.shift
    countedCash.value = ''
    closePreview.value = null
    cashDropAmount.value = 0
    cashDropReference.value = ''
    flash(result.message)
  } catch (e) { showError(e) } finally { busy.value = false }
}

function openPayment() {
  if (!storageReady.value) { showError('ยังไม่สามารถรับชำระได้: ยังเตรียมฐานข้อมูล POS ไม่สำเร็จ กรุณาเปิด ตั้งค่าเครื่อง แล้วตรวจสุขภาพ POS Local'); return }
  if (!cashier.value) { modal.value = 'cashier'; return }
  if (!shift.value) { modal.value = 'shift'; return }
  repriceCartForCurrentSchedule()
  // เลขอ้างอิงของบิลก่อนห้ามติดมาบิลใหม่
  paymentRef.value = ''
  transferLast4.value = ''
  cashReceived.value = subtotal.value
  modal.value = 'payment'
}

function setQuickCash(amount: number | 'exact') {
  cashReceived.value = amount === 'exact' ? subtotal.value : amount
}

async function checkout() {
  if (!profile.value || !cashier.value || !shift.value || !cart.value.length) return
  repriceCartForCurrentSchedule()
  if (paymentMethod.value === 'cash' && cashReceived.value < subtotal.value) { showError('ยอดเงินสดที่รับไม่ครบ'); return }
  if ((paymentMethod.value === 'transfer' || paymentMethod.value === 'mixed') && !/^\d{4}$/.test(transferLast4.value)) { showError('ใส่เลขท้ายบัญชีผู้โอน 4 หลัก (ดูจากสลิป)'); return }
  if (paymentMethod.value === 'mixed' && !(cashReceived.value > 0 && cashReceived.value < subtotal.value)) { showError('จ่ายผสม: เงินสดต้องมากกว่า 0 และน้อยกว่ายอดบิล ที่เหลือเป็นยอดโอน'); return }
  const id = `${profile.value.terminalCode || 'POS'}:SALE:${crypto.randomUUID()}`
  // ของแถมพิมพ์ในใบเสร็จด้วย ราคา 0
  const freeLines = pricing.value.freeItems.map((free) => ({
    ...(products.value.find((product) => product.id === free.product_id) ?? { id: free.product_id, sku_code: free.sku_code, barcodes: [] }),
    name_th: `${free.name_th} (แถม)`, pos_price: 0, qty: free.qty,
  }) as CartLine)
  const soldItems = [...cart.value.map((line) => ({ ...line })), ...freeLines]
  const soldTotal = subtotal.value
  const soldDiscount = pricing.value.manualDiscount
  const soldChange = change.value
  const payload = {
    branch_id: profile.value.branchId,
    shift_id: shift.value.id,
    cashier_id: cashier.value.id,
    method: paymentMethod.value,
    payment_ref: paymentRef.value || undefined,
    ...(paymentMethod.value === 'transfer' || paymentMethod.value === 'mixed' ? { transfer_account_last4: transferLast4.value } : {}),
    payment_confirmed: paymentMethod.value !== 'cash',
    cash_received: paymentMethod.value === 'cash' || paymentMethod.value === 'mixed' ? cashReceived.value : undefined,
    change_amount: paymentMethod.value === 'cash' ? change.value : paymentMethod.value === 'mixed' ? 0 : undefined,
    ...(paymentMethod.value === 'mixed' ? { cash_amount: cashReceived.value, transfer_amount: Number((subtotal.value - cashReceived.value).toFixed(2)) } : {}),
    ...(member.value ? { member_id: member.value.id } : {}),
    bill_promotions: true,
    mix_promotions: true,
    vat_mode: 'included' as const,
    ...(pricing.value.manualDiscount > 0 ? {
      manual_discount_amount: pricing.value.manualDiscount,
      net_items: true,
      discount_approved_by: `คนขาย ${cashier.value.name}`,
    } : {}),
    items: pricing.value.items,
  }
  const queueItem: QueueItem = { id, payload, status: 'pending', attempts: 0, createdAt: new Date().toISOString() }
  busy.value = true
  try {
    await enqueue(queueItem)
    // Queue เป็นข้อมูลหลักของการขาย ประวัติในเครื่องเป็นข้อมูลเสริมที่ห้ามทำให้บิลถูกมาร์ค failed
    try {
      await saveSaleHistory({ id, receiptNo: id.split(':').pop()!.slice(0, 8).toUpperCase(), status: 'pending', total: soldTotal, method: paymentMethod.value, paid: cashReceived.value, change: soldChange, items: soldItems, printedAt: new Date().toISOString() })
      await refreshLocalSales()
    } catch (historyError) {
      console.warn('บันทึกประวัติ POS ไม่สำเร็จ แต่บิลยังอยู่ในคิวซิงก์', historyError)
    }
    cart.value = []
    billDiscount.value = 0
    modal.value = null
    await refreshQueue()
    let receiptNo = id.split(':').pop()!.slice(0, 8).toUpperCase()
    let provisional = true
    if (navigator.onLine) {
      await syncCheckoutQueue(refreshQueue)
      await reconcileShiftWithErp().catch(() => undefined)
      const result = (await queueItems()).find((item) => item.id === id)
      if (result?.status === 'synced') {
        receiptNo = result.receiptNo || receiptNo
        provisional = false
        flash(`บันทึกบิล ${result.receiptNo || ''} แล้ว`)
      }
      else flash('เก็บบิลในเครื่องแล้ว ระบบจะส่งซ้ำอัตโนมัติ')
    } else flash('ออฟไลน์: เก็บบิลในเครื่องแล้ว ระบบจะส่งเมื่ออินเทอร์เน็ตกลับมา')
    lastReceipt.value = { no: receiptNo, items: soldItems, discount: soldDiscount, total: soldTotal, method: paymentMethod.value, paid: cashReceived.value, change: soldChange, printedAt: new Date().toLocaleString('th-TH'), provisional }
    // พิมพ์เองทุกบิลเป็นค่าเริ่มต้น ปิดได้ที่ฮาร์ดแวร์ของเครื่องใน ERP (auto_print = false)
    if (profile.value.hardwareProfile?.auto_print !== false) {
      const copies = receiptCopies(paymentMethod.value)
      window.setTimeout(() => { void printLastReceipt(copies) }, 150)
    }
  } catch (e) {
    await markQueue(id, 'failed', e instanceof Error ? e.message : String(e)).catch(() => undefined)
    showError(e)
  } finally { busy.value = false }
}

async function checkUpdate() {
  try {
    const update = await check()
    if (update) {
      flash(`กำลังอัปเดตเป็นรุ่น ${update.version}`)
      await update.downloadAndInstall()
    }
  } catch { /* การขายต้องทำต่อได้แม้เซิร์ฟเวอร์อัปเดตไม่ตอบ */ }
}

async function start() {
  if (!isTauri() && (import.meta.env.DEV || browserPreview)) {
    profile.value = { serverUrl: setupUrl.value, deviceName: 'เครื่องทดสอบ', terminalCode: 'POS-0001-01', branchId: 1, branchName: 'สาขาวารินชำราบ', vatRate: 7, company: { name: 'บริษัท ป๊อปสตาร์ฟู้ดเทรดดิ้ง จำกัด' } }
    cashier.value = { id: 1, code: 'C001', name: 'พนักงานทดสอบ' }
    shift.value = { id: 1, shift_no: 'SHIFT-0001-DEMO', status: 'open', opening_cash: 1000, expected_cash: 1000, receipt_count: 0 }
    products.value = [
      { id: 1, sku_code: '103022', name_th: 'BB-B ก้าวหน้า (ถุง 10 กก.)', pos_price: 80, stock_qty: 24, barcodes: [] },
      { id: 2, sku_code: '301355', name_th: 'Boss Coffee ลาเต้ 230 มล.', pos_price: 25, stock_qty: 48, is_promotion: true, barcodes: [] },
      { id: 3, sku_code: '208111', name_th: 'CPW ถ้วยกระดาษ 390 cc', pos_price: 15, stock_qty: 120, barcodes: [] },
      { id: 4, sku_code: '800101', name_th: 'หมูหมักงาสำหรับชุดหมูกระทะ', pos_price: 159, stock_qty: 18.5, barcodes: [] },
      { id: 5, sku_code: '800102', name_th: 'สามชั้นสไลซ์แช่เย็น', pos_price: 189, stock_qty: 12.75, is_flash_sale: true, barcodes: [] },
      { id: 6, sku_code: '401201', name_th: 'น้ำจิ้มสุกี้ POPSTAR 750 มล.', pos_price: 69, stock_qty: 31, barcodes: [] },
    ]
    return
  }
  try {
    storageStatus.value = await invoke<LocalStorageStatus>('prepare_local_storage')
  } catch (e) {
    showError(`เตรียมพื้นที่เก็บข้อมูล POS ไม่สำเร็จ: ${e instanceof Error ? e.message : String(e)}`)
    return
  }
  profile.value = await loadProfile()
  products.value = await loadProducts()
  promotions.value = await loadPromotions()
  await refreshLocalSales()
  const saved = await loadSession()
  cashier.value = saved.cashier
  shift.value = saved.shift?.status === 'open' ? saved.shift : null
  await refreshQueue()
  if (!profile.value) { modal.value = 'settings'; await inspectLocalDb(); return }
  setServerUrl(profile.value.serverUrl)
  // เปิดแอปใหม่ (ปิดไป/ไฟดับ/แอปค้าง) ต้องใส่ PIN ทุกครั้ง ERP จะได้รู้ว่าใครขายจริง
  // ตะกร้าที่ยังไม่ชำระกลับมาเหมือนเดิม
  try {
    const savedCart = JSON.parse((await loadAppState('cart')) || 'null')
    if (Array.isArray(savedCart?.cart)) { cart.value = savedCart.cart; billDiscount.value = Number(savedCart.billDiscount || 0) }
  } catch { /* ตะกร้าเสียหาย เริ่มใหม่ */ }
  screenLocked.value = true
  modal.value = 'cashier'
  void syncAll()
  void checkUpdate()
}

function networkUp() { online.value = true; void syncAll() }
function networkDown() { online.value = false }
function handleShortcut(event: KeyboardEvent) {
  // หน้าใส่ PIN: กดตัวเลขจากคีย์บอร์ดได้เสมอ แม้เพิ่งกดแป้นบนจอ (ช่อง PIN จริงซ่อนอยู่)
  if (modal.value === 'cashier' && profile.value?.cashierLoginMode !== 'selection' && document.activeElement?.tagName !== 'INPUT') {
    if (/^\d$/.test(event.key)) { event.preventDefault(); cashierPin.value = (cashierPin.value + event.key).slice(0, 20); return }
    if (event.key === 'Backspace') { event.preventDefault(); cashierPin.value = cashierPin.value.slice(0, -1); return }
  }
  if (event.key === 'F8' && !modal.value) {
    event.preventDefault()
    scanner.value?.focus()
  }
  if (event.key === 'F10' && !modal.value && cart.value.length) {
    event.preventDefault()
    openPayment()
  }
  // ตั้งค่าครั้งแรกกับตั้ง PIN ใหม่ กด Escape ข้ามไม่ได้ ต้องทำให้จบก่อน
  const locked = (modal.value === 'closeShift' && busy.value) || modal.value === 'changePin' || modal.value === 'cashier' || (modal.value === 'settings' && !profile.value)
  if (event.key === 'Escape' && modal.value === 'closeApp') { event.preventDefault(); cancelCloseApp(); return }
  if (event.key === 'Escape' && modal.value && !locked) {
    modal.value = null
    nextTick(() => scanner.value?.focus())
  }
}
onMounted(() => {
  // กดกากบาท: ถามก่อนเสมอ ป้องกันปิดกลางบิล (ย่อหน้าต่างไปทำอย่างอื่นได้ตามปกติ)
  if (isTauri()) {
    void getCurrentWindow().onCloseRequested((event) => {
      event.preventDefault()
      // จำหน้าต่างเดิมไว้ กด "กลับ" แล้วต้องกลับไปที่เดิม (หน้าล็อกต้องยังล็อกอยู่)
      modalBeforeClose = modal.value === 'closeApp' ? modalBeforeClose : modal.value
      modal.value = 'closeApp'
    })
  }
  window.addEventListener('online', networkUp)
  window.addEventListener('offline', networkDown)
  window.addEventListener('keydown', handleShortcut)
  syncTimer = window.setInterval(() => {
    catalogClock.value = Date.now()
    repriceCartForCurrentSchedule()
    if (profile.value && navigator.onLine) void syncAll()
  }, 30000)
  void start()
})
onUnmounted(() => {
  window.removeEventListener('online', networkUp)
  window.removeEventListener('offline', networkDown)
  window.removeEventListener('keydown', handleShortcut)
  if (syncTimer !== null) window.clearInterval(syncTimer)
})
</script>

<template>
  <main class="app-shell">
    <div v-if="shift?.cash_warning" role="alert" style="background:#fff4cf;color:#854d0e;padding:8px">เงินสดในลิ้นชักเกินเกณฑ์ ควรนำส่งเงิน</div>
    <header class="topbar">
      <div class="brand"><img class="brand-logo" :src="mpmLogo" alt="MPM Food Group"><div><strong>JET POS</strong><small>POINT OF SALE</small></div></div>
      <div class="terminal-context">
        <div><span>สาขา / เครื่อง</span><strong>{{ profile?.branchName || 'ยังไม่ได้ตั้งค่าเครื่อง' }}</strong><small>{{ profile?.terminalCode || 'POS' }}</small></div>
        <div><span>กะขาย</span><strong>{{ shift?.shift_no || 'ยังไม่เปิดกะ' }}</strong><small>{{ cashier?.name || 'ยังไม่เข้าแคชเชียร์' }}</small></div>
      </div>
      <div class="top-actions">
        <button class="status" :class="connectionClass" :title="catalogSyncError || 'ตรวจการเชื่อมต่อ ERP และซิงก์ข้อมูล'" @click="syncAll"><Wifi v-if="profile && online"/><CloudOff v-else/><span>{{ connectionLabel }}</span></button>
        <button v-if="cashier && !shift" class="shift-action" title="เปิดกะขาย" @click="modal = 'shift'"><Banknote/><span>เปิดกะ</span></button>
        <button v-if="cashier" class="icon-button" title="ล็อกหน้าจอ (พัก/เปลี่ยนคน)" @click="lockScreen"><Lock/></button>
        <button class="shift-action" :title="cashier ? 'ออกจากระบบ' : 'เข้าสู่ระบบ'" @click="cashier ? logoutCashier() : (modal = 'cashier')"><LogOut/><span>{{ cashier ? 'ออกจากระบบ' : 'เข้าสู่ระบบ' }}</span></button>
        <button class="icon-button" title="ซิงก์ข้อมูล" @click="syncAll"><RefreshCw :class="{ spin: syncing }"/></button>
        <button class="icon-button" title="คู่มือการใช้งาน POS" @click="openGuide"><CircleHelp/></button>
        <button class="icon-button" title="ประวัติการขายย้อนหลัง" @click="openHistory"><History/></button>
        <button v-if="lastReceipt" class="icon-button" title="พิมพ์บิลล่าสุด" @click="printLastReceipt()"><Printer/></button>
        <button class="icon-button" title="เรียกบิลพักส่วนกลาง" @click="openHeldBills"><FolderOpen/></button>
        <button v-if="shift" class="icon-button" title="ปิดกะขาย" @click="openCloseShift"><LogOut/></button>
        <button class="icon-button" title="ตั้งค่าเครื่อง" @click="openSettings"><Settings/></button>
      </div>
    </header>

    <section class="workspace">
      <div class="catalog">
        <div class="section-heading">
          <div><span>รายการสินค้า</span><strong>เลือกหรือสแกนเพื่อขาย</strong></div>
          <div class="catalog-count"><b>{{ products.length }}</b><span>สินค้าในเครื่อง</span></div>
        </div>
        <div class="search-row">
          <span class="scan-icon"><ScanLine/></span>
          <input ref="scanner" v-model="search" autofocus placeholder="สแกนบาร์โค้ด หรือค้นหาชื่อสินค้า" @keyup.enter="scan" />
          <span class="search-state"><Search/>{{ filteredProducts.length }} รายการ · F8</span>
        </div>
        <section v-if="!search && quickProducts.length" class="quick-sell">
          <div><strong>ขายบ่อยบนเครื่องนี้</strong><small>อิงจาก 30 วันล่าสุด</small></div>
          <div class="quick-sell-list">
            <button v-for="product in quickProducts" :key="`quick-${product.id}`" type="button" @click="addProduct(product)">
              <span>{{ product.name_th }}</span><b>฿{{ money(product.pos_price) }}</b>
            </button>
          </div>
        </section>
        <div v-if="products.length" class="product-grid">
          <button v-for="product in filteredProducts" :key="product.id" class="product-tile" @click="addProduct(product)">
            <div class="product-code"><span>{{ product.sku_code }}</span><em v-if="product.margin_warning">กำไรต่ำ</em><em v-else-if="product.is_promotion || product.is_flash_sale">ราคาพิเศษ</em></div>
            <strong>{{ product.name_th }}</strong>
            <div class="product-bottom"><span :class="{ low: product.stock_qty != null && product.stock_qty <= 5 }"><i></i>คงเหลือ {{ product.stock_qty == null ? '-' : product.stock_qty }}</span><b>฿{{ money(product.pos_price) }}</b></div>
          </button>
        </div>
        <div v-else class="empty-state"><PackageSearch/><strong>ยังไม่มีข้อมูลสินค้าในเครื่อง</strong><span>{{ catalogSyncError || 'ตั้งค่าเครื่องและเชื่อมต่ออินเทอร์เน็ตเพื่อดาวน์โหลดสินค้า' }}</span><button type="button" class="empty-action" :disabled="syncing" @click="syncAll"><RefreshCw :class="{ spin: syncing }"/>{{ syncing ? 'กำลังโหลดสินค้า...' : 'โหลดสินค้าใหม่' }}</button></div>
      </div>

      <aside class="cart-panel">
        <div class="cart-title"><div><span class="cart-icon"><ReceiptText/></span><span><small>บิลปัจจุบัน</small><strong>รายการขาย</strong></span></div><div style="display:flex;align-items:center;gap:8px"><button class="icon-button" :disabled="!cart.length || !shift" title="พักบิลส่วนกลาง" @click="openHoldBill"><PauseCircle/></button><span>{{ cart.length }} รายการ · {{ totalQty }} ชิ้น</span></div></div>
        <div class="shift-pulse">
          <span><small>บิลวันนี้บนเครื่อง</small><strong>{{ todayLocalSales.length }} บิล</strong></span>
          <span><small>ยอดขาย</small><strong>฿{{ money(todayLocalTotal) }}</strong></span>
          <span><small>เฉลี่ย/บิล</small><strong>฿{{ money(todayLocalAverage) }}</strong></span>
        </div>
        <div v-if="cart.length" class="cart-columns"><span>#</span><span>สินค้า</span><span>จำนวน</span><span>รวม</span><span></span></div>
        <div class="cart-lines">
          <div v-for="(line, index) in cart" :key="`${line.id}-${line.scannedBarcode || ''}`" class="cart-line">
            <span class="line-seq">{{ index + 1 }}</span>
            <div class="line-info"><strong>{{ line.name_th }}</strong><span>{{ line.sku_code }} · ฿{{ money(line.pos_price) }}</span></div>
            <div class="qty-control"><button title="ลดจำนวน" @click="line.qty <= 1 ? cart.splice(index, 1) : line.qty--"><Minus/></button><input v-model.number="line.qty" type="number" min="0.001" step="1"><button title="เพิ่มจำนวน" @click="line.qty++"><Plus/></button></div>
            <b>฿{{ money(line.pos_price * line.qty) }}</b>
            <button class="delete" title="ลบรายการ" @click="cart.splice(index, 1)"><Trash2/></button>
          </div>
          <div v-for="free in pricing.freeItems" :key="`free-${free.product_id}`" class="cart-line free-line">
            <span class="line-seq">แถม</span>
            <div class="line-info"><strong>{{ free.name_th }}</strong><span>{{ free.sku_code }} · {{ free.promotion }}</span></div>
            <div class="qty-control"><span>× {{ free.qty }}</span></div>
            <b>฿0.00</b>
            <span></span>
          </div>
          <div v-if="!cart.length" class="cart-empty"><span><ShoppingCart/></span><strong>ยังไม่มีรายการขาย</strong><small>สแกนสินค้าเพื่อเริ่มบิล</small></div>
        </div>
        <label class="bill-discount">ส่วนลดท้ายบิล (บาท)<input v-model.number="billDiscount" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0"></label>
        <div class="totals"><div v-if="pricing.promoDiscount > 0" class="promo-row"><span>ส่วนลดโปรโมชั่น</span><b>-฿{{ money(pricing.promoDiscount) }}</b></div><div><span>ยอดก่อนภาษี</span><b>฿{{ money(subtotal - vat) }}</b></div><div><span>VAT {{ profile?.vatRate || 7 }}%</span><b>฿{{ money(vat) }}</b></div><div class="grand"><span>ยอดสุทธิ</span><b>฿{{ money(subtotal) }}</b></div></div>
        <button class="pay-button" :disabled="!cart.length || busy || !storageReady" @click="openPayment"><Banknote/><span><small>{{ storageReady ? 'รับชำระ' : 'รอพื้นที่ข้อมูล' }}</small><strong>{{ storageReady ? 'ชำระเงิน' : 'ตรวจฐานข้อมูล' }}</strong></span><ChevronRight/></button>
        <div class="queue-bar" :class="{ warning: pendingCount }"><Cloud v-if="pendingCount"/><CheckCircle2 v-else/><span>{{ pendingCount ? `รอส่งขึ้น ERP ${pendingCount} บิล${queueError ? ` · ${queueError}` : ''}` : 'บิลทั้งหมดส่งขึ้น ERP แล้ว' }}</span></div>
      </aside>
    </section>

    <transition name="toast"><div v-if="notice" class="toast success"><CheckCircle2/>{{ notice }}</div></transition>
    <transition name="toast"><div v-if="error" class="toast error"><AlertTriangle/>{{ error }}<button @click="error = ''"><X/></button></div></transition>

    <div v-if="modal" class="modal-backdrop">
      <section v-if="modal === 'guide'" class="modal guide-modal">
        <div class="modal-head"><div><CircleHelp/><span><strong>คู่มือ JET POS</strong><small>ขั้นตอนใช้งานและแก้ปัญหาเบื้องต้น</small></span></div><button type="button" @click="modal = null"><X/></button></div>
        <div class="guide-grid">
          <article><b>เริ่มขาย</b><span>ตั้งค่าเครื่องครั้งแรก → เข้าแคชเชียร์ → เปิดกะ → สแกนสินค้า → รับชำระ</span></article>
          <article><b>เน็ตหลุด</b><span>ขายต่อได้ตามปกติ บิลจะเก็บในเครื่องและส่งขึ้น ERP อัตโนมัติเมื่อออนไลน์</span></article>
          <article><b>บิลค้าง</b><span>กดไอคอนซิงก์ ระบบจะส่งซ้ำด้วยเลขอ้างอิงเดิม ไม่สร้างบิลซ้ำ</span></article>
          <article><b>ดูบิลย้อนหลัง</b><span>กดไอคอนประวัติ เลือก 30, 60 หรือ 90 วัน แล้วเลือกบิลเพื่อดูหรือพิมพ์ซ้ำ</span></article>
          <article><b>แก้ปัญหา SQLite</b><span>เข้า ตั้งค่าเครื่อง → สุขภาพ POS Local → ตรวจใหม่ หรือสำรองข้อมูลก่อนให้ IT ช่วย</span></article>
          <article><b>เปลี่ยนเครื่อง</b><span>ติดตั้งรุ่นล่าสุด วาง Device Token ของเครื่องใหม่ แล้วดาวน์โหลดสินค้าและราคาจาก ERP</span></article>
        </div>
        <div class="guide-footer"><strong>หลักสำคัญ:</strong> ERP เป็นผู้ตรวจราคา ตัดสต๊อก และออกเลขบิลจริง ส่วน POS ทำหน้าที่ขายต่อเนื่องและเก็บคิวเมื่อออฟไลน์</div>
        <button type="button" class="primary" @click="modal = null">เข้าใจแล้ว</button>
      </section>
      <form v-if="modal === 'settings'" class="modal" @submit.prevent="configure">
        <div class="modal-head"><div><Settings/><span><strong>ตั้งค่าเครื่อง POS</strong><small>เชื่อมเครื่องนี้กับ ERP เพียงครั้งแรก</small></span></div><button type="button" @click="modal = null"><X/></button></div>
        <label>ที่อยู่เซิร์ฟเวอร์<input v-model="setupUrl" required placeholder="https://erp.popstarcenter.com"></label>
        <small class="setup-hint">ที่อยู่ ERP ปัจจุบันคือ https://erp.popstarcenter.com หากเชื่อมต่อไม่ได้ ให้ตรวจอินเทอร์เน็ตและ Device Token</small>
        <p v-if="setupError" class="setup-error">{{ setupError }}</p>
        <label>รหัสประจำเครื่อง (ดูจากหลังบ้าน จัดการเครื่อง POS)<input v-model="pairCode" inputmode="numeric" maxlength="8" placeholder="8 หลัก"></label>
        <details><summary>ใช้ Device Token แทน</summary>        <label>Device Token<textarea v-model="setupToken" rows="3" placeholder="วาง Token จาก ERP > ตั้งค่า > ดาวน์โหลด POS"></textarea></label></details>
        <label>โฟลเดอร์เก็บข้อมูล POS<input v-model="storageFolder" required placeholder="D:\\POPSTAR-POS\\data"></label>
        <small class="setup-hint">แนะนำให้เลือกไดรฟ์ D: หรือโฟลเดอร์ข้อมูลที่สำรองได้ ระบบจะเก็บ SQLite, บิลออฟไลน์ และคิวซิงก์ไว้ที่นี่</small>
        <small class="setup-hint">Token ใช้ผูกเครื่องกับสาขา ไม่ควรนำไปใส่ใน GitHub หรือส่งต่อให้พนักงาน</small>
        <div class="modal-actions"><button type="button" class="secondary" @click="modal = null">ปิด</button><button class="primary" :disabled="busy">{{ busy ? 'กำลังตรวจสอบ...' : 'เชื่อมต่อเครื่อง' }}</button></div>
        <div class="local-diagnostics">
          <div class="diagnostic-head"><span><strong>สุขภาพ POS Local</strong><small>ตรวจ SQLite และคิวบิลในเครื่องนี้</small></span><button type="button" class="icon-button" title="ตรวจใหม่" :disabled="localHealthBusy" @click="inspectLocalDb"><RefreshCw :class="{ spin: localHealthBusy }"/></button></div>
          <div v-if="storageStatus" class="storage-status" :class="storageStatus.location === 'd-drive' ? 'storage-ok' : 'storage-warning'"><strong>{{ storageStatus.location === 'd-drive' ? 'ข้อมูลอยู่ไดรฟ์ D:' : 'ข้อมูลยังอยู่ไดรฟ์ C:' }}</strong><small>{{ storageStatus.database_path }}</small></div>
          <p v-if="storageStatus?.warning" class="diagnostic-warning">{{ storageStatus.warning }}</p>
          <div v-if="localHealth" class="diagnostic-grid">
            <div><span>SQLite</span><strong :class="localHealth.integrity === 'ok' ? 'ok' : 'bad'">{{ localHealth.integrity === 'ok' ? 'ปกติ' : localHealth.integrity }}</strong></div>
            <div><span>สินค้าในเครื่อง</span><strong>{{ localHealth.products }}</strong></div>
            <div><span>บิลรอส่ง</span><strong :class="localHealth.pending ? 'bad' : 'ok'">{{ localHealth.pending }}</strong></div>
            <div><span>บิลล้มเหลว</span><strong :class="localHealth.failed ? 'bad' : 'ok'">{{ localHealth.failed }}</strong></div>
          </div>
          <p v-if="localHealth" class="diagnostic-meta">ซิงก์สินค้าล่าสุด: {{ localHealth.lastProductSyncAt ? new Date(localHealth.lastProductSyncAt).toLocaleString('th-TH') : 'ยังไม่เคยซิงก์' }} · SQLite {{ (localHealth.sizeBytes / 1024).toFixed(1) }} KB</p>
          <p v-else class="diagnostic-meta">กดตรวจใหม่เพื่ออ่านสถานะ SQLite</p>
          <div class="diagnostic-actions"><button type="button" class="secondary" :disabled="localBackupBusy" @click="backupLocalDb()">{{ localBackupBusy ? 'กำลังสำรอง...' : 'สำรอง SQLite' }}</button><button type="button" class="secondary danger-action" :disabled="!pendingCount" @click="clearStuckSales">ล้างบิลค้าง {{ pendingCount }} บิล</button><button type="button" class="secondary danger-action" :disabled="localRestoreBusy || !localHealth || localHealth.pending > 0 || localHealth.failed > 0" @click="restoreLocalDb">{{ localRestoreBusy ? 'กำลังกู้คืน...' : 'กู้คืน Backup ล่าสุด' }}</button></div>
          <small class="diagnostic-note">Restore จะสร้างไฟล์ pre-restore ก่อนเสมอ และล็อกเมื่อมีบิลค้าง/ล้มเหลว</small>
        </div>
      </form>

      <form v-else-if="modal === 'cashier'" class="modal compact" @submit.prevent="loginCashier">
        <div class="modal-head"><div><UserRound/><span><strong>เข้าใช้งานแคชเชียร์</strong><small>{{ profile?.branchName }} · {{ profile?.terminalCode }}</small></span></div></div>
        <template v-if="profile?.cashierLoginMode === 'selection'">
          <small class="setup-hint">เครื่องนี้ผูกสาขา {{ profile?.branchName }} แล้ว เลือกชื่อผู้ปฏิบัติงานเพื่อเริ่มขาย</small>
          <button class="primary" :disabled="busy">เลือกชื่อพนักงาน</button>
        </template>
        <template v-else>
          <strong class="pin-title">ใส่ PIN ของคุณ</strong>
          <input v-model="cashierPin" class="pin-input-hidden" required autofocus type="password" inputmode="numeric" pattern="\d{4,20}" minlength="4" maxlength="20" autocomplete="current-password" aria-label="PIN ของพนักงาน">
          <div class="pin-dots"><span v-for="n in Math.max(4, cashierPin.length)" :key="n" :class="{ filled: n <= cashierPin.length }"></span></div>
          <div class="pin-pad">
            <button v-for="digit in ['1','2','3','4','5','6','7','8','9']" :key="digit" type="button" :disabled="busy" @click="cashierPin = (cashierPin + digit).slice(0, 20)">{{ digit }}</button>
            <button type="button" class="pin-action" :disabled="busy" @click="cashierPin = ''">ล้าง</button>
            <button type="button" :disabled="busy" @click="cashierPin = (cashierPin + '0').slice(0, 20)">0</button>
            <button type="button" class="pin-action" :disabled="busy" @click="cashierPin = cashierPin.slice(0, -1)">ลบ</button>
          </div>
          <small class="setup-hint">ใส่ PIN ครบแล้วรอสักครู่ หรือกดเข้าใช้งาน · พิมพ์จากคีย์บอร์ดได้ · สาขา {{ profile?.branchName }}</small>
          <button class="primary" :disabled="busy || cashierPin.length < 4">{{ busy ? 'กำลังเข้าใช้งาน...' : 'เข้าใช้งาน' }}</button>
          <button type="button" class="link-button" @click="openSettings">ตั้งค่าเครื่อง (ผู้ดูแล)</button>
        </template>
      </form>

      <section v-else-if="modal === 'selectCashier'" class="modal compact">
        <div class="modal-head"><div><UserRound/><span><strong>เลือกชื่อพนักงาน</strong><small>PIN เริ่มต้นนี้ใช้ร่วมกัน กรุณาเลือกชื่อผู้ปฏิบัติงานจริง</small></span></div><button type="button" @click="modal = null"><X/></button></div>
        <div class="held-list">
          <button v-for="choice in cashierChoices" :key="choice.id" type="button" :disabled="busy" @click="selectCashier(choice)">
            <span><strong>{{ choice.name }}</strong><small>{{ choice.code }}</small></span><ChevronRight/>
          </button>
        </div>
        <button type="button" class="secondary" :disabled="busy" @click="modal = 'cashier'">กลับไปใส่ PIN</button>
      </section>

      <form v-else-if="modal === 'changePin'" class="modal compact" @submit.prevent="changePin">
        <div class="modal-head"><div><UserRound/><span><strong>ตั้ง PIN ของคุณเอง</strong><small>PIN ที่ได้รับมาเป็นค่าเริ่มต้น ต้องเปลี่ยนก่อนเริ่มขาย</small></span></div><button type="button" @click="modal = null"><X/></button></div>
        <label>PIN ใหม่<input v-model="newPin" required type="password" inputmode="numeric" pattern="\d{4,20}" minlength="4" maxlength="20" autofocus autocomplete="new-password"></label>
        <label>ยืนยัน PIN ใหม่<input v-model="confirmPin" required type="password" inputmode="numeric" pattern="\d{4,20}" minlength="4" maxlength="20" autocomplete="new-password"></label>
        <button class="primary" :disabled="busy">{{ busy ? 'กำลังบันทึก...' : 'บันทึก PIN แล้วเริ่มงาน' }}</button>
      </form>

      <form v-else-if="modal === 'shift'" class="modal compact" @submit.prevent="openShift">
        <div class="modal-head"><div><Banknote/><span><strong>เปิดกะขาย</strong><small>{{ cashier?.name }} · {{ profile?.branchName }}</small></span></div><button type="button" @click="modal = null"><X/></button></div>
        <p v-if="carriedFloat !== null">เงินทอนยกมา {{ money(carriedFloat) }}</p>
        <label v-if="carriedFloat !== null && openingCash !== carriedFloat">เหตุผลยอดรับกะต่างจากยกมา<input v-model="openingNote" required maxlength="500"></label>
        <label>เงินทอนเริ่มต้น<input v-model.number="openingCash" type="number" min="0" step="0.01" autofocus required></label>
        <button class="primary" :disabled="busy">ยืนยันเปิดกะ</button>
        <button type="button" class="link-button" @click="printZReport">พิมพ์ Z Report กะล่าสุดซ้ำ</button>
      </form>

      <form v-else-if="modal === 'closeShift'" class="modal compact close-shift-dialog" @submit.prevent="closeShift">
        <div class="modal-head"><div><LogOut/><span><strong>ปิดกะขาย</strong><small>{{ shift?.shift_no }} · {{ pendingCount ? `มี ${pendingCount} บิลรอส่ง` : 'บิลส่งครบแล้ว' }}</small></span></div><button type="button" :disabled="busy" @click="modal = null"><X/></button></div>
        <div class="close-shift-body">
        <p v-if="shift?.cash_warning" class="error">เงินสดเกินเกณฑ์ ควรนำส่งเงิน</p>
        <p v-if="shift?.blind_count === false && shift?.expected_cash !== undefined">เงินที่ควรมี {{ money(shift.expected_cash) }}</p>
        <details><summary>ช่วยนับธนบัตร / เหรียญ</summary>
          <label v-for="(value, i) in denominationValues" :key="value">{{ money(value) }} ×
            <input v-model.number="denominationCounts[i]" type="number" min="0" max="1000000" step="1" @input="totalDenominations">
          </label>
        </details>
        <label>เงินสดที่นับได้จริง<input v-model.number="countedCash" type="number" min="0" step="0.01" autofocus required></label>
        <template v-if="shift?.default_float !== undefined">
          <label>เงินทอนเก็บในลิ้นชัก<input v-model.number="floatKept" type="number" min="0" :max="countedCash === '' ? undefined : countedCash" step="0.01" required></label>
          <button v-if="countedCash !== '' && countedCash < (shift.default_float ?? 0)" type="button" class="secondary" @click="floatKept = Number(countedCash)">เงินไม่ถึง float: เก็บเท่าที่มี</button>
          <label>เลขซองเงินนำส่ง<input v-model="depositReference" maxlength="80"></label>
          <button type="button" class="secondary" :disabled="busy || countedCash === '' || pendingCount > 0" @click="previewCloseShift">ตรวจยอดที่นับ</button>
          <div v-if="closePreview">
            <p>เงินที่ควรมี {{ money(closePreview.expected_cash) }}</p>
            <p :style="{ color: (closePreview.cash_difference ?? 0) < 0 ? '#b91c1c' : '#15803d' }">ส่วนต่าง {{ money(closePreview.cash_difference ?? 0) }}</p>
            <p>เงินนำส่ง {{ money(closePreview.deposit_amount ?? 0) }}</p>
          </div>
        </template>
        <label>นำส่งเงินระหว่างกะ<input v-model.number="cashDropAmount" type="number" min="0" step="0.01" placeholder="0.00"></label>
        <label v-if="cashDropAmount > 0">เลขอ้างอิง / ซองเงิน<input v-model="cashDropReference"></label>
        <button v-if="cashDropAmount > 0" type="button" class="secondary" :disabled="busy" @click="recordCashDrop">บันทึกนำส่งเงิน</button>
        </div>
        <div class="close-shift-footer"><button class="primary" :disabled="busy || countedCash === '' || pendingCount > 0 || (shift?.default_float !== undefined && !closePreview)">ยืนยันปิดกะ</button></div>
      </form>

      <section v-else-if="modal === 'closeApp'" class="modal compact">
        <div class="modal-head"><div><LogOut/><span><strong>ปิดโปรแกรม JET POS</strong><small>{{ shift ? `กะ ${shift.shift_no} ยังเปิดอยู่` : 'ไม่มีกะเปิด' }}</small></span></div><button type="button" @click="cancelCloseApp"><X/></button></div>
        <ul class="close-notes">
          <li v-if="cart.length">ตะกร้ามี {{ cart.length }} รายการ เก็บไว้ในเครื่อง เปิดใหม่แล้วกลับมาเหมือนเดิม</li>
          <li v-if="pendingCount">มีบิลรอส่ง ERP {{ pendingCount }} บิล จะส่งต่อเมื่อเปิดโปรแกรมใหม่</li>
          <li v-if="shift">กะยังเปิดอยู่ ถ้าเลิกงานให้กด “ปิดกะ” แทน</li>
          <li>เปิดใหม่ต้องใส่ PIN อีกครั้ง</li>
        </ul>
        <div class="close-actions">
          <button type="button" class="secondary" @click="cancelCloseApp">กลับไปขายต่อ</button>
          <button type="button" class="secondary" @click="lockScreen">ล็อกหน้าจอแทน</button>
          <button type="button" class="primary danger-action" @click="confirmCloseApp">ปิดโปรแกรม</button>
        </div>
      </section>

      <form v-else-if="modal === 'void' && selectedHistory" class="modal compact" @submit.prevent="submitVoid">
        <div class="modal-head"><div><RotateCcw/><span><strong>{{ voidMode === 'void' ? 'ยกเลิกบิล' : 'รับคืนสินค้า' }} {{ selectedHistory.receiptNo }}</strong><small>{{ voidMode === 'void' ? 'ยกเลิกได้เฉพาะบิลวันนี้ในกะที่เปิดอยู่' : 'ใบรับคืนต้องอนุมัติใน ERP ก่อนคืนเงิน' }}</small></span></div><button type="button" @click="modal = null"><X/></button></div>
        <template v-if="voidMode === 'return'">
          <label v-for="(line, index) in selectedHistory.items" :key="`ret-${index}`" class="return-line">{{ line.name_th }} (ขาย {{ line.qty }})<input v-model.number="returnQty[index]" type="number" min="0" :max="line.qty" step="any" placeholder="0"></label>
        </template>
        <label>เหตุผล<input v-model="voidReason" required maxlength="500" autofocus></label>
        <label>หัวหน้าที่อนุมัติ<select v-model.number="voidApproverId" required><option v-for="row in managers" :key="row.id" :value="row.id">{{ row.name }}</option></select></label>
        <label>PIN หัวหน้า<input v-model="voidApproverPin" type="password" inputmode="numeric" required pattern="\d{4,20}" autocomplete="off"></label>
        <button class="primary danger-action" :disabled="busy">{{ voidMode === 'void' ? 'ยืนยันยกเลิกบิล' : 'บันทึกรับคืน' }}</button>
      </form>

      <form v-else-if="modal === 'weight' && weighProduct" class="modal compact" @submit.prevent="addWeighed">
        <div class="modal-head"><div><Scale/><span><strong>{{ weighProduct.name_th }}</strong><small>{{ weighProduct.sku_code }} · ฿{{ money(weighProduct.pos_price) }} ต่อหน่วย</small></span></div><button type="button" @click="modal = null"><X/></button></div>
        <label>น้ำหนัก<input v-model.number="weighQty" type="number" min="0.001" step="0.001" inputmode="decimal" required autofocus placeholder="เช่น 0.350"></label>
        <div class="payment-total"><span>ราคา</span><strong>฿{{ money(Number(weighQty || 0) * Number(weighProduct.pos_price)) }}</strong></div>
        <button class="primary">ใส่ตะกร้า</button>
      </form>

      <form v-else-if="modal === 'holdBill'" class="modal compact" @submit.prevent="holdBill">
        <div class="modal-head"><div><PauseCircle/><span><strong>พักบิลส่วนกลาง</strong><small>เรียกต่อได้จาก POS เครื่องอื่นในสาขา</small></span></div><button type="button" @click="modal = null"><X/></button></div>
        <label>ชื่อบิล / โต๊ะ / ลูกค้า<input v-model="holdLabel" required autofocus maxlength="200"></label>
        <div class="payment-total"><span>ยอดบิล</span><strong>฿{{ money(subtotal) }}</strong></div>
        <button class="primary" :disabled="busy">ยืนยันพักบิล</button>
      </form>

      <section v-else-if="modal === 'heldBills'" class="modal">
        <div class="modal-head"><div><FolderOpen/><span><strong>บิลพักส่วนกลาง</strong><small>{{ profile?.branchName }} · {{ heldBills.length }} บิล</small></span></div><button type="button" @click="modal = null"><X/></button></div>
        <div class="held-list">
          <button v-for="bill in heldBills" :key="bill.id" type="button" @click="resumeHeldBill(bill.id)">
            <span><strong>{{ bill.label }}</strong><small>{{ bill.hold_no }} · {{ bill.cashier_name || bill.terminal_name || '-' }}</small></span>
            <b>฿{{ money(bill.total_amount) }}</b>
          </button>
          <div v-if="!heldBills.length" class="empty-state" style="min-height:160px"><FolderOpen/><strong>ไม่มีบิลที่พักไว้</strong></div>
        </div>
      </section>

      <section v-else-if="modal === 'history'" class="modal history-modal">
        <div class="modal-head"><div><History/><span><strong>ประวัติการขาย</strong><small>ข้อมูลในเครื่อง POS ย้อนหลังได้สูงสุด 90 วัน</small></span></div><button type="button" @click="modal = null"><X/></button></div>
        <div class="history-toolbar">
          <label>ช่วงเวลา<select v-model.number="historyDays" @change="refreshHistory"><option :value="30">30 วัน</option><option :value="60">60 วัน</option><option :value="90">90 วัน</option></select></label>
          <button type="button" class="secondary history-refresh" @click="refreshHistory"><RefreshCw/>รีเฟรช</button>
        </div>
        <div class="history-list">
          <button v-for="sale in saleHistoryRows" :key="sale.id" type="button" class="history-row" @click="historyReceipt(sale)">
            <span><strong>{{ sale.receiptNo }}</strong><small>{{ new Date(sale.printedAt).toLocaleString('th-TH') }} · {{ sale.items.length }} รายการ · {{ paymentName(sale.method) }}</small></span>
            <span class="history-amount"><b>฿{{ money(sale.total) }}</b><em :class="`history-${sale.status}`">{{ historyStatus(sale.status) }}</em></span>
          </button>
          <div v-if="!saleHistoryRows.length" class="empty-state history-empty"><History/><strong>ยังไม่มีประวัติในช่วงเวลานี้</strong><span>บิลที่ชำระแล้วจะถูกเก็บไว้ในเครื่องอัตโนมัติ</span></div>
        </div>
        <div v-if="selectedHistory" class="history-detail">
          <div class="history-detail-head"><strong>{{ selectedHistory.receiptNo }}</strong><span class="history-actions"><button type="button" class="secondary" @click="printLastReceipt()"><Printer/>พิมพ์ซ้ำ</button><button v-if="selectedHistory.status === 'synced'" type="button" class="secondary" @click="openVoid('return')"><RotateCcw/>คืนสินค้า</button><button v-if="selectedHistory.status === 'synced'" type="button" class="secondary danger-action" @click="openVoid('void')"><X/>ยกเลิกบิล</button></span></div>
          <div v-for="line in selectedHistory.items" :key="`${selectedHistory.id}-${line.id}-${line.scannedBarcode || ''}`" class="history-item"><span>{{ line.name_th }} × {{ line.qty }}</span><b>฿{{ money(line.qty * line.pos_price) }}</b></div>
          <div class="history-detail-total"><span>ยอดสุทธิ</span><strong>฿{{ money(selectedHistory.total) }}</strong></div>
          <small v-if="selectedHistory.status !== 'synced'" class="history-warning">บิลนี้ยังไม่ยืนยันเลขที่จาก ERP จะแสดงเป็นรายการชั่วคราวจนกว่าจะซิงก์สำเร็จ</small>
          <small v-if="selectedHistory.error && selectedHistory.status !== 'synced'" class="history-error">ERP ตอบกลับ: {{ selectedHistory.error }}</small>
          <template v-if="selectedHistory.status === 'needs_review' || selectedHistory.status === 'failed'">
            <button v-if="!discardOpen" type="button" class="secondary danger-action history-discard" @click="openDiscard">ยกเลิกบิลนี้ออกจากคิว (หัวหน้าอนุมัติ)</button>
            <form v-else class="history-fix" @submit.prevent="discardStuckSale">
              <label>หัวหน้า<select v-model.number="discardApproverId" required><option v-for="row in managers" :key="row.id" :value="row.id">{{ row.name }}</option></select></label>
              <label>PIN หัวหน้า<input v-model="discardPin" type="password" inputmode="numeric" required pattern="\d{4,20}" autocomplete="off"></label>
              <button class="primary danger-action" :disabled="busy">ยืนยันยกเลิก</button>
            </form>
          </template>
          <form v-if="selectedHistory.status !== 'synced' && selectedHistory.status !== 'void' && (selectedHistory.method === 'transfer' || selectedHistory.method === 'mixed')" class="history-fix" @submit.prevent="resendWithLast4">
            <label>เลขท้ายบัญชีผู้โอน 4 หลัก<input v-model="fixLast4" required inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="ดูจากสลิป"></label>
            <button class="primary" :disabled="busy">ส่งบิลนี้ใหม่</button>
          </form>
        </div>
      </section>

      <form v-else-if="modal === 'payment'" class="modal payment-modal" @submit.prevent="checkout">
        <div class="modal-head"><div><Banknote/><span><strong>รับชำระเงิน</strong><small>{{ cart.length }} รายการ · {{ totalQty }} ชิ้น</small></span></div><button type="button" @click="modal = null"><X/></button></div>
        <div class="payment-total"><span>ยอดที่ต้องชำระ</span><strong>฿{{ money(subtotal) }}</strong></div>
        <div class="payment-methods"><button v-for="method in (['cash','transfer','mixed','credit_card','cheque'] as PaymentMethod[])" :key="method" type="button" :class="{ active: paymentMethod === method }" @click="paymentMethod = method; if (method === 'mixed') cashReceived = 0"><Banknote v-if="method === 'cash'"/><QrCode v-else-if="method === 'transfer' || method === 'mixed'"/><CreditCard v-else-if="method === 'credit_card'"/><FileText v-else/>{{ ({cash:'เงินสด',transfer:'โอน/QR',mixed:'เงินสด+โอน',credit_card:'บัตรเครดิต',cheque:'เช็ค'} as any)[method] }}</button></div>
        <template v-if="paymentMethod === 'cash'">
          <label class="payment-field">รับเงินสด<input v-model.number="cashReceived" type="number" min="0" step="0.01" autofocus></label>
          <div class="tender-grid"><button type="button" @click="setQuickCash('exact')">รับพอดี</button><button type="button" :disabled="subtotal > 100" @click="setQuickCash(100)">100</button><button type="button" :disabled="subtotal > 500" @click="setQuickCash(500)">500</button><button type="button" :disabled="subtotal > 1000" @click="setQuickCash(1000)">1,000</button></div>
        </template>
        <template v-else-if="paymentMethod === 'mixed'">
          <label class="payment-field">เงินสด<input v-model.number="cashReceived" type="number" min="0" step="0.01" autofocus></label>
          <div class="change"><span>ยอดโอน</span><strong>฿{{ money(Math.max(0, subtotal - cashReceived)) }}</strong></div>
          <label class="payment-field">เลขอ้างอิงการโอน<input v-model="paymentRef" required></label>
        </template>
        <label v-else class="payment-field">เลขอ้างอิงการชำระ<input v-model="paymentRef" required autofocus></label>
        <label v-if="paymentMethod === 'transfer' || paymentMethod === 'mixed'" class="payment-field">เลขท้ายบัญชีผู้โอน 4 หลัก (ดูจากสลิป)<input v-model="transferLast4" required inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="เช่น 1234"></label>
        <div class="member-field">
          <label class="payment-field">สมาชิก (เบอร์โทร/รหัส)<input v-model="memberQuery" inputmode="tel" placeholder="ไม่ใส่ก็ได้" @keydown.enter.prevent="findMember" @blur="findMember"></label>
          <small v-if="member">{{ member.name }} · {{ member.member_code }}<template v-if="member.points != null"> · {{ member.points }} แต้ม</template></small>
        </div>
        <div v-if="paymentMethod === 'cash'" class="change"><span>เงินทอน</span><strong>฿{{ money(change) }}</strong></div>
        <button class="primary pay-confirm" :disabled="busy"><CheckCircle2/>{{ online ? 'ยืนยันและออกบิล' : 'ยืนยันบิลออฟไลน์' }}</button>
      </form>
    </div>

    <template v-if="printingZ && lastZReport"><section v-for="copy in zCopies" :key="copy" class="print-receipt print-z" :style="{ breakAfter: copy === zCopies[0] ? 'page' : 'auto' }" :class="receiptTemplate.paper_width === 58 ? 'paper-58' : 'paper-80'">
      <div class="print-block align-center size-large is-bold">{{ profile?.company?.name || 'JET POS' }}</div>
      <div class="print-block type-title align-center is-bold">รายงานปิดกะ (Z Report) · {{ copy }}</div>
      <div class="print-meta print-block size-small">
        <span><b>เลขกะ</b><em>{{ lastZReport.shift_no }}</em></span>
        <span><b>สาขา / เครื่อง</b><em>{{ lastZReport.branch_name || profile?.branchName }} · {{ profile?.terminalCode }}</em></span>
        <span><b>แคชเชียร์</b><em>{{ lastZReport.cashier_name }}</em></span>
        <span><b>เปิดกะ</b><em>{{ lastZReport.opened_at }}</em></span>
        <span><b>ปิดกะ</b><em>{{ lastZReport.closed_at }}</em></span>
        <span><b>จำนวนบิล</b><em>{{ lastZReport.receipt_count }}</em></span>
      </div>
      <div class="print-divider"></div>
      <div class="print-meta print-block size-small">
        <span><b>เงินสด</b><em>{{ money(lastZReport.cash_sales) }}</em></span>
        <span><b>โอน / QR</b><em>{{ money(lastZReport.transfer_sales) }}</em></span>
        <span><b>บัตรเครดิต</b><em>{{ money(lastZReport.card_sales) }}</em></span>
        <span><b>เช็ค</b><em>{{ money(lastZReport.cheque_sales) }}</em></span>
        <span><b>รวมยอดขาย</b><em>{{ money(lastZReport.cash_sales + lastZReport.transfer_sales + lastZReport.card_sales + lastZReport.cheque_sales) }}</em></span>
      </div>
      <div class="print-divider"></div>
      <div class="print-meta print-block size-small">
        <span><b>ยกเลิก / รับคืน</b><em>{{ lastZReport.void_count ?? '-' }} / {{ lastZReport.return_count ?? '-' }}</em></span>
        <span><b>ส่วนลดรวม</b><em>{{ optionalMoney(lastZReport.discount_total) }}</em></span>
        <span><b>เงินทอนยกมา</b><em>{{ optionalMoney(lastZReport.carried_float) }}</em></span>
        <span><b>ส่วนต่างตอนรับกะ</b><em>{{ optionalMoney(lastZReport.opening_variance) }}</em></span>
        <span><b>เหตุผลรับกะ</b><em>{{ lastZReport.opening_note || '-' }}</em></span>
        <span><b>เงินนำส่งปิดกะ</b><em>{{ optionalMoney(lastZReport.deposit_amount) }}</em></span>
        <span><b>เลขซอง</b><em>{{ lastZReport.deposit_reference || '-' }}</em></span>
        <span><b>เงินทอนเก็บในลิ้นชัก</b><em>{{ optionalMoney(lastZReport.float_kept) }}</em></span>
        <span><b>เงินทอนตั้งต้น</b><em>{{ money(lastZReport.opening_cash) }}</em></span>
        <span><b>เงินเข้าลิ้นชัก</b><em>{{ money(lastZReport.cash_in) }}</em></span>
        <span><b>นำส่งเงิน</b><em>-{{ money(lastZReport.cash_drops) }}</em></span>
        <span><b>เบิกจ่าย</b><em>-{{ money(lastZReport.cash_payouts) }}</em></span>
        <span><b>เงินสดที่ควรมี</b><em>{{ money(lastZReport.expected_cash) }}</em></span>
        <span><b>เงินสดนับจริง</b><em>{{ money(lastZReport.counted_cash ?? 0) }}</em></span>
        <span class="is-bold"><b>เงินขาด / เกิน</b><em>{{ money(lastZReport.cash_difference ?? 0) }}</em></span>
      </div>
      <div class="print-divider"></div>
      <div class="print-block align-center size-small">ผู้ส่ง ........................ &nbsp; ผู้รับ (หัวหน้า) ........................</div>
    </section></template>
    <section v-else-if="lastReceipt" class="print-receipt" :class="receiptTemplate.paper_width === 58 ? 'paper-58' : 'paper-80'">
      <div v-for="block in receiptTemplate.blocks" :key="block.id" class="print-block" :class="[receiptBlockClasses(block), `type-${block.type}`]">
        <template v-if="block.type === 'logo'">
          <img v-if="profile?.company.logo_url" class="print-logo" :src="profile.company.logo_url" alt="">
          <strong v-else class="print-brand">{{ profile?.company?.name || 'JET POS' }}</strong>
        </template>
        <template v-else-if="block.type === 'company'">
          <strong>{{ profile?.company.name || 'MPM Food Group' }}</strong>
          <span>{{ profile?.company.address }}</span>
          <span>เลขประจำตัวผู้เสียภาษี {{ profile?.company.tax_id || '-' }}</span>
          <span v-if="profile?.company.phone">โทร {{ profile.company.phone }}</span>
        </template>
        <template v-else-if="block.type === 'title'">
          {{ lastReceipt.provisional ? 'ใบรับรายการชั่วคราว' : 'ใบเสร็จรับเงิน/ใบกำกับภาษีอย่างย่อ' }}
          <small v-if="printCopyLabel" class="print-copy">{{ printCopyLabel }}</small>
        </template>
        <template v-else-if="block.type === 'meta'">
          <div class="print-meta"><span><b>เลขที่</b><em>{{ lastReceipt.no }}</em></span><span><b>วันที่</b><em>{{ lastReceipt.printedAt }}</em></span><span><b>สาขา</b><em>{{ profile?.branchName }}</em></span><span><b>แคชเชียร์</b><em>{{ cashier?.name }}</em></span></div>
        </template>
        <div v-else-if="block.type === 'divider'" class="print-divider"></div>
        <table v-else-if="block.type === 'items'" class="print-items">
          <thead><tr><th>รายการ</th><th>จำนวน</th><th>รวม</th></tr></thead>
          <tbody><tr v-for="line in lastReceipt.items" :key="`${block.id}-${line.id}-${line.scannedBarcode || ''}`"><td><span v-if="block.show_sku">{{ line.sku_code }} </span>{{ line.name_th }}<small v-if="block.show_unit_price">{{ money(line.pos_price) }} / หน่วย</small></td><td>{{ line.qty }}</td><td>{{ money(line.qty * line.pos_price) }}</td></tr></tbody>
        </table>
        <div v-else-if="block.type === 'tax-summary'" class="print-row"><span>มูลค่าก่อนภาษี / VAT {{ profile?.vatRate || 7 }}%</span><b>{{ money(lastReceipt.total - receiptVat) }} / {{ money(receiptVat) }}</b></div>
        <div v-else-if="block.type === 'totals' && lastReceipt.discount" class="print-row"><span>ส่วนลดท้ายบิล</span><b>-฿{{ money(lastReceipt.discount) }}</b></div>
        <div v-if="block.type === 'totals'" class="print-row print-total"><span>รวมสุทธิ</span><strong>{{ money(lastReceipt.total) }} บาท</strong></div>
        <div v-else-if="block.type === 'payment'" class="print-payment"><span>ชำระโดย {{ paymentName(lastReceipt.method) }}</span><span v-if="lastReceipt.method === 'cash'">รับเงิน {{ money(lastReceipt.paid) }} · เงินทอน {{ money(lastReceipt.change) }}</span></div>
        <template v-else-if="block.type === 'custom' || block.type === 'footer'">{{ block.text }}</template>
      </div>
      <div v-if="lastReceipt.provisional" class="provisional-note">รายการนี้รอส่งขึ้น ERP เอกสารภาษีฉบับจริงจะออกเมื่อเชื่อมต่อสำเร็จ</div>
    </section>
  </main>
</template>
