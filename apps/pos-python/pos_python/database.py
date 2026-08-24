from __future__ import annotations

import sqlite3
from pathlib import Path


SCHEMA = """
CREATE TABLE IF NOT EXISTS local_cashiers (
    id INTEGER PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    pin_hash TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    synced_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY,
    server_id INTEGER UNIQUE,
    sku TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    unit_name TEXT NOT NULL DEFAULT 'หน่วย',
    active INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS product_barcodes (
    barcode TEXT PRIMARY KEY,
    product_id INTEGER NOT NULL REFERENCES products(id),
    barcode_type TEXT NOT NULL DEFAULT 'CUSTOM',
    unit_factor REAL NOT NULL DEFAULT 1,
    price REAL,
    synced_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS price_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id),
    price REAL NOT NULL,
    starts_at TEXT NOT NULL,
    ends_at TEXT,
    version TEXT NOT NULL,
    branch_id INTEGER,
    synced_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS shifts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL UNIQUE,
    branch_id INTEGER NOT NULL,
    terminal_id TEXT NOT NULL,
    cashier_id INTEGER NOT NULL REFERENCES local_cashiers(id),
    opened_at TEXT NOT NULL,
    closed_at TEXT,
    opening_cash REAL NOT NULL DEFAULT 0,
    status TEXT NOT NULL CHECK(status IN ('open', 'closed'))
);
CREATE TABLE IF NOT EXISTS sales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_uuid TEXT NOT NULL UNIQUE,
    document_no TEXT NOT NULL UNIQUE,
    branch_id INTEGER NOT NULL,
    terminal_id TEXT NOT NULL,
    shift_id INTEGER NOT NULL REFERENCES shifts(id),
    cashier_id INTEGER NOT NULL REFERENCES local_cashiers(id),
    sale_datetime TEXT NOT NULL,
    subtotal REAL NOT NULL,
    discount_total REAL NOT NULL DEFAULT 0,
    vat_total REAL NOT NULL DEFAULT 0,
    grand_total REAL NOT NULL,
    payment_status TEXT NOT NULL,
    sync_status TEXT NOT NULL DEFAULT 'pending',
    is_void INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS sale_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL REFERENCES sales(id),
    product_id INTEGER NOT NULL REFERENCES products(id),
    barcode TEXT,
    source_barcode TEXT,
    product_name_snapshot TEXT NOT NULL,
    unit_name_snapshot TEXT NOT NULL,
    qty REAL NOT NULL,
    unit_price REAL NOT NULL,
    discount REAL NOT NULL DEFAULT 0,
    line_total REAL NOT NULL,
    price_version TEXT
);
CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL REFERENCES sales(id),
    method TEXT NOT NULL,
    amount REAL NOT NULL,
    reference TEXT
);
CREATE TABLE IF NOT EXISTS print_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL REFERENCES sales(id),
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS sync_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    aggregate_type TEXT NOT NULL,
    aggregate_uuid TEXT NOT NULL UNIQUE,
    payload TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,
    created_at TEXT NOT NULL,
    synced_at TEXT
);
CREATE TABLE IF NOT EXISTS sync_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    direction TEXT NOT NULL,
    status TEXT NOT NULL,
    message TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS device_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS printer_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    driver_type TEXT NOT NULL CHECK(driver_type IN ('EPSON_ESC_POS', 'STAR', 'GENERIC_ESC_POS', 'MOCK')),
    connection_type TEXT NOT NULL CHECK(connection_type IN ('USB', 'SERIAL', 'NETWORK', 'MOCK')),
    address TEXT,
    paper_width_mm INTEGER NOT NULL CHECK(paper_width_mm IN (58, 80)),
    open_drawer INTEGER NOT NULL DEFAULT 1,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS receipt_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    revision INTEGER NOT NULL,
    paper_width_mm INTEGER NOT NULL CHECK(paper_width_mm IN (58, 80)),
    header_text TEXT NOT NULL,
    footer_text TEXT NOT NULL,
    show_tax_id INTEGER NOT NULL DEFAULT 1,
    show_cashier INTEGER NOT NULL DEFAULT 1,
    show_barcode INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(name, revision)
);
"""


def connect(path: Path) -> sqlite3.Connection:
    path.parent.mkdir(parents=True, exist_ok=True)
    connection = sqlite3.connect(path)
    connection.row_factory = sqlite3.Row
    connection.execute("PRAGMA foreign_keys = ON")
    connection.execute("PRAGMA journal_mode = WAL")
    connection.execute("PRAGMA synchronous = FULL")
    connection.executescript(SCHEMA)
    return connection
