<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import { AlertTriangle, Banknote, CheckCircle2, Cloud, CloudOff, LogOut, Minus, PackageSearch, Plus, Printer, RefreshCw, Search, Settings, ShoppingCart, Trash2, UserRound, Wifi, X } from 'lucide-vue-next'
import { check } from '@tauri-apps/plugin-updater'
import { isTauri } from '@tauri-apps/api/core'
import { api, connect, setServerUrl } from './lib/api'
import { enqueue, loadProducts, loadProfile, loadSession, markQueue, queueItems, replaceProducts, saveProfile, saveSession } from './lib/db'
import { syncCheckoutQueue } from './lib/sync'
import type { CartLine, Cashier, DeviceProfile, PaymentMethod, Product, QueueItem, Shift } from './lib/types'

const profile = ref<DeviceProfile | null>(null)
const products = ref<Product[]>([])
const cart = ref<CartLine[]>([])
const cashier = ref<Cashier | null>(null)
const shift = ref<Shift | null>(null)
const search = ref('')
const scanner = ref<HTMLInputElement | null>(null)
const online = ref(navigator.onLine)
const syncing = ref(false)
const pendingCount = ref(0)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const modal = ref<'cashier' | 'shift' | 'closeShift' | 'payment' | 'settings' | null>(null)
const setupUrl = ref('http://27.254.143.219')
const setupToken = ref('')
const cashierCode = ref('')
const cashierPin = ref('')
const openingCash = ref(0)
const countedCash = ref(0)
const paymentMethod = ref<PaymentMethod>('cash')
const cashReceived = ref(0)
const paymentRef = ref('')
const lastReceipt = ref<{ no: string; items: CartLine[]; total: number; method: PaymentMethod; paid: number; change: number; printedAt: string; provisional: boolean } | null>(null)

const filteredProducts = computed(() => {
  const q = search.value.trim().toLocaleLowerCase('th')
  if (!q) return products.value.slice(0, 80)
  return products.value.filter((p) => p.sku_code.toLowerCase().includes(q) || p.name_th.toLocaleLowerCase('th').includes(q) || p.barcodes?.some((b) => b.barcode.includes(q))).slice(0, 80)
})
const subtotal = computed(() => cart.value.reduce((sum, line) => sum + Number(line.pos_price) * line.qty, 0))
const vat = computed(() => subtotal.value * ((profile.value?.vatRate || 7) / (100 + (profile.value?.vatRate || 7))))
const change = computed(() => Math.max(0, cashReceived.value - subtotal.value))

function money(value: number) { return new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value) }
function printLastReceipt() { window.print() }
function flash(message: string) { notice.value = message; window.setTimeout(() => { notice.value = '' }, 3500) }
function showError(value: unknown) { error.value = value instanceof Error ? value.message : String(value); window.setTimeout(() => { error.value = '' }, 6000) }

function addProduct(product: Product, barcode?: string) {
  const existing = cart.value.find((line) => line.id === product.id && line.scannedBarcode === barcode)
  if (existing) existing.qty = Number((existing.qty + 1).toFixed(4))
  else cart.value.push({ ...product, qty: 1, scannedBarcode: barcode })
  search.value = ''
  nextTick(() => scanner.value?.focus())
}

// ป้ายเครื่องชั่ง: PLU 6 หลัก + ราคารวม (สตางค์) — ตรงกับ SCALE_BARCODE_RULES ของ POS เว็บ
// 13 หลักต้องตรวจ check digit เพราะ 800-839 เป็นรหัสประเทศ EAN ของอิตาลีด้วย
function decodeScaleLabel(code: string): { plu: string; price: number } | null {
  const long = /^(80[01]\d{3})(\d{6})(\d)$/.exec(code)
  if (long) {
    const body = long[1] + long[2]
    let sum = 0
    for (let i = 0; i < body.length; i++) sum += Number(body[i]) * (i % 2 === 0 ? 1 : 3)
    if ((10 - (sum % 10)) % 10 !== Number(long[3])) return null
    return { plu: long[1], price: Number(long[2]) / 100 }
  }
  const short = /^(80[01]\d{3})(\d{5})\d$/.exec(code)
  if (short) return { plu: short[1], price: Number(short[2]) / 100 }
  return null
}

function scan() {
  const code = search.value.trim()
  if (!code) return

  // หาสินค้าจากรหัส/บาร์โค้ดที่ลงทะเบียนไว้ก่อนเสมอ แล้วค่อยตีความเป็นป้ายชั่ง
  // (กันสินค้านำเข้าที่ขึ้นต้น 800/801 ถูกอ่านเป็นป้ายชั่งแล้วคิดเงินผิด)
  const known = products.value.find((p) => p.sku_code === code || p.barcodes?.some((b) => b.barcode === code))
  if (known) {
    addProduct(known, known.barcodes?.find((b) => b.barcode === code)?.barcode)
    return
  }

  const scale = decodeScaleLabel(code)
  if (scale) {
    const weighed = products.value.find((p) => p.sku_code === scale.plu || p.barcodes?.some((b) => b.barcode === scale.plu))
    if (!weighed) return flash(`ไม่พบสินค้าชั่งรหัส ${scale.plu}`)
    const perUnit = Number(weighed.pos_price)
    if (!(perUnit > 0)) return flash(`สินค้าชั่ง ${scale.plu} ยังไม่ได้ตั้งราคาต่อหน่วย`)
    // ป้ายหนึ่งใบ = ถุงจริงหนึ่งถุง จึงแยกบรรทัดเสมอ ไม่รวมยอดกับถุงก่อนหน้า
    // (server จะถอดบาร์โค้ดและคำนวณน้ำหนักซ้ำอีกครั้งจากราคาต่อหน่วยของตัวเอง)
    cart.value.push({ ...weighed, qty: Number((scale.price / perUnit).toFixed(4)), scannedBarcode: code })
    search.value = ''
    nextTick(() => scanner.value?.focus())
    return
  }

  flash(`ไม่พบรหัส ${code}`)
}

async function refreshQueue() {
  pendingCount.value = (await queueItems()).filter((item) => item.status !== 'synced').length
}

async function syncAll() {
  if (!profile.value || !navigator.onLine) return
  syncing.value = true
  try {
    const fresh = await api.products(profile.value.branchId)
    products.value = fresh
    await replaceProducts(fresh)
    await syncCheckoutQueue(refreshQueue)
    await refreshQueue()
    online.value = true
  } catch (e) {
    online.value = false
    showError(e)
  } finally { syncing.value = false }
}

async function configure() {
  busy.value = true
  try {
    const connected = await connect(setupUrl.value, setupToken.value.trim())
    profile.value = connected
    await saveProfile(connected)
    modal.value = 'cashier'
    await syncAll()
  } catch (e) { showError(e) } finally { busy.value = false }
}

async function loginCashier() {
  busy.value = true
  try {
    const result = await api.cashierLogin(cashierCode.value.trim(), cashierPin.value)
    cashier.value = result.cashier
    shift.value = await api.activeShift(profile.value!.branchId, cashier.value.id)
    await saveSession(cashier.value, shift.value)
    modal.value = shift.value ? null : 'shift'
    cashierPin.value = ''
  } catch (e) { showError(e) } finally { busy.value = false }
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

function openPayment() {
  if (!cashier.value) { modal.value = 'cashier'; return }
  if (!shift.value) { modal.value = 'shift'; return }
  cashReceived.value = subtotal.value
  modal.value = 'payment'
}

async function checkout() {
  if (!profile.value || !cashier.value || !shift.value || !cart.value.length) return
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
    items: cart.value.map((line) => ({ product_id: line.id, qty: line.qty, unit_price: Number(line.pos_price), barcode: line.scannedBarcode })),
  }
  const queueItem: QueueItem = { id, payload, status: 'pending', attempts: 0, createdAt: new Date().toISOString() }
  busy.value = true
  try {
    await enqueue(queueItem)
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
  profile.value = await loadProfile()
  products.value = await loadProducts()
  const saved = await loadSession()
  cashier.value = saved.cashier
  shift.value = saved.shift?.status === 'open' ? saved.shift : null
  await refreshQueue()
  if (!profile.value) { modal.value = 'settings'; return }
  setServerUrl(profile.value.serverUrl)
  modal.value = cashier.value && shift.value ? null : 'cashier'
  void syncAll()
  void checkUpdate()
}

function networkUp() { online.value = true; void syncAll() }
function networkDown() { online.value = false }
onMounted(() => { window.addEventListener('online', networkUp); window.addEventListener('offline', networkDown); void start() })
onUnmounted(() => { window.removeEventListener('online', networkUp); window.removeEventListener('offline', networkDown) })
</script>

<template>
  <main class="app-shell">
    <header class="topbar">
      <div class="brand"><span class="brand-star">★</span><div><strong>POPSTAR</strong><small>POINT OF SALE</small></div></div>
      <div class="terminal"><strong>{{ profile?.branchName || 'ยังไม่ได้ตั้งค่าเครื่อง' }}</strong><span>{{ profile?.terminalCode || 'POS' }} · {{ cashier?.name || 'ยังไม่เข้าแคชเชียร์' }}</span></div>
      <div class="top-actions">
        <button class="status" :class="online ? 'online' : 'offline'" @click="syncAll"><Wifi v-if="online"/><CloudOff v-else/><span>{{ online ? (syncing ? 'กำลังซิงก์' : 'ออนไลน์') : 'ออฟไลน์' }}</span></button>
        <button class="icon-button" title="ซิงก์ข้อมูล" @click="syncAll"><RefreshCw :class="{ spin: syncing }"/></button>
        <button v-if="lastReceipt" class="icon-button" title="พิมพ์บิลล่าสุด" @click="printLastReceipt"><Printer/></button>
        <button v-if="shift" class="icon-button" title="ปิดกะขาย" @click="countedCash = shift.expected_cash; modal = 'closeShift'"><LogOut/></button>
        <button class="icon-button" title="ตั้งค่าเครื่อง" @click="modal = 'settings'"><Settings/></button>
      </div>
    </header>

    <section class="workspace">
      <div class="catalog">
        <div class="search-row">
          <Search/>
          <input ref="scanner" v-model="search" autofocus placeholder="สแกนบาร์โค้ด หรือค้นหาชื่อสินค้า" @keyup.enter="scan" />
          <span>{{ filteredProducts.length }} รายการ</span>
        </div>
        <div v-if="products.length" class="product-grid">
          <button v-for="product in filteredProducts" :key="product.id" class="product-tile" @click="addProduct(product)">
            <div class="product-code">{{ product.sku_code }} <span v-if="product.is_promotion || product.is_flash_sale">โปร</span></div>
            <strong>{{ product.name_th }}</strong>
            <div class="product-bottom"><span>คงเหลือ {{ product.stock_qty == null ? '-' : product.stock_qty }}</span><b>฿{{ money(product.pos_price) }}</b></div>
          </button>
        </div>
        <div v-else class="empty-state"><PackageSearch/><strong>ยังไม่มีข้อมูลสินค้าในเครื่อง</strong><span>ตั้งค่าเครื่องและเชื่อมต่ออินเทอร์เน็ตเพื่อดาวน์โหลดสินค้า</span></div>
      </div>

      <aside class="cart-panel">
        <div class="cart-title"><div><ShoppingCart/><strong>รายการขาย</strong></div><span>{{ cart.length }} รายการ</span></div>
        <div class="cart-lines">
          <div v-for="(line, index) in cart" :key="`${line.id}-${line.scannedBarcode || ''}`" class="cart-line">
            <div class="line-info"><strong>{{ line.name_th }}</strong><span>{{ line.sku_code }} · ฿{{ money(line.pos_price) }}</span></div>
            <div class="qty-control"><button title="ลดจำนวน" @click="line.qty <= 1 ? cart.splice(index, 1) : line.qty--"><Minus/></button><input v-model.number="line.qty" type="number" min="0.001" step="1"><button title="เพิ่มจำนวน" @click="line.qty++"><Plus/></button></div>
            <b>฿{{ money(line.pos_price * line.qty) }}</b>
            <button class="delete" title="ลบรายการ" @click="cart.splice(index, 1)"><Trash2/></button>
          </div>
          <div v-if="!cart.length" class="cart-empty"><ShoppingCart/><span>สแกนสินค้าเพื่อเริ่มขาย</span></div>
        </div>
        <div class="totals"><div><span>ยอดก่อนภาษี</span><b>฿{{ money(subtotal - vat) }}</b></div><div><span>VAT {{ profile?.vatRate || 7 }}%</span><b>฿{{ money(vat) }}</b></div><div class="grand"><span>ยอดสุทธิ</span><b>฿{{ money(subtotal) }}</b></div></div>
        <button class="pay-button" :disabled="!cart.length || busy" @click="openPayment"><Banknote/> ชำระเงิน <span>F10</span></button>
        <div class="queue-bar" :class="{ warning: pendingCount }"><Cloud/><span>{{ pendingCount ? `รอส่งขึ้น ERP ${pendingCount} บิล` : 'บิลทั้งหมดส่งขึ้น ERP แล้ว' }}</span></div>
      </aside>
    </section>

    <transition name="toast"><div v-if="notice" class="toast success"><CheckCircle2/>{{ notice }}</div></transition>
    <transition name="toast"><div v-if="error" class="toast error"><AlertTriangle/>{{ error }}<button @click="error = ''"><X/></button></div></transition>

    <div v-if="modal" class="modal-backdrop">
      <form v-if="modal === 'settings'" class="modal" @submit.prevent="configure">
        <div class="modal-head"><div><Settings/><span><strong>ตั้งค่าเครื่อง POS</strong><small>เชื่อมเครื่องนี้กับ ERP เพียงครั้งแรก</small></span></div><button v-if="profile" type="button" @click="modal = null"><X/></button></div>
        <label>ที่อยู่เซิร์ฟเวอร์<input v-model="setupUrl" required placeholder="https://erp.example.com"></label>
        <label>Device Token<textarea v-model="setupToken" required rows="3" placeholder="วาง Token จาก ERP > ตั้งค่า > ดาวน์โหลด POS"></textarea></label>
        <button class="primary" :disabled="busy">{{ busy ? 'กำลังตรวจสอบ...' : 'เชื่อมต่อเครื่อง' }}</button>
      </form>

      <form v-else-if="modal === 'cashier'" class="modal compact" @submit.prevent="loginCashier">
        <div class="modal-head"><div><UserRound/><span><strong>เข้าใช้งานแคชเชียร์</strong><small>{{ profile?.branchName }}</small></span></div></div>
        <label>รหัสพนักงาน<input v-model="cashierCode" required autofocus autocomplete="username"></label>
        <label>PIN<input v-model="cashierPin" required type="password" inputmode="numeric" minlength="4" autocomplete="current-password"></label>
        <button class="primary" :disabled="busy">เข้าใช้งาน</button>
      </form>

      <form v-else-if="modal === 'shift'" class="modal compact" @submit.prevent="openShift">
        <div class="modal-head"><div><Banknote/><span><strong>เปิดกะขาย</strong><small>{{ cashier?.name }} · {{ profile?.branchName }}</small></span></div></div>
        <label>เงินทอนเริ่มต้น<input v-model.number="openingCash" type="number" min="0" step="0.01" autofocus required></label>
        <button class="primary" :disabled="busy">ยืนยันเปิดกะ</button>
      </form>

      <form v-else-if="modal === 'closeShift'" class="modal compact" @submit.prevent="closeShift">
        <div class="modal-head"><div><LogOut/><span><strong>ปิดกะขาย</strong><small>{{ shift?.shift_no }} · {{ pendingCount ? `มี ${pendingCount} บิลรอส่ง` : 'บิลส่งครบแล้ว' }}</small></span></div><button type="button" @click="modal = null"><X/></button></div>
        <label>เงินสดที่นับได้จริง<input v-model.number="countedCash" type="number" min="0" step="0.01" autofocus required></label>
        <button class="primary" :disabled="busy || pendingCount > 0">ยืนยันปิดกะ</button>
      </form>

      <form v-else-if="modal === 'payment'" class="modal payment-modal" @submit.prevent="checkout">
        <div class="modal-head"><div><Banknote/><span><strong>รับชำระเงิน</strong><small>{{ cart.length }} รายการ</small></span></div><button type="button" @click="modal = null"><X/></button></div>
        <div class="payment-total"><span>ยอดที่ต้องชำระ</span><strong>฿{{ money(subtotal) }}</strong></div>
        <div class="payment-methods"><button v-for="method in (['cash','transfer','credit_card','cheque'] as PaymentMethod[])" :key="method" type="button" :class="{ active: paymentMethod === method }" @click="paymentMethod = method">{{ ({cash:'เงินสด',transfer:'โอน/QR',credit_card:'บัตรเครดิต',cheque:'เช็ค'} as any)[method] }}</button></div>
        <label v-if="paymentMethod === 'cash'">รับเงินสด<input v-model.number="cashReceived" type="number" min="0" step="0.01" autofocus></label>
        <label v-else>เลขอ้างอิงการชำระ<input v-model="paymentRef" required autofocus></label>
        <div v-if="paymentMethod === 'cash'" class="change"><span>เงินทอน</span><strong>฿{{ money(change) }}</strong></div>
        <button class="primary pay-confirm" :disabled="busy">{{ online ? 'ยืนยันและออกบิล' : 'ยืนยันบิลออฟไลน์' }}</button>
      </form>
    </div>

    <section v-if="lastReceipt" class="print-receipt">
      <h1>{{ profile?.company.name || 'POPSTAR SHOP' }}</h1>
      <p>{{ profile?.company.address }}<br>เลขประจำตัวผู้เสียภาษี {{ profile?.company.tax_id || '-' }}</p>
      <h2>{{ lastReceipt.provisional ? 'ใบรับรายการชั่วคราว' : 'ใบเสร็จรับเงิน/ใบกำกับภาษีอย่างย่อ' }}</h2>
      <div class="print-meta"><span>เลขที่ {{ lastReceipt.no }}</span><span>{{ lastReceipt.printedAt }}</span><span>{{ profile?.branchName }} / {{ cashier?.name }}</span></div>
      <table><tbody><tr v-for="line in lastReceipt.items" :key="line.id"><td>{{ line.name_th }}<small>{{ line.qty }} x {{ money(line.pos_price) }}</small></td><td>{{ money(line.qty * line.pos_price) }}</td></tr></tbody></table>
      <div class="print-total"><span>รวมสุทธิ</span><strong>{{ money(lastReceipt.total) }} บาท</strong></div>
      <p>ชำระโดย {{ ({cash:'เงินสด',transfer:'โอน/QR',credit_card:'บัตรเครดิต',cheque:'เช็ค',mixed:'เงินสด+โอน'} as any)[lastReceipt.method] }}<br><span v-if="lastReceipt.method === 'cash'">รับเงิน {{ money(lastReceipt.paid) }} · เงินทอน {{ money(lastReceipt.change) }}</span></p>
      <p v-if="lastReceipt.provisional" class="provisional-note">รายการนี้รอส่งขึ้น ERP เอกสารภาษีฉบับจริงจะออกเมื่อเชื่อมต่อสำเร็จ</p>
      <p>ขอบคุณที่ใช้บริการ</p>
    </section>
  </main>
</template>
