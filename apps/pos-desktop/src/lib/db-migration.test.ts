import { copyFileSync, existsSync, rmSync } from 'node:fs'
import { createRequire } from 'node:module'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import type { DatabaseSync as DatabaseSyncClass } from 'node:sqlite'
import { describe, expect, it } from 'vitest'

// โหลดตอนรันไทม์ เพราะ vite รุ่นที่ล็อกไว้ยังไม่รู้จัก node:sqlite
// มันจะตัด prefix "node:" ทิ้งแล้วไปหา package ชื่อ sqlite ซึ่งไม่มีอยู่จริง
// ส่วน import type ข้างบนถูกลบตอนคอมไพล์ vite จึงไม่เห็น
const { DatabaseSync } = createRequire(import.meta.url)('node:sqlite') as {
  DatabaseSync: typeof DatabaseSyncClass
}

/**
 * อัปเดตแล้วของที่ค้างอยู่ในเครื่องต้องไม่หาย
 *
 * เครื่องหน้าร้านมีบิลที่ยังส่งไม่ขึ้น ประวัติขาย และบิลพักค้างอยู่เสมอ
 * ถ้าการเพิ่มตารางใหม่ไปลบของพวกนี้ ร้านจะเสียยอดขายจริงโดยไม่มีใครรู้จนกว่าจะปิดร้าง
 */

// ใช้ node:sqlite ที่มากับ Node 22 แทนไลบรารีภายนอก เพื่อไม่ให้ lockfile
// ต่างไปจนงาน build installer ที่ล็อกไว้ด้วย --frozen-lockfile ล้ม

/** ทำซ้ำสิ่งที่ connection() ใน db.ts ทำ เพื่อทดสอบได้โดยไม่ต้องมี Tauri */
function applySchema(db: DatabaseSyncClass) {
  db.exec(`CREATE TABLE IF NOT EXISTS app_state (key TEXT PRIMARY KEY, value TEXT NOT NULL)`)
  db.exec(`CREATE TABLE IF NOT EXISTS products (id INTEGER PRIMARY KEY, data TEXT NOT NULL, synced_at TEXT NOT NULL)`)
  db.exec(`CREATE TABLE IF NOT EXISTS promotions (id INTEGER PRIMARY KEY, data TEXT NOT NULL, synced_at TEXT NOT NULL)`)
  db.exec(`CREATE TABLE IF NOT EXISTS offline_cashiers (
    id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, branch_id INTEGER,
    credential TEXT, active INTEGER NOT NULL DEFAULT 1, synced_at TEXT NOT NULL
  )`)
  db.exec(`CREATE TABLE IF NOT EXISTS checkout_queue (
    id TEXT PRIMARY KEY, payload TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0,
    error TEXT, receipt_no TEXT, created_at TEXT NOT NULL, synced_at TEXT
  )`)
  db.exec(`CREATE TABLE IF NOT EXISTS pos_sale_history (
    id TEXT PRIMARY KEY, receipt_no TEXT NOT NULL, status TEXT NOT NULL,
    total REAL NOT NULL, method TEXT NOT NULL, paid REAL NOT NULL DEFAULT 0,
    change_amount REAL NOT NULL DEFAULT 0, items TEXT NOT NULL, printed_at TEXT NOT NULL,
    error TEXT, synced_at TEXT
  )`)
  db.exec(`CREATE TABLE IF NOT EXISTS product_barcodes (
    barcode TEXT PRIMARY KEY, product_id INTEGER NOT NULL,
    barcode_type TEXT NOT NULL DEFAULT 'CUSTOM',
    unit_id INTEGER, unit_factor REAL NOT NULL DEFAULT 1, price REAL, synced_at TEXT NOT NULL
  )`)
  db.exec('CREATE INDEX IF NOT EXISTS idx_product_barcodes_product ON product_barcodes (product_id)')
}

/** โครงของรุ่นก่อนหน้า ที่ยังไม่มีตาราง product_barcodes */
function applyPreviousSchema(db: DatabaseSyncClass) {
  db.exec(`CREATE TABLE app_state (key TEXT PRIMARY KEY, value TEXT NOT NULL)`)
  db.exec(`CREATE TABLE products (id INTEGER PRIMARY KEY, data TEXT NOT NULL, synced_at TEXT NOT NULL)`)
  db.exec(`CREATE TABLE promotions (id INTEGER PRIMARY KEY, data TEXT NOT NULL, synced_at TEXT NOT NULL)`)
  db.exec(`CREATE TABLE offline_cashiers (
    id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, branch_id INTEGER,
    credential TEXT, active INTEGER NOT NULL DEFAULT 1, synced_at TEXT NOT NULL
  )`)
  db.exec(`CREATE TABLE checkout_queue (
    id TEXT PRIMARY KEY, payload TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0,
    error TEXT, receipt_no TEXT, created_at TEXT NOT NULL, synced_at TEXT
  )`)
  db.exec(`CREATE TABLE pos_sale_history (
    id TEXT PRIMARY KEY, receipt_no TEXT NOT NULL, status TEXT NOT NULL,
    total REAL NOT NULL, method TEXT NOT NULL, paid REAL NOT NULL DEFAULT 0,
    change_amount REAL NOT NULL DEFAULT 0, items TEXT NOT NULL, printed_at TEXT NOT NULL,
    error TEXT, synced_at TEXT
  )`)
}

describe('สำรองและกู้คืนแบบ WAL', () => {
  it('VACUUM INTO เก็บรายการที่ยังอยู่ใน WAL ไปด้วย ไม่ทิ้งไว้ข้างหลัง', () => {
    const source = join(tmpdir(), `pos-wal-${Date.now()}.db`)
    const backup = join(tmpdir(), `pos-backup-${Date.now()}.db`)
    const db = new DatabaseSync(source)
    db.exec('PRAGMA journal_mode=WAL')
    applySchema(db)
    db.prepare('INSERT INTO checkout_queue (id, payload, status, created_at) VALUES (?, ?, ?, ?)')
      .run('offline-sale-1', '{"total":250}', 'pending', '2026-08-24T09:00:00Z')

    // ยังไม่ checkpoint — บิลนี้อยู่ใน -wal ไม่ได้อยู่ในไฟล์ .db
    db.exec(`VACUUM INTO '${backup}'`)
    db.close()

    const restored = new DatabaseSync(backup)
    expect(restored.prepare('SELECT COUNT(*) AS n FROM checkout_queue').get()).toEqual({ n: 1 })
    restored.close()

    for (const path of [source, backup, `${source}-wal`, `${source}-shm`]) {
      if (existsSync(path)) rmSync(path)
    }
  })

  it('คัดลอกเฉพาะไฟล์ .db ได้สำเนาที่ใช้ไม่ได้เลย', () => {
    const source = join(tmpdir(), `pos-copy-${Date.now()}.db`)
    const copy = join(tmpdir(), `pos-copy-only-${Date.now()}.db`)
    const db = new DatabaseSync(source)
    db.exec('PRAGMA journal_mode=WAL')
    applySchema(db)
    db.prepare('INSERT INTO checkout_queue (id, payload, status, created_at) VALUES (?, ?, ?, ?)')
      .run('offline-sale-2', '{"total":900}', 'pending', '2026-08-24T09:05:00Z')

    // นี่คือสิ่งที่โค้ดเดิมทำ — คัดลอก .db เฉย ๆ ระหว่างที่ยังมี -wal ค้างอยู่
    copyFileSync(source, copy)
    db.close()

    // ไม่ใช่แค่บิลที่หาย — โครงตารางเองก็ยังอยู่ใน WAL สำเนาที่ได้จึงว่างเปล่าทั้งไฟล์
    const restored = new DatabaseSync(copy)
    expect(() => restored.prepare('SELECT COUNT(*) AS n FROM checkout_queue').get())
      .toThrowError(/no such table/)
    restored.close()

    for (const path of [source, copy, `${source}-wal`, `${source}-shm`]) {
      if (existsSync(path)) rmSync(path)
    }
  })
})

describe('อัปเกรดฐานข้อมูลในเครื่อง', () => {
  it('เพิ่มตารางบาร์โค้ดโดยไม่แตะคิวส่งบิล ประวัติขาย และบิลพัก', () => {
    const db = new DatabaseSync(':memory:')
    applyPreviousSchema(db)

    db.prepare('INSERT INTO checkout_queue (id, payload, status, created_at) VALUES (?, ?, ?, ?)')
      .run('queued-1', '{"items":[]}', 'pending', '2026-08-23T10:00:00Z')
    db.prepare(`INSERT INTO pos_sale_history (id, receipt_no, status, total, method, items, printed_at)
      VALUES (?, ?, ?, ?, ?, ?, ?)`).run('sale-1', 'R0001', 'pending', 250.5, 'cash', '[]', '2026-08-23T10:05:00Z')
    db.prepare('INSERT INTO app_state (key, value) VALUES (?, ?)').run('held_bills', '[{"id":1,"label":"โต๊ะ 3"}]')

    applySchema(db)

    expect(db.prepare('SELECT COUNT(*) AS n FROM checkout_queue').get()).toEqual({ n: 1 })
    expect(db.prepare('SELECT status FROM checkout_queue WHERE id = ?').get('queued-1')).toEqual({ status: 'pending' })
    expect(db.prepare('SELECT total FROM pos_sale_history WHERE id = ?').get('sale-1')).toEqual({ total: 250.5 })
    expect(db.prepare('SELECT value FROM app_state WHERE key = ?').get('held_bills'))
      .toEqual({ value: '[{"id":1,"label":"โต๊ะ 3"}]' })

    const columns = db.prepare('PRAGMA table_info(product_barcodes)').all() as Array<{ name: string }>
    expect(columns.map((column) => column.name)).toContain('barcode_type')
  })

  it('รันซ้ำได้โดยไม่พัง และของเดิมยังอยู่', () => {
    const db = new DatabaseSync(':memory:')
    applySchema(db)
    db.prepare(`INSERT INTO product_barcodes (barcode, product_id, barcode_type, unit_factor, synced_at)
      VALUES (?, ?, ?, ?, ?)`).run('8850000000003', 7, 'EAN13_STANDARD', 1, '2026-08-23T10:00:00Z')

    expect(() => applySchema(db)).not.toThrow()
    expect(db.prepare('SELECT barcode_type FROM product_barcodes WHERE barcode = ?').get('8850000000003'))
      .toEqual({ barcode_type: 'EAN13_STANDARD' })
  })

  it('บาร์โค้ดเดียวกันซ้ำสองสินค้าไม่ได้ เหมือนฝั่งเซิร์ฟเวอร์', () => {
    const db = new DatabaseSync(':memory:')
    applySchema(db)
    const insert = db.prepare(`INSERT INTO product_barcodes (barcode, product_id, barcode_type, unit_factor, synced_at)
      VALUES (?, ?, ?, ?, ?)`)
    insert.run('8850000000010', 1, 'INTERNAL_13', 1, 'now')

    expect(() => insert.run('8850000000010', 2, 'INTERNAL_13', 1, 'now')).toThrow()
  })
})
