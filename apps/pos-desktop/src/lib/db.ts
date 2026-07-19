import Database from '@tauri-apps/plugin-sql'
import type { Cashier, DeviceProfile, Product, QueueItem, Shift } from './types'

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
}
