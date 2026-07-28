import Database from '@tauri-apps/plugin-sql'
import type { Cashier, DeviceProfile, LocalSaleHistory, Product, QueueItem, Shift } from './types'

let db: Database | null = null

async function connection() {
  if (db) return db
  db = await Database.load('sqlite:popstar-pos.db')
  await db.execute(`CREATE TABLE IF NOT EXISTS app_state (key TEXT PRIMARY KEY, value TEXT NOT NULL)`)
  await db.execute(`CREATE TABLE IF NOT EXISTS products (id INTEGER PRIMARY KEY, data TEXT NOT NULL, synced_at TEXT NOT NULL)`)
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
  await db.execute("DELETE FROM pos_sale_history WHERE printed_at < datetime('now', '-90 days')")
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

export async function replaceProducts(products: Product[]) {
  const conn = await connection()
  const syncedAt = new Date().toISOString()
  await conn.execute('DELETE FROM products')
  for (const product of products) {
    await conn.execute('INSERT INTO products (id, data, synced_at) VALUES (?, ?, ?)', [product.id, JSON.stringify(product), syncedAt])
  }
}

export async function loadProducts(): Promise<Product[]> {
  const conn = await connection()
  const rows = await conn.select<Array<{ data: string }>>('SELECT data FROM products ORDER BY id')
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
