<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import { AlertTriangle, Banknote, CheckCircle2, ChevronRight, CircleHelp, Cloud, CloudOff, CreditCard, FileText, FolderOpen, History, LogOut, Minus, PackageSearch, PauseCircle, Plus, Printer, QrCode, ReceiptText, RefreshCw, ScanLine, Search, Settings, ShoppingCart, Trash2, UserRound, Wifi, X } from 'lucide-vue-next'
import { check } from '@tauri-apps/plugin-updater'
import { invoke, isTauri } from '@tauri-apps/api/core'
import { api, connect, PosUnreachableError, setServerUrl } from './lib/api'
import { closeLocalDb, enqueue, loadOfflineCashiers, loadOfflineCashiersByPin, loadProducts, loadProfile, loadPromotions, loadSession, localDbHealth, markQueue, queueItems, replaceOfflineCashiers, replaceProducts, replacePromotions, saleHistory, saveOfflineCredential, saveProfile, saveSaleHistory, saveSession, type LocalDbHealth } from './lib/db'
import { productAtSaleTime } from './lib/catalog'
import { resolveScan, type BarcodeType } from './lib/barcode'
import { priceCart } from './lib/pricing'
import { syncCheckoutQueue } from './lib/sync'
import type { CartLine, Cashier, DeviceProfile, HeldBill, LocalSaleHistory, LocalStorageStatus, PaymentMethod, Product, QtyPromotion, QueueItem, ReceiptBlock, ReceiptTemplate, Shift } from './lib/types'

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
const error = ref('')
const notice = ref('')
const busy = ref(false)
const modal = ref<'cashier' | 'selectCashier' | 'changePin' | 'shift' | 'closeShift' | 'payment' | 'settings' | 'holdBill' | 'heldBills' | 'history' | 'guide' | null>(null)
const setupUrl = ref('http://27.254.143.219')
const setupToken = ref('')
const setupError = ref('')   // ค้างไว้จนกว่าจะลองเชื่อมใหม่ ต่างจาก toast ที่หายใน 6 วิ
const cashierPin = ref('')
const pendingCashier = ref<Cashier | null>(null)
const cashierChoices = ref<Cashier[]>([])
const offlineSelection = ref(false)
const newPin = ref('')
const confirmPin = ref('')
const openingCash = ref(0)
const countedCash = ref(0)
const paymentMethod = ref<PaymentMethod>('cash')
const cashReceived = ref(0)
const paymentRef = ref('')
const lastReceipt = ref<{ no: string; items: CartLine[]; total: number; method: PaymentMethod; paid: number; change: number; printedAt: string; provisional: boolean } | null>(null)
const heldBills = ref<HeldBill[]>([])
const holdLabel = ref('')
const cashDropAmount = ref(0)
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

const filteredProducts = computed(() => {
  catalogClock.value
  const q = search.value.trim().toLocaleLowerCase('th')
  const live = products.value.map((product) => productAtSaleTime(product, new Date()))
  if (!q) return live.slice(0, 80)
  return live.filter((p) => p.sku_code.toLowerCase().includes(q) || p.name_th.toLocaleLowerCase('th').includes(q) || p.barcodes?.some((b) => b.barcode.includes(q))).slice(0, 80)
})
const storageReady = computed(() => !isTauri() || storageStatus.value?.location === 'd-drive')
const connectionLabel = computed(() => {
  if (!profile.value) return 'ยังไม่เชื่อม ERP'
  if (syncing.value) return 'กำลังซิงก์'
  if (!online.value) return 'เชื่อม ERP ไม่ได้'
  // ERP ตอบ ping แล้ว แต่ดึง/บันทึกข้อมูลไม่ครบ — คนละปัญหากับเน็ตหลุด
  return catalogSyncError.value ? 'ซิงก์ข้อมูลไม่ครบ' : 'ออนไลน์'
})
const connectionClass = computed(() => profile.value && online.value ? 'online' : 'offline')
const pricing = computed(() => priceCart(cart.value, promotions.value, profile.value?.vatRate || 7))
const subtotal = computed(() => pricing.value.total)
const totalQty = computed(() => cart.value.reduce((sum, line) => sum + Number(line.qty), 0))
const vat = computed(() => pricing.value.vat)
const change = computed(() => Math.max(0, cashReceived.value - subtotal.value))
const todayLocalSales = computed(() => {
  const today = new Date().toLocaleDateString('en-CA')
  return recentSales.value.filter((sale) => sale.status !== 'failed' && new Date(sale.printedAt).toLocaleDateString('en-CA') === today)
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

function money(value: number) { return new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value) }
function printLastReceipt() { window.print() }
function flash(message: string) { notice.value = message; window.setTimeout(() => { notice.value = '' }, 3500) }
function errorText(value: unknown) { return value instanceof Error ? value.message : String(value) }
function showError(value: unknown) { error.value = errorText(value); window.setTimeout(() => { error.value = '' }, 6000) }
function receiptBlockClasses(block: ReceiptBlock) { return [`align-${block.align}`, `size-${block.size}`, { 'is-bold': block.bold }] }
function paymentName(method: PaymentMethod) { return ({ cash: 'เงินสด', transfer: 'โอน/QR', credit_card: 'บัตรเครดิต', cheque: 'เช็ค', mixed: 'เงินสด+โอน' })[method] }
function historyStatus(status: LocalSaleHistory['status']) { return ({ pending: 'รอส่ง', syncing: 'กำลังส่ง', synced: 'ส่งแล้ว', failed: 'ส่งไม่สำเร็จ' })[status] }
function historyReceipt(sale: LocalSaleHistory) {
  lastReceipt.value = { no: sale.receiptNo, items: sale.items, total: sale.total, method: sale.method, paid: sale.paid, change: sale.change, printedAt: new Date(sale.printedAt).toLocaleString('th-TH'), provisional: sale.status !== 'synced' }
  selectedHistory.value = sale
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
  const existing = cart.value.find((line) => line.id === product.id && line.scannedBarcode === barcode)
  if (existing) existing.qty = Number((existing.qty + 1).toFixed(4))
  else cart.value.push({ ...product, qty: 1, scannedBarcode: barcode, scannedBarcodeType: barcodeType })
  search.value = ''
  nextTick(() => scanner.value?.focus())
}

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

  if (resolved.kind === 'not-found') return flash(`ไม่พบรหัส ${code}`)
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
  pendingCount.value = (await queueItems()).filter((item) => item.status !== 'synced').length
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

async function backupLocalDb() {
  if (!isTauri()) return showError('ฟังก์ชัน Backup POS ใช้ได้ในโปรแกรม Windows เท่านั้น')
  localBackupBusy.value = true
  try {
    await closeLocalDb()
    const path = await invoke<string>('backup_local_database')
    flash(`สำรองข้อมูล POS แล้ว: ${path}`)
    await inspectLocalDb()
  } catch (e) {
    showError(`สำรองข้อมูลไม่สำเร็จ: ${e instanceof Error ? e.message : String(e)}`)
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
    const connected = await connect(setupUrl.value, setupToken.value.trim())
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
  } catch (e) { showError(e) } finally { busy.value = false }
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

async function startCashierSession(next: Cashier) {
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

async function openShift() {
  if (!cashier.value || !profile.value) return
  busy.value = true
  try {
    shift.value = await api.openShift(profile.value.branchId, cashier.value.id, openingCash.value)
    await saveSession(cashier.value, shift.value)
    modal.value = null
    flash(`เปิดกะ ${shift.value.shift_no} แล้ว`)
  } catch (e) { showError(e) } finally { busy.value = false }
}

async function closeShift() {
  if (!shift.value) return
  if (pendingCount.value) { showError('ต้องส่งบิลค้างขึ้น ERP ให้ครบก่อนปิดกะ'); return }
  busy.value = true
  try {
    await api.closeShift(shift.value.id, countedCash.value)
    shift.value = null
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
    cart.value = bill.cart || []
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
    countedCash.value = shift.value.expected_cash
    cashDropAmount.value = 0
    cashDropReference.value = ''
    flash(result.message)
  } catch (e) { showError(e) } finally { busy.value = false }
}

function openPayment() {
  if (!storageReady.value) { showError('ยังไม่สามารถรับชำระได้: ต้องเตรียมฐานข้อมูลบนไดรฟ์ D: ให้พร้อมก่อน'); return }
  if (!cashier.value) { modal.value = 'cashier'; return }
  if (!shift.value) { modal.value = 'shift'; return }
  repriceCartForCurrentSchedule()
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
  const id = `${profile.value.terminalCode || 'POS'}:SALE:${crypto.randomUUID()}`
  const soldItems = cart.value.map((line) => ({ ...line }))
  const soldTotal = subtotal.value
  const soldChange = change.value
  const payload = {
    branch_id: profile.value.branchId,
    shift_id: shift.value.id,
    cashier_id: cashier.value.id,
    method: paymentMethod.value,
    payment_ref: paymentRef.value || undefined,
    payment_confirmed: paymentMethod.value !== 'cash',
    cash_received: paymentMethod.value === 'cash' ? cashReceived.value : undefined,
    change_amount: paymentMethod.value === 'cash' ? change.value : undefined,
    vat_mode: 'included' as const,
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
    modal.value = null
    await refreshQueue()
    let receiptNo = id.split(':').pop()!.slice(0, 8).toUpperCase()
    let provisional = true
    if (navigator.onLine) {
      await syncCheckoutQueue(refreshQueue)
      const result = (await queueItems()).find((item) => item.id === id)
      if (result?.status === 'synced') {
        receiptNo = result.receiptNo || receiptNo
        provisional = false
        flash(`บันทึกบิล ${result.receiptNo || ''} แล้ว`)
      }
      else flash('เก็บบิลในเครื่องแล้ว ระบบจะส่งซ้ำอัตโนมัติ')
    } else flash('ออฟไลน์: เก็บบิลในเครื่องแล้ว ระบบจะส่งเมื่ออินเทอร์เน็ตกลับมา')
    lastReceipt.value = { no: receiptNo, items: soldItems, total: soldTotal, method: paymentMethod.value, paid: cashReceived.value, change: soldChange, printedAt: new Date().toLocaleString('th-TH'), provisional }
    if (profile.value.hardwareProfile?.auto_print) {
      window.setTimeout(() => printLastReceipt(), 150)
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
  if (import.meta.env.DEV && !isTauri()) {
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
  modal.value = cashier.value && shift.value ? null : 'cashier'
  void syncAll()
  void checkUpdate()
}

function networkUp() { online.value = true; void syncAll() }
function networkDown() { online.value = false }
function handleShortcut(event: KeyboardEvent) {
  if (event.key === 'F8' && !modal.value) {
    event.preventDefault()
    scanner.value?.focus()
  }
  if (event.key === 'F10' && !modal.value && cart.value.length) {
    event.preventDefault()
    openPayment()
  }
  // ตั้งค่าครั้งแรกกับตั้ง PIN ใหม่ กด Escape ข้ามไม่ได้ ต้องทำให้จบก่อน
  const locked = modal.value === 'changePin' || (modal.value === 'settings' && !profile.value)
  if (event.key === 'Escape' && modal.value && !locked) {
    modal.value = null
    nextTick(() => scanner.value?.focus())
  }
}
onMounted(() => {
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
    <header class="topbar">
      <div class="brand"><span class="brand-mark">P</span><div><strong>POPSTAR</strong><small>POINT OF SALE</small></div></div>
      <div class="terminal-context">
        <div><span>สาขา / เครื่อง</span><strong>{{ profile?.branchName || 'ยังไม่ได้ตั้งค่าเครื่อง' }}</strong><small>{{ profile?.terminalCode || 'POS' }}</small></div>
        <div><span>กะขาย</span><strong>{{ shift?.shift_no || 'ยังไม่เปิดกะ' }}</strong><small>{{ cashier?.name || 'ยังไม่เข้าแคชเชียร์' }}</small></div>
      </div>
      <div class="top-actions">
        <button class="status" :class="connectionClass" :title="catalogSyncError || 'ตรวจการเชื่อมต่อ ERP และซิงก์ข้อมูล'" @click="syncAll"><Wifi v-if="profile && online"/><CloudOff v-else/><span>{{ connectionLabel }}</span></button>
        <button class="icon-button" title="ซิงก์ข้อมูล" @click="syncAll"><RefreshCw :class="{ spin: syncing }"/></button>
        <button class="icon-button" title="คู่มือการใช้งาน POS" @click="openGuide"><CircleHelp/></button>
        <button class="icon-button" title="ประวัติการขายย้อนหลัง" @click="openHistory"><History/></button>
        <button v-if="lastReceipt" class="icon-button" title="พิมพ์บิลล่าสุด" @click="printLastReceipt"><Printer/></button>
        <button class="icon-button" title="เรียกบิลพักส่วนกลาง" @click="openHeldBills"><FolderOpen/></button>
        <button v-if="shift" class="icon-button" title="ปิดกะขาย" @click="countedCash = shift.expected_cash; modal = 'closeShift'"><LogOut/></button>
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
        <div class="cart-lines">
          <div v-for="(line, index) in cart" :key="`${line.id}-${line.scannedBarcode || ''}`" class="cart-line">
            <span class="line-seq">{{ index + 1 }}</span>
            <div class="line-info"><strong>{{ line.name_th }}</strong><span>{{ line.sku_code }} · ฿{{ money(line.pos_price) }}</span></div>
            <div class="qty-control"><button title="ลดจำนวน" @click="line.qty <= 1 ? cart.splice(index, 1) : line.qty--"><Minus/></button><input v-model.number="line.qty" type="number" min="0.001" step="1"><button title="เพิ่มจำนวน" @click="line.qty++"><Plus/></button></div>
            <b>฿{{ money(line.pos_price * line.qty) }}</b>
            <button class="delete" title="ลบรายการ" @click="cart.splice(index, 1)"><Trash2/></button>
          </div>
          <div v-if="!cart.length" class="cart-empty"><span><ShoppingCart/></span><strong>ยังไม่มีรายการขาย</strong><small>สแกนสินค้าเพื่อเริ่มบิล</small></div>
        </div>
        <div class="totals"><div><span>ยอดก่อนภาษี</span><b>฿{{ money(subtotal - vat) }}</b></div><div><span>VAT {{ profile?.vatRate || 7 }}%</span><b>฿{{ money(vat) }}</b></div><div class="grand"><span>ยอดสุทธิ</span><b>฿{{ money(subtotal) }}</b></div></div>
        <button class="pay-button" :disabled="!cart.length || busy || !storageReady" @click="openPayment"><Banknote/><span><small>{{ storageReady ? 'รับชำระ' : 'รอพื้นที่ข้อมูล' }}</small><strong>{{ storageReady ? 'ชำระเงิน' : 'เตรียมไดรฟ์ D:' }}</strong></span><ChevronRight/></button>
        <div class="queue-bar" :class="{ warning: pendingCount }"><Cloud v-if="pendingCount"/><CheckCircle2 v-else/><span>{{ pendingCount ? `รอส่งขึ้น ERP ${pendingCount} บิล` : 'บิลทั้งหมดส่งขึ้น ERP แล้ว' }}</span></div>
      </aside>
    </section>

    <transition name="toast"><div v-if="notice" class="toast success"><CheckCircle2/>{{ notice }}</div></transition>
    <transition name="toast"><div v-if="error" class="toast error"><AlertTriangle/>{{ error }}<button @click="error = ''"><X/></button></div></transition>

    <div v-if="modal" class="modal-backdrop">
      <section v-if="modal === 'guide'" class="modal guide-modal">
        <div class="modal-head"><div><CircleHelp/><span><strong>คู่มือ POPSTAR POS</strong><small>ขั้นตอนใช้งานและแก้ปัญหาเบื้องต้น</small></span></div><button type="button" @click="modal = null"><X/></button></div>
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
        <div class="modal-head"><div><Settings/><span><strong>ตั้งค่าเครื่อง POS</strong><small>เชื่อมเครื่องนี้กับ ERP เพียงครั้งแรก</small></span></div><button v-if="profile" type="button" @click="modal = null"><X/></button></div>
        <label>ที่อยู่เซิร์ฟเวอร์<input v-model="setupUrl" required placeholder="http://27.254.143.219"></label>
        <small class="setup-hint">ใส่ที่อยู่ตามที่ผู้ดูแลระบบแจ้งเท่านั้น (ปัจจุบันคือ http://27.254.143.219) — แอปรุ่นนี้อนุญาตให้ต่อได้เฉพาะที่อยู่นั้น ยังไม่เปิด HTTPS</small>
        <p v-if="setupError" class="setup-error">{{ setupError }}</p>
        <label>Device Token<textarea v-model="setupToken" required rows="3" placeholder="วาง Token จาก ERP > ตั้งค่า > ดาวน์โหลด POS"></textarea></label>
        <small class="setup-hint">Token ใช้ผูกเครื่องกับสาขา ไม่ควรนำไปใส่ใน GitHub หรือส่งต่อให้พนักงาน</small>
        <button class="primary" :disabled="busy">{{ busy ? 'กำลังตรวจสอบ...' : 'เชื่อมต่อเครื่อง' }}</button>
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
          <div class="diagnostic-actions"><button type="button" class="secondary" :disabled="localBackupBusy" @click="backupLocalDb">{{ localBackupBusy ? 'กำลังสำรอง...' : 'สำรอง SQLite' }}</button><button type="button" class="secondary danger-action" :disabled="localRestoreBusy || !localHealth || localHealth.pending > 0 || localHealth.failed > 0" @click="restoreLocalDb">{{ localRestoreBusy ? 'กำลังกู้คืน...' : 'กู้คืน Backup ล่าสุด' }}</button></div>
          <small class="diagnostic-note">Restore จะสร้างไฟล์ pre-restore ก่อนเสมอ และล็อกเมื่อมีบิลค้าง/ล้มเหลว</small>
        </div>
      </form>

      <form v-else-if="modal === 'cashier'" class="modal compact" @submit.prevent="loginCashier">
        <div class="modal-head"><div><UserRound/><span><strong>เข้าใช้งานแคชเชียร์</strong><small>{{ profile?.branchName }}</small></span></div></div>
        <template v-if="profile?.cashierLoginMode === 'selection'">
          <small class="setup-hint">เครื่องนี้ผูกสาขา {{ profile?.branchName }} แล้ว เลือกชื่อผู้ปฏิบัติงานเพื่อเริ่มขาย</small>
          <button class="primary" :disabled="busy">เลือกชื่อพนักงาน</button>
        </template>
        <template v-else>
          <label>PIN ของพนักงาน<input v-model="cashierPin" required autofocus type="password" inputmode="numeric" pattern="\d{4,20}" minlength="4" maxlength="20" autocomplete="current-password"></label>
          <small class="setup-hint">เครื่องนี้ผูกสาขา {{ profile?.branchName }} แล้ว ระบบจะระบุผู้ใช้จาก PIN โดยอัตโนมัติ</small>
          <button class="primary" :disabled="busy">เข้าใช้งาน</button>
        </template>
      </form>

      <section v-else-if="modal === 'selectCashier'" class="modal compact">
        <div class="modal-head"><div><UserRound/><span><strong>เลือกชื่อพนักงาน</strong><small>PIN เริ่มต้นนี้ใช้ร่วมกัน กรุณาเลือกชื่อผู้ปฏิบัติงานจริง</small></span></div></div>
        <div class="held-list">
          <button v-for="choice in cashierChoices" :key="choice.id" type="button" :disabled="busy" @click="selectCashier(choice)">
            <span><strong>{{ choice.name }}</strong><small>{{ choice.code }}</small></span><ChevronRight/>
          </button>
        </div>
        <button type="button" class="secondary" :disabled="busy" @click="modal = 'cashier'">กลับไปใส่ PIN</button>
      </section>

      <form v-else-if="modal === 'changePin'" class="modal compact" @submit.prevent="changePin">
        <div class="modal-head"><div><UserRound/><span><strong>ตั้ง PIN ของคุณเอง</strong><small>PIN ที่ได้รับมาเป็นค่าเริ่มต้น ต้องเปลี่ยนก่อนเริ่มขาย</small></span></div></div>
        <label>PIN ใหม่<input v-model="newPin" required type="password" inputmode="numeric" pattern="\d{4,20}" minlength="4" maxlength="20" autofocus autocomplete="new-password"></label>
        <label>ยืนยัน PIN ใหม่<input v-model="confirmPin" required type="password" inputmode="numeric" pattern="\d{4,20}" minlength="4" maxlength="20" autocomplete="new-password"></label>
        <button class="primary" :disabled="busy">{{ busy ? 'กำลังบันทึก...' : 'บันทึก PIN แล้วเริ่มงาน' }}</button>
      </form>

      <form v-else-if="modal === 'shift'" class="modal compact" @submit.prevent="openShift">
        <div class="modal-head"><div><Banknote/><span><strong>เปิดกะขาย</strong><small>{{ cashier?.name }} · {{ profile?.branchName }}</small></span></div></div>
        <label>เงินทอนเริ่มต้น<input v-model.number="openingCash" type="number" min="0" step="0.01" autofocus required></label>
        <button class="primary" :disabled="busy">ยืนยันเปิดกะ</button>
      </form>

      <form v-else-if="modal === 'closeShift'" class="modal compact" @submit.prevent="closeShift">
        <div class="modal-head"><div><LogOut/><span><strong>ปิดกะขาย</strong><small>{{ shift?.shift_no }} · {{ pendingCount ? `มี ${pendingCount} บิลรอส่ง` : 'บิลส่งครบแล้ว' }}</small></span></div><button type="button" @click="modal = null"><X/></button></div>
        <label>เงินสดที่นับได้จริง<input v-model.number="countedCash" type="number" min="0" step="0.01" autofocus required></label>
        <label>นำส่งเงินระหว่างกะ<input v-model.number="cashDropAmount" type="number" min="0" step="0.01" placeholder="0.00"></label>
        <label v-if="cashDropAmount > 0">เลขอ้างอิง / ซองเงิน<input v-model="cashDropReference"></label>
        <button v-if="cashDropAmount > 0" type="button" class="secondary" :disabled="busy" @click="recordCashDrop">บันทึกนำส่งเงิน</button>
        <button class="primary" :disabled="busy || pendingCount > 0">ยืนยันปิดกะ</button>
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
          <div class="history-detail-head"><strong>{{ selectedHistory.receiptNo }}</strong><button type="button" class="secondary" @click="printLastReceipt"><Printer/>พิมพ์ซ้ำ</button></div>
          <div v-for="line in selectedHistory.items" :key="`${selectedHistory.id}-${line.id}-${line.scannedBarcode || ''}`" class="history-item"><span>{{ line.name_th }} × {{ line.qty }}</span><b>฿{{ money(line.qty * line.pos_price) }}</b></div>
          <div class="history-detail-total"><span>ยอดสุทธิ</span><strong>฿{{ money(selectedHistory.total) }}</strong></div>
          <small v-if="selectedHistory.status !== 'synced'" class="history-warning">บิลนี้ยังไม่ยืนยันเลขที่จาก ERP จะแสดงเป็นรายการชั่วคราวจนกว่าจะซิงก์สำเร็จ</small>
        </div>
      </section>

      <form v-else-if="modal === 'payment'" class="modal payment-modal" @submit.prevent="checkout">
        <div class="modal-head"><div><Banknote/><span><strong>รับชำระเงิน</strong><small>{{ cart.length }} รายการ · {{ totalQty }} ชิ้น</small></span></div><button type="button" @click="modal = null"><X/></button></div>
        <div class="payment-total"><span>ยอดที่ต้องชำระ</span><strong>฿{{ money(subtotal) }}</strong></div>
        <div class="payment-methods"><button v-for="method in (['cash','transfer','credit_card','cheque'] as PaymentMethod[])" :key="method" type="button" :class="{ active: paymentMethod === method }" @click="paymentMethod = method"><Banknote v-if="method === 'cash'"/><QrCode v-else-if="method === 'transfer'"/><CreditCard v-else-if="method === 'credit_card'"/><FileText v-else/>{{ ({cash:'เงินสด',transfer:'โอน/QR',credit_card:'บัตรเครดิต',cheque:'เช็ค'} as any)[method] }}</button></div>
        <template v-if="paymentMethod === 'cash'">
          <label class="payment-field">รับเงินสด<input v-model.number="cashReceived" type="number" min="0" step="0.01" autofocus></label>
          <div class="tender-grid"><button type="button" @click="setQuickCash('exact')">รับพอดี</button><button type="button" :disabled="subtotal > 100" @click="setQuickCash(100)">100</button><button type="button" :disabled="subtotal > 500" @click="setQuickCash(500)">500</button><button type="button" :disabled="subtotal > 1000" @click="setQuickCash(1000)">1,000</button></div>
        </template>
        <label v-else class="payment-field">เลขอ้างอิงการชำระ<input v-model="paymentRef" required autofocus></label>
        <div v-if="paymentMethod === 'cash'" class="change"><span>เงินทอน</span><strong>฿{{ money(change) }}</strong></div>
        <button class="primary pay-confirm" :disabled="busy"><CheckCircle2/>{{ online ? 'ยืนยันและออกบิล' : 'ยืนยันบิลออฟไลน์' }}</button>
      </form>
    </div>

    <section v-if="lastReceipt" class="print-receipt" :class="receiptTemplate.paper_width === 58 ? 'paper-58' : 'paper-80'">
      <div v-for="block in receiptTemplate.blocks" :key="block.id" class="print-block" :class="[receiptBlockClasses(block), `type-${block.type}`]">
        <template v-if="block.type === 'logo'">
          <img v-if="profile?.company.logo_url" class="print-logo" :src="profile.company.logo_url" alt="">
          <strong v-else class="print-brand">POPSTAR</strong>
        </template>
        <template v-else-if="block.type === 'company'">
          <strong>{{ profile?.company.name || 'POPSTAR SHOP' }}</strong>
          <span>{{ profile?.company.address }}</span>
          <span>เลขประจำตัวผู้เสียภาษี {{ profile?.company.tax_id || '-' }}</span>
          <span v-if="profile?.company.phone">โทร {{ profile.company.phone }}</span>
        </template>
        <template v-else-if="block.type === 'title'">
          {{ lastReceipt.provisional ? 'ใบรับรายการชั่วคราว' : 'ใบเสร็จรับเงิน/ใบกำกับภาษีอย่างย่อ' }}
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
        <div v-else-if="block.type === 'totals'" class="print-row print-total"><span>รวมสุทธิ</span><strong>{{ money(lastReceipt.total) }} บาท</strong></div>
        <div v-else-if="block.type === 'payment'" class="print-payment"><span>ชำระโดย {{ paymentName(lastReceipt.method) }}</span><span v-if="lastReceipt.method === 'cash'">รับเงิน {{ money(lastReceipt.paid) }} · เงินทอน {{ money(lastReceipt.change) }}</span></div>
        <template v-else-if="block.type === 'custom' || block.type === 'footer'">{{ block.text }}</template>
      </div>
      <div v-if="lastReceipt.provisional" class="provisional-note">รายการนี้รอส่งขึ้น ERP เอกสารภาษีฉบับจริงจะออกเมื่อเชื่อมต่อสำเร็จ</div>
    </section>
  </main>
</template>
