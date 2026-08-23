import Database from '@tauri-apps/plugin-sql'
import { normalizeBarcodeType } from './barcode'
import { verifyOfflinePin } from './offline-auth'
import type { Cashier, DeviceProfile, LocalSaleHistory, Product, QtyPromotion, QueueItem, Shift } from './types'

let db: Database | null = null

async function connection() {
  if (db) return db
  db = await Database.load('sqlite:popstar-pos.db')
  await db.execute(`CREATE TABLE IF NOT EXISTS app_state (key TEXT PRIMARY KEY, value TEXT NOT NULL)`)
  await db.execute(`CREATE TABLE IF NOT EXISTS products (id INTEGER PRIMARY KEY, data TEXT NOT NULL, synced_at TEXT NOT NULL)`)
  await db.execute(`CREATE TABLE IF NOT EXISTS promotions (id INTEGER PRIMARY KEY, data TEXT NOT NULL, synced_at TEXT NOT NULL)`)
  await db.execute(`CREATE TABLE IF NOT EXISTS offline_cashiers (
    id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, branch_id INTEGER,
    credential TEXT, active INTEGER NOT NULL DEFAULT 1, synced_at TEXT NOT NULL
  )`)
  await db.execute(`CREATE TABLE IF NOT EXISTS checkout_queue (
    id TEXT PRIMARY KEY, payload TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0,
    error TEXT, receipt_no TEXT, created_at TEXT NOT NULL, synced_at TEXT
  )`)
  await db.execute(`CREATE TABLE IF NOT EXISTS pos_sale_history (
    id TEXT PRIMARY KEY, receipt_no TEXT NOT NULL, status TEXT NOT NULL,
    total REAL NOT NULL, method TEXT NOT NULL, paid REAL NOT NULL DEFAULT 0,
    change_amount REAL NOT NULL DEFAULT 0, items TEXT NOT NULL, printed_at TEXT NOT NULL,
    error TEXT, synced_at TEXT
  )`)
  // ตารางบาร์โค้ดแยกออกมา เพื่อให้ค้นด้วยค่าดิบได้ตรง ๆ และเก็บประเภทไว้ใช้ตอนออฟไลน์
  // สร้างเพิ่มอย่างเดียว ไม่แตะ checkout_queue / pos_sale_history / app_state
  // ที่เก็บบิลค้าง ประวัติขาย และบิลพักอยู่ — อัปเดตแล้วของพวกนั้นต้องอยู่ครบ
  await db.execute(`CREATE TABLE IF NOT EXISTS product_barcodes (
    barcode TEXT PRIMARY KEY, product_id INTEGER NOT NULL,
    barcode_type TEXT NOT NULL DEFAULT 'CUSTOM',
    unit_id INTEGER, unit_factor REAL NOT NULL DEFAULT 1, price REAL, synced_at TEXT NOT NULL
  )`)
  await db.execute('CREATE INDEX IF NOT EXISTS idx_product_barcodes_product ON product_barcodes (product_id)')

  // ลบเฉพาะบิลที่ส่งขึ้นเซิร์ฟเวอร์แล้ว บิลที่ยังค้างต้องเก็บไว้ให้เห็นจนกว่าจะส่งสำเร็จ
  // และต้องเทียบผ่าน datetime() เพราะ printed_at เก็บเป็น ISO-8601 (มี T กับ Z) เทียบสตริงตรงๆ ไม่ตรงรูปแบบกัน
  await db.execute("DELETE FROM pos_sale_history WHERE status = 'synced' AND datetime(printed_at) < datetime('now', '-90 days')")
  return db
}

export async function saveProfile(profile: DeviceProfile) {
  const conn = await connection()
  await conn.execute('INSERT OR REPLACE INTO app_state (key, value) VALUES (?, ?)', ['profile', JSON.stringify(profile)])
}

export async function loadProfile(): Promise<DeviceProfile | null> {
  const conn = await connection()
  const rows = await conn.select<Array<{ value: string }>>('SELECT value FROM app_state WHERE key = ?', ['profile'])
  return rows[0] ? JSON.parse(rows[0].value) : null
}

export async function saveSession(cashier: Cashier | null, shift: Shift | null) {
  const conn = await connection()
  await conn.execute('INSERT OR REPLACE INTO app_state (key, value) VALUES (?, ?)', ['session', JSON.stringify({ cashier, shift })])
}

export async function loadSession(): Promise<{ cashier: Cashier | null; shift: Shift | null }> {
  const conn = await connection()
  const rows = await conn.select<Array<{ value: string }>>('SELECT value FROM app_state WHERE key = ?', ['session'])
  return rows[0] ? JSON.parse(rows[0].value) : { cashier: null, shift: null }
}

/**
 * เขียนทับทั้งตารางแบบ all-or-nothing: ถ้าพังกลางคันต้องได้ข้อมูลชุดเดิมคืน
 * ไม่ใช่ตารางว่าง (เคยทำให้เครื่องสาขาไม่มีสินค้าเลยหลังซิงก์สะดุด)
 * และเร็วกว่าเดิมมากเพราะ commit ครั้งเดียวแทนหนึ่ง commit ต่อหนึ่งแถว
 */
async function inTransaction(conn: Database, work: () => Promise<void>) {
  await conn.execute('BEGIN')
  try {
    await work()
    await conn.execute('COMMIT')
  } catch (error) {
    await conn.execute('ROLLBACK').catch(() => undefined)
    throw error
  }
}

/** Cache public cashier data; credential is only written after a successful online PIN check. */
export async function replaceOfflineCashiers(cashiers: Cashier[]) {
  const conn = await connection()
  const syncedAt = new Date().toISOString()
  await inTransaction(conn, async () => {
    await conn.execute('UPDATE offline_cashiers SET active = 0, synced_at = ?', [syncedAt])
    for (const cashier of cashiers) {
      await conn.execute(
        `INSERT INTO offline_cashiers (id, code, name, branch_id, credential, active, synced_at)
         VALUES (?, ?, ?, ?, NULL, 1, ?)
         ON CONFLICT(id) DO UPDATE SET code = excluded.code, name = excluded.name,
         branch_id = excluded.branch_id, active = 1, synced_at = excluded.synced_at`,
        [cashier.id, cashier.code, cashier.name, cashier.branch_id || null, syncedAt],
      )
    }
  })
}

export async function saveOfflineCredential(cashier: Cashier) {
  if (!cashier.offline_credential) return
  const conn = await connection()
  await conn.execute('UPDATE offline_cashiers SET credential = ?, active = 1 WHERE id = ?', [JSON.stringify(cashier.offline_credential), cashier.id])
}

/** หาเจ้าของ PIN ที่เคยยืนยันออนไลน์บนเครื่องนี้แล้ว โดยจำกัดคนของสาขาเครื่อง POS */
export async function loadOfflineCashiersByPin(pin: string, branchId?: number): Promise<Cashier[]> {
  const conn = await connection()
  const rows = await conn.select<Array<any>>(
    `SELECT id, code, name, branch_id, credential FROM offline_cashiers
     WHERE active = 1 AND credential IS NOT NULL AND (branch_id IS NULL OR branch_id = ?)`,
    [branchId || null],
  )
  const matches: Cashier[] = []
  for (const row of rows) {
    const credential = JSON.parse(row.credential)
    if (await verifyOfflinePin(pin, credential)) {
      matches.push({
        id: Number(row.id), code: row.code, name: row.name,
        branch_id: row.branch_id == null ? undefined : Number(row.branch_id),
        offline_credential: credential,
      })
    }
  }
  return matches
}

export async function loadOfflineCashiers(branchId?: number): Promise<Cashier[]> {
  const conn = await connection()
  const rows = await conn.select<Array<any>>(
    `SELECT id, code, name, branch_id FROM offline_cashiers
     WHERE active = 1 AND (branch_id IS NULL OR branch_id = ?) ORDER BY name, code`,
    [branchId || null],
  )
  return rows.map((row) => ({
    id: Number(row.id), code: row.code, name: row.name,
    branch_id: row.branch_id == null ? undefined : Number(row.branch_id),
  }))
}

export async function replaceProducts(products: Product[]) {
  const conn = await connection()
  const syncedAt = new Date().toISOString()
  await inTransaction(conn, async () => {
    await conn.execute('DELETE FROM products')
    await conn.execute('DELETE FROM product_barcodes')
    for (const product of products) {
      await conn.execute('INSERT INTO products (id, data, synced_at) VALUES (?, ?, ?)', [product.id, JSON.stringify(product), syncedAt])
      for (const row of product.barcodes || []) {
        await conn.execute(
          'INSERT OR REPLACE INTO product_barcodes (barcode, product_id, barcode_type, unit_id, unit_factor, price, synced_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
          [row.barcode, product.id, normalizeBarcodeType(row.barcode_type), row.unit_id ?? null, Number(row.unit_factor || 1), row.price ?? null, syncedAt],
        )
      }
    }
  })
}

export async function loadProducts(): Promise<Product[]> {
  const conn = await connection()
  const rows = await conn.select<Array<{ data: string }>>('SELECT data FROM products ORDER BY id')
  return rows.map((row) => JSON.parse(row.data))
}

/** จำนวนบาร์โค้ดแยกตามประเภท ใช้ตรวจว่าเครื่อง sync ประเภทมาครบแล้วจริง */
export async function barcodeTypeCounts(): Promise<Record<string, number>> {
  const conn = await connection()
  const rows = await conn.select<Array<{ barcode_type: string; count: number }>>(
    'SELECT barcode_type, COUNT(*) AS count FROM product_barcodes GROUP BY barcode_type',
  )

  return Object.fromEntries(rows.map((row) => [row.barcode_type, Number(row.count)]))
}

export async function replacePromotions(promotions: QtyPromotion[]) {
  const conn = await connection()
  const syncedAt = new Date().toISOString()
  await inTransaction(conn, async () => {
    await conn.execute('DELETE FROM promotions')
    for (const promotion of promotions) {
      await conn.execute('INSERT INTO promotions (id, data, synced_at) VALUES (?, ?, ?)', [promotion.id, JSON.stringify(promotion), syncedAt])
    }
  })
}

export async function loadPromotions(): Promise<QtyPromotion[]> {
  const conn = await connection()
  const rows = await conn.select<Array<{ data: string }>>('SELECT data FROM promotions ORDER BY id')
  return rows.map((row) => JSON.parse(row.data))
}

export async function enqueue(item: QueueItem) {
  const conn = await connection()
  await conn.execute(
    'INSERT INTO checkout_queue (id, payload, status, attempts, created_at) VALUES (?, ?, ?, ?, ?)',
    [item.id, JSON.stringify(item.payload), item.status, item.attempts, item.createdAt],
  )
}

export async function queueItems(): Promise<QueueItem[]> {
  const conn = await connection()
  const rows = await conn.select<Array<any>>('SELECT * FROM checkout_queue ORDER BY created_at')
  return rows.map((row) => ({ id: row.id, payload: JSON.parse(row.payload), status: row.status, attempts: row.attempts, error: row.error, receiptNo: row.receipt_no, createdAt: row.created_at }))
}

export async function markQueue(id: string, status: QueueItem['status'], error?: string, receiptNo?: string) {
  const conn = await connection()
  await conn.execute(
    `UPDATE checkout_queue SET status = ?, attempts = attempts + 1, error = ?, receipt_no = ?, synced_at = CASE WHEN ? = 'synced' THEN datetime('now') ELSE synced_at END WHERE id = ?`,
    [status, error || null, receiptNo || null, status, id],
  )
  await conn.execute(
    `UPDATE pos_sale_history SET status = ?, error = ?, receipt_no = COALESCE(?, receipt_no), synced_at = CASE WHEN ? = 'synced' THEN datetime('now') ELSE synced_at END WHERE id = ?`,
    [status, error || null, receiptNo || null, status, id],
  )
}

export async function saveSaleHistory(sale: LocalSaleHistory) {
  const conn = await connection()
  await conn.execute(
    `INSERT OR REPLACE INTO pos_sale_history (id, receipt_no, status, total, method, paid, change_amount, items, printed_at, error, synced_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [sale.id, sale.receiptNo, sale.status, sale.total, sale.method, sale.paid, sale.change, JSON.stringify(sale.items), sale.printedAt, sale.error || null, sale.syncedAt || null],
  )
}

export async function saleHistory(days = 90): Promise<LocalSaleHistory[]> {
  const conn = await connection()
  const rows = await conn.select<Array<any>>(
    `SELECT * FROM pos_sale_history WHERE datetime(printed_at) >= datetime('now', ?) ORDER BY printed_at DESC`,
    [`-${Math.max(1, Math.min(days, 90))} days`],
  )
  return rows.map((row) => ({
    id: row.id,
    receiptNo: row.receipt_no,
    status: row.status,
    total: Number(row.total),
    method: row.method,
    paid: Number(row.paid),
    change: Number(row.change_amount),
    items: JSON.parse(row.items),
    printedAt: row.printed_at,
    syncedAt: row.synced_at || undefined,
    error: row.error || undefined,
  }))
}

export type LocalDbHealth = {
  integrity: string
  products: number
  pending: number
  failed: number
  synced: number
  lastProductSyncAt: string | null
  sizeBytes: number
}

export async function localDbHealth(): Promise<LocalDbHealth> {
  const conn = await connection()
  const integrityRows = await conn.select<Array<{ integrity_check: string }>>('PRAGMA integrity_check')
  const counts = await conn.select<Array<{ status: string; count: number }>>(
    'SELECT status, COUNT(*) AS count FROM checkout_queue GROUP BY status',
  )
  const productSync = await conn.select<Array<{ synced_at: string | null }>>(
    'SELECT MAX(synced_at) AS synced_at FROM products',
  )
  const pageCount = await conn.select<Array<{ page_count: number }>>('PRAGMA page_count')
  const pageSize = await conn.select<Array<{ page_size: number }>>('PRAGMA page_size')
  const countBy = (status: string) => Number(counts.find((row) => row.status === status)?.count || 0)

  return {
    integrity: integrityRows[0]?.integrity_check || 'unknown',
    products: Number((await conn.select<Array<{ count: number }>>('SELECT COUNT(*) AS count FROM products'))[0]?.count || 0),
    pending: countBy('pending') + countBy('syncing'),
    failed: countBy('failed'),
    synced: countBy('synced'),
    lastProductSyncAt: productSync[0]?.synced_at || null,
    sizeBytes: Number(pageCount[0]?.page_count || 0) * Number(pageSize[0]?.page_size || 0),
  }
}

export async function closeLocalDb() {
  if (!db) return
  await db.close()
  db = null
}
