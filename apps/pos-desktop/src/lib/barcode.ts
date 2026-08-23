import type { Barcode, Product } from './types'

/**
 * ประเภทบาร์โค้ด — ต้องตรงกับ App\Support\BarcodePolicy ฝั่งเซิร์ฟเวอร์
 */
export const BARCODE_TYPES = ['EAN13_STANDARD', 'INTERNAL_13', 'SCALE_WEIGHT', 'CUSTOM'] as const
export type BarcodeType = (typeof BARCODE_TYPES)[number]

/** ของที่ sync มาก่อนมีฟิลด์นี้จะไม่มีค่า ให้ถือเป็นรหัสกำหนดเองไว้ก่อน */
export const DEFAULT_BARCODE_TYPE: BarcodeType = 'CUSTOM'

export function normalizeBarcodeType(value: unknown): BarcodeType {
  return BARCODE_TYPES.includes(value as BarcodeType) ? (value as BarcodeType) : DEFAULT_BARCODE_TYPE
}

export function ean13CheckDigit(twelveDigits: string): number | null {
  if (!/^\d{12}$/.test(twelveDigits)) return null
  let sum = 0
  for (let i = 0; i < 12; i++) sum += Number(twelveDigits[i]) * (i % 2 === 0 ? 1 : 3)
  return (10 - (sum % 10)) % 10
}

export function isValidEan13(barcode: string): boolean {
  if (!/^\d{13}$/.test(barcode)) return false
  return ean13CheckDigit(barcode.slice(0, 12)) === Number(barcode[12])
}

/**
 * ป้ายเครื่องชั่ง: PLU 6 หลัก + ราคารวม (สตางค์)
 *
 * 13 หลักต้องตรวจ check digit เพราะ 800-839 เป็นรหัสประเทศ EAN ของอิตาลีด้วย
 * และเป็นตัวกันไม่ให้แก้ PLU บนป้ายแล้วสวมเป็นสินค้าอื่น
 */
export function decodeScaleLabel(code: string): { plu: string; price: number } | null {
  const long = /^(80[01]\d{3})(\d{6})(\d)$/.exec(code)
  if (long) {
    return ean13CheckDigit(long[1] + long[2]) === Number(long[3])
      ? { plu: long[1], price: Number(long[2]) / 100 }
      : null
  }
  const short = /^(80[01]\d{3})(\d{5})\d$/.exec(code)
  return short ? { plu: short[1], price: Number(short[2]) / 100 } : null
}

export type ScanResolution =
  | { kind: 'exact'; product: Product; barcode: string; barcodeType: BarcodeType; warning?: string }
  | { kind: 'scale'; product: Product; barcode: string; barcodeType: BarcodeType; plu: string; totalPrice: number }
  | { kind: 'scale-unknown'; plu: string }
  | { kind: 'not-found' }

function findBarcode(product: Product, code: string): Barcode | undefined {
  return product.barcodes?.find((row) => row.barcode === code)
}

/**
 * ตีความสิ่งที่สแกนเข้ามา
 *
 * หาค่าดิบตรงตัวก่อนเสมอ ไม่ว่าประเภทไหน — รหัสที่ลงทะเบียนไว้แล้วต้องขายได้
 * แม้ check digit จะไม่ตรงสูตร (ของเก่าจำนวนมากเป็นแบบนั้น และป้ายที่ติดสินค้า
 * ไปแล้วก็เปลี่ยนตามไม่ได้) จากนั้นค่อยตีความเป็นป้ายชั่ง เพื่อกันสินค้านำเข้า
 * ที่ขึ้นต้น 800/801 ถูกอ่านเป็นป้ายชั่งแล้วคิดเงินผิด
 */
export function resolveScan(code: string, products: Product[]): ScanResolution {
  const scanned = code.trim()
  if (!scanned) return { kind: 'not-found' }

  for (const product of products) {
    const matched = findBarcode(product, scanned)
    if (matched) {
      const barcodeType = normalizeBarcodeType(matched.barcode_type)

      return {
        kind: 'exact',
        product,
        barcode: matched.barcode,
        barcodeType,
        // ตรวจแล้วบอก ไม่แก้เลขให้เอง ค่าที่ sync มาคือค่าที่อยู่บนป้ายจริง
        warning: barcodeType === 'EAN13_STANDARD' && !isValidEan13(matched.barcode)
          ? `บาร์โค้ด ${matched.barcode} ตั้งเป็น EAN-13 มาตรฐาน แต่ check digit ไม่ตรง`
          : undefined,
      }
    }

    if (product.sku_code === scanned) {
      return { kind: 'exact', product, barcode: scanned, barcodeType: DEFAULT_BARCODE_TYPE }
    }
  }

  const scale = decodeScaleLabel(scanned)
  if (!scale) return { kind: 'not-found' }

  // ป้ายชั่งใบหนึ่งใช้ครั้งเดียว ไม่ได้ลงทะเบียนไว้ในแคตตาล็อก
  // ตัวที่ลงทะเบียนคือ PLU ของสินค้า
  for (const product of products) {
    if (product.sku_code === scale.plu || findBarcode(product, scale.plu)) {
      return {
        kind: 'scale',
        product,
        barcode: scanned,
        barcodeType: 'SCALE_WEIGHT',
        plu: scale.plu,
        totalPrice: scale.price,
      }
    }
  }

  return { kind: 'scale-unknown', plu: scale.plu }
}
