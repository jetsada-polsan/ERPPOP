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
    role TEXT NOT NULL DEFAULT 'cashier',
    credential_version TEXT,
    last_synced_at TEXT,
    offline_valid_until TEXT,
    force_pin_change INTEGER NOT NULL DEFAULT 0,
    revoked_at TEXT,
    synced_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS auth_events_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_uuid TEXT NOT NULL UNIQUE,
    cashier_code TEXT NOT NULL,
    event_type TEXT NOT NULL,
    success INTEGER NOT NULL,
    reason TEXT,
    terminal_code TEXT,
    branch_code TEXT,
    occurred_at TEXT NOT NULL,
    synced INTEGER NOT NULL DEFAULT 0,
    synced_at TEXT,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT
);
CREATE TABLE IF NOT EXISTS cashier_credential_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cashier_id INTEGER NOT NULL REFERENCES local_cashiers(id),
    credential_version TEXT NOT NULL,
    cred_salt TEXT NOT NULL,
    cred_verifier TEXT NOT NULL,
    cred_iterations INTEGER NOT NULL,
    offline_valid_until TEXT NOT NULL,
    superseded_at TEXT NOT NULL,
    UNIQUE(cashier_id, credential_version)
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
    unit_factor TEXT NOT NULL DEFAULT '1',
    price TEXT,
    synced_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS price_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id),
    price TEXT NOT NULL,
    starts_at TEXT NOT NULL,
    ends_at TEXT,
    version TEXT NOT NULL,
    branch_id INTEGER,
    synced_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS shifts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    server_id INTEGER UNIQUE,
    uuid TEXT NOT NULL UNIQUE,
    branch_id INTEGER NOT NULL,
    terminal_id TEXT NOT NULL,
    cashier_id INTEGER NOT NULL REFERENCES local_cashiers(id),
    opened_at TEXT NOT NULL,
    closed_at TEXT,
    opening_cash TEXT NOT NULL DEFAULT '0',
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
    subtotal TEXT NOT NULL,
    discount_total TEXT NOT NULL DEFAULT '0',
    vat_total TEXT NOT NULL DEFAULT '0',
    grand_total TEXT NOT NULL,
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
    barcode_type TEXT NOT NULL DEFAULT 'CUSTOM',
    product_name_snapshot TEXT NOT NULL,
    unit_name_snapshot TEXT NOT NULL,
    qty TEXT NOT NULL,
    unit_price TEXT NOT NULL,
    discount TEXT NOT NULL DEFAULT '0',
    line_total TEXT NOT NULL,
    price_version TEXT
);
CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL REFERENCES sales(id),
    method TEXT NOT NULL,
    amount TEXT NOT NULL,
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
CREATE TABLE IF NOT EXISTS scale_profiles (
    code TEXT PRIMARY KEY,
    prefix TEXT NOT NULL,
    plu_length INTEGER NOT NULL,
    value_length INTEGER NOT NULL,
    value_type TEXT NOT NULL,
    check_digit TEXT NOT NULL,
    total_length INTEGER NOT NULL,
    synced_at TEXT NOT NULL
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


# คอลัมน์ที่เพิ่มทีหลัง — CREATE TABLE IF NOT EXISTS ไม่เติมคอลัมน์ให้เครื่องที่ลงไปแล้ว
# เครื่องหน้าร้านที่อัปเดตจึงต้องมีขั้นตอนเติมทีละคอลัมน์ ไม่ใช่สร้างตารางใหม่ทับ
# ซึ่งจะทำให้บิลค้างส่งกับประวัติขายหายไปทั้งหมด
ADDED_COLUMNS: list[tuple[str, str, str]] = [
    ("payments", "change_amount", "TEXT NOT NULL DEFAULT '0'"),
    # ราคาขายรวม VAT อยู่แล้ว ค่านี้จึงมีผลกับการแยกยอดในบิล ไม่ใช่กับยอดที่ลูกค้าจ่าย
    ("products", "is_vat", "INTEGER NOT NULL DEFAULT 1"),
    # หมวดสินค้าใช้ทำแถบกรองบนหน้าขาย ERP ส่ง id มาให้ ส่วนชื่อเติมตอน sync
    ("products", "category_id", "INTEGER"),
    ("products", "category_name", "TEXT"),
    ("products", "price", "TEXT NOT NULL DEFAULT '0'"),
    # เลขนี้มาจาก ERP หลัง sync สำเร็จ และเป็นกุญแจที่ ERP ใช้ยกเลิกบิล
    ("sales", "server_receipt_no", "TEXT"),
    ("sales", "voided_at", "TEXT"),
    ("sales", "void_reason", "TEXT"),
    ("sales", "voided_by", "INTEGER"),
    # id ของ Salesman ฝั่ง ERP — sync บิลต้องส่ง id นี้ ไม่ใช่ local id ที่ไม่รับประกันว่าตรงกัน
    ("local_cashiers", "server_id", "INTEGER"),
    # credential ออฟไลน์ที่ ERP ออกให้ (PBKDF2) เก็บแทนการเก็บ PIN/hash เต็มลงเครื่อง
    ("local_cashiers", "cred_salt", "TEXT"),
    ("local_cashiers", "cred_verifier", "TEXT"),
    ("local_cashiers", "cred_iterations", "INTEGER"),
    ("local_cashiers", "cred_expires_at", "TEXT"),
    ("local_cashiers", "credential_version", "TEXT"),
    # Offline-first authentication metadata.  These are additive so upgrading a
    # terminal never destroys its cached cashier or pending sales records.
    ("local_cashiers", "role", "TEXT NOT NULL DEFAULT 'cashier'"),
    ("local_cashiers", "last_synced_at", "TEXT"),
    ("local_cashiers", "offline_valid_until", "TEXT"),
    ("local_cashiers", "force_pin_change", "INTEGER NOT NULL DEFAULT 0"),
    ("local_cashiers", "revoked_at", "TEXT"),
    ("local_cashiers", "server_credential_version", "TEXT"),
    ("local_cashiers", "local_override_pin_hash", "TEXT"),
    ("local_cashiers", "local_override_expires_at", "TEXT"),
    ("local_cashiers", "local_override_set_by", "TEXT"),
    # Outbox ordering is additive. Existing pending sales retain priority 2.
    ("sync_outbox", "priority", "INTEGER NOT NULL DEFAULT 2"),
    ("sync_outbox", "depends_on_uuid", "TEXT"),
    ("sync_outbox", "next_attempt_at", "TEXT"),
]

# ยูนีคเฉพาะแถวที่มี server_id — กันแคชเชียร์คนเดียวถูก sync ลงซ้ำสองแถว
# ใช้ partial index เพราะ ALTER TABLE ADD COLUMN เติม UNIQUE ให้ไม่ได้
ADDED_INDEXES: list[str] = [
    "CREATE UNIQUE INDEX IF NOT EXISTS ux_local_cashiers_server_id ON local_cashiers(server_id) WHERE server_id IS NOT NULL",
    "CREATE INDEX IF NOT EXISTS ix_sync_outbox_ready ON sync_outbox(status, priority, created_at)",
    "CREATE INDEX IF NOT EXISTS ix_credential_history_cashier ON cashier_credential_history(cashier_id, superseded_at DESC)",
]


def _add_missing_columns(connection: sqlite3.Connection) -> None:
    for table, column, definition in ADDED_COLUMNS:
        existing = {row["name"] for row in connection.execute(f"PRAGMA table_info({table})")}
        if column not in existing:
            connection.execute(f"ALTER TABLE {table} ADD COLUMN {column} {definition}")
    for statement in ADDED_INDEXES:
        connection.execute(statement)
    # Preserve credentials issued before the new names were introduced.  A
    # blank value means "unknown", not expired; cred_expires_at is authoritative
    # for older releases until the next cashier sync updates both fields.
    connection.execute(
        "UPDATE local_cashiers SET last_synced_at = synced_at "
        "WHERE last_synced_at IS NULL AND synced_at IS NOT NULL"
    )
    connection.execute(
        "UPDATE local_cashiers SET offline_valid_until = cred_expires_at "
        "WHERE offline_valid_until IS NULL AND cred_expires_at IS NOT NULL"
    )


def connect(path: Path) -> sqlite3.Connection:
    path.parent.mkdir(parents=True, exist_ok=True)
    connection = sqlite3.connect(path)
    connection.row_factory = sqlite3.Row
    connection.execute("PRAGMA foreign_keys = ON")
    connection.execute("PRAGMA journal_mode = WAL")
    connection.execute("PRAGMA synchronous = FULL")
    connection.executescript(SCHEMA)
    _add_missing_columns(connection)
    connection.commit()
    return connection
