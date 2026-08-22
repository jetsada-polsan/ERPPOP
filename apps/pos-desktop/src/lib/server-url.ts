/**
 * ทำความสะอาดที่อยู่ ERP ที่พนักงานสาขาพิมพ์/วางในหน้า "ตั้งค่าเครื่อง POS"
 *
 * เคสที่เจอจริงหน้างานแล้วทำให้ขึ้น "เชื่อม ERP ไม่ได้" ทั้งที่ ERP ปกติ:
 *   - มีช่องว่างติดมาจากการ copy (" http://27.254.143.219 ")
 *   - ไม่ได้ใส่ scheme ("27.254.143.219")
 *   - วาง path ของ API ติดมาด้วย (".../api/pos")
 *   - ปิดท้ายด้วย / หรือมี query/hash ติดมา
 *
 * คืนค่าเป็น base URL ที่พร้อมต่อท้ายด้วย /api/pos ได้ทันที
 */
export function normalizeServerUrl(raw: string): string {
  const cleaned = String(raw ?? '').replace(/[\s\u200B-\u200D\uFEFF]+/g, '')
  if (!cleaned) {
    throw new Error('กรุณาระบุที่อยู่เซิร์ฟเวอร์ ERP')
  }

  const withScheme = /^https?:\/\//i.test(cleaned) ? cleaned : `http://${cleaned}`
  let url: URL
  try {
    url = new URL(withScheme)
  } catch {
    throw new Error(`ที่อยู่เซิร์ฟเวอร์ไม่ถูกต้อง: ${String(raw).trim()}`)
  }
  if (!url.hostname) {
    throw new Error(`ที่อยู่เซิร์ฟเวอร์ไม่ถูกต้อง: ${String(raw).trim()}`)
  }

  // ตัด /api หรือ /api/pos ที่มักถูกวางติดมาจากคู่มือ ไม่งั้นจะยิงไป /api/pos/api/pos/ping
  const path = url.pathname.replace(/\/+$/, '').replace(/\/api(\/pos)?$/i, '')

  return `${url.protocol}//${url.host}${path}`
}
