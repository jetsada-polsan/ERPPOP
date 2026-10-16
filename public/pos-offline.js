/*
 * POPSTAR Web POS — ขายออฟไลน์ (IndexedDB)
 *
 * เก็บแคตตาล็อกสินค้า+ราคา และคิวบิลที่ขายตอนเน็ต/Host ล่ม ไว้ในเครื่อง
 * ไม่เก็บรหัสผ่านใด ๆ ในเครื่อง ตัวตนผู้ขายมาจาก session ที่ล็อกอินออนไลน์ล่าสุด
 */
(function () {
  'use strict';

  const DB_NAME = 'popstar-pos-offline';
  const DB_VERSION = 1;
  const MAX_PRODUCTS = 20000;
  let dbPromise = null;

  function open() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
      if (!('indexedDB' in window)) {
        reject(new Error('เบราว์เซอร์นี้ไม่รองรับ IndexedDB'));
        return;
      }
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains('products')) db.createObjectStore('products', { keyPath: 'id' });
        if (!db.objectStoreNames.contains('barcodes')) db.createObjectStore('barcodes', { keyPath: 'code' });
        if (!db.objectStoreNames.contains('queue')) {
          const q = db.createObjectStore('queue', { keyPath: 'offline_uuid' });
          q.createIndex('sold_at', 'sold_at');
        }
        if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta', { keyPath: 'key' });
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
    return dbPromise;
  }

  function done(tx) {
    return new Promise((resolve, reject) => {
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
      tx.onabort = () => reject(tx.error || new Error('transaction aborted'));
    });
  }

  function request(req) {
    return new Promise((resolve, reject) => {
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  }

  async function getMeta(key, fallback = null) {
    const db = await open();
    const row = await request(db.transaction('meta').objectStore('meta').get(key));
    return row ? row.value : fallback;
  }

  async function setMeta(key, value) {
    const db = await open();
    const tx = db.transaction('meta', 'readwrite');
    tx.objectStore('meta').put({ key, value });
    await done(tx);
  }

  /** แทนที่แคตตาล็อกทั้งชุดใน transaction เดียว (ไม่มีสถานะครึ่ง ๆ กลาง ๆ) */
  async function saveCatalog(products, branchId) {
    const rows = (products || []).slice(0, MAX_PRODUCTS);
    const db = await open();
    const tx = db.transaction(['products', 'barcodes', 'meta'], 'readwrite');
    const ps = tx.objectStore('products');
    const bs = tx.objectStore('barcodes');
    ps.clear();
    bs.clear();
    for (const p of rows) {
      const slim = {
        id: p.id, sku_code: p.sku_code, name_th: p.name_th,
        pos_price: Number(p.pos_price ?? p.default_price ?? 0), default_price: Number(p.default_price ?? 0),
        price_source: p.price_source, is_vat: p.is_vat, is_scale: p.is_scale, scale_plu: p.scale_plu,
        product_category_id: p.product_category_id, average_cost: p.average_cost,
        minimum_margin_percent: p.minimum_margin_percent, margin_control_policy: p.margin_control_policy,
        stock_qty: p.stock_qty, base_unit_name: p.base_unit_name, unit_name: p.unit_name,
        search: [p.sku_code, p.name_th].concat((p.barcodes || []).map((b) => b.barcode)).join(' ').toLowerCase(),
      };
      ps.put(slim);
      for (const b of (p.barcodes || [])) {
        if (!b || !b.barcode) continue;
        bs.put({
          code: String(b.barcode), product_id: p.id, barcode_type: b.barcode_type || null,
          unit_id: b.unit_id ?? null, unit_factor: Number(b.unit_factor || 1),
          unit_name: b.unit?.name_th || b.unit?.name || null,
          price: b.price !== null && b.price !== undefined && Number(b.price) > 0 ? Number(b.price) : null,
        });
      }
    }
    const meta = tx.objectStore('meta');
    meta.put({ key: 'catalogUpdatedAt', value: new Date().toISOString() });
    meta.put({ key: 'catalogCount', value: rows.length });
    meta.put({ key: 'catalogBranchId', value: String(branchId || '') });
    await done(tx);
    return rows.length;
  }

  async function allProducts() {
    const db = await open();
    return request(db.transaction('products').objectStore('products').getAll());
  }

  async function search(q, categoryId, limit = 100) {
    const needle = String(q || '').trim().toLowerCase();
    const rows = await allProducts();
    return rows
      .filter((p) => (!categoryId || String(p.product_category_id) === String(categoryId))
        && (!needle || p.search.includes(needle)))
      .sort((a, b) => String(a.name_th).localeCompare(String(b.name_th), 'th'))
      .slice(0, limit);
  }

  /** ค้นแบบเดียวกับ server: barcode exact ก่อน แล้วค่อย SKU */
  async function exact(code, lookup = 'barcode') {
    const value = String(code || '').trim();
    if (!value) return [];
    const db = await open();
    const tx = db.transaction(['products', 'barcodes']);
    if (lookup !== 'sku') {
      const bc = await request(tx.objectStore('barcodes').get(value));
      if (bc) {
        const p = await request(tx.objectStore('products').get(bc.product_id));
        if (p) {
          const factor = bc.unit_factor > 0 ? bc.unit_factor : 1;
          return [{
            ...p,
            pos_price: bc.price ?? Math.round(p.pos_price * factor * 100) / 100,
            price_source: bc.price ? 'barcode' : (factor !== 1 ? 'offline_unit_factor' : p.price_source),
            matched_barcode: {
              barcode: bc.code, barcode_type: bc.barcode_type, unit_id: bc.unit_id,
              unit_name: bc.unit_name, unit_factor: factor,
            },
            offline: true,
          }];
        }
      }
      if (lookup === 'barcode') return [];
    }
    const rows = await allProducts();
    const hit = rows.find((p) => p.sku_code === value);
    return hit ? [{ ...hit, offline: true }] : [];
  }

  async function enqueue(sale) {
    const db = await open();
    const tx = db.transaction('queue', 'readwrite');
    tx.objectStore('queue').put({ ...sale, status: 'queued', attempts: 0, last_error: null });
    await done(tx);
  }

  async function queued() {
    const db = await open();
    const rows = await request(db.transaction('queue').objectStore('queue').getAll());
    return rows.sort((a, b) => String(a.sold_at).localeCompare(String(b.sold_at)));
  }

  async function removeQueued(uuid) {
    const db = await open();
    const tx = db.transaction('queue', 'readwrite');
    tx.objectStore('queue').delete(uuid);
    await done(tx);
  }

  async function markQueued(uuid, patch) {
    const db = await open();
    const tx = db.transaction('queue', 'readwrite');
    const store = tx.objectStore('queue');
    const row = await request(store.get(uuid));
    if (row) store.put({ ...row, ...patch });
    await done(tx);
  }

  function uuid() {
    if (crypto.randomUUID) return crypto.randomUUID();
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
  }

  async function persist() {
    try {
      if (!navigator.storage?.persist) return false;
      if (await navigator.storage.persisted()) return true;
      return await navigator.storage.persist();
    } catch (e) {
      return false;
    }
  }

  async function persisted() {
    try { return Boolean(await navigator.storage?.persisted?.()); } catch (e) { return false; }
  }

  window.PosOffline = {
    open, getMeta, setMeta, saveCatalog, search, exact, enqueue, queued, removeQueued, markQueued,
    uuid, persist, persisted, MAX_PRODUCTS,
  };
})();
