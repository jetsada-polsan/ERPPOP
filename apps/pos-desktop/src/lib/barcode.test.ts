import { describe, expect, it } from 'vitest'
import { decodeScaleLabel, ean13CheckDigit, isValidEan13, normalizeBarcodeType, resolveScan } from './barcode'
import type { Product } from './types'

function product(id: number, sku: string, barcodes: Array<[string, string]> = [], price = 100): Product {
  return {
    id,
    sku_code: sku,
    name_th: `สินค้า ${id}`,
    pos_price: price,
    barcodes: barcodes.map(([barcode, barcode_type]) => ({ barcode, barcode_type, unit_factor: 1 })),
  } as Product
}

describe('ประเภทบาร์โค้ด', () => {
  it('ของที่ sync มาก่อนมีฟิลด์นี้ถือเป็น CUSTOM ไม่ใช่ล้มทั้งการสแกน', () => {
    expect(normalizeBarcodeType(undefined)).toBe('CUSTOM')
    expect(normalizeBarcodeType('ไม่รู้จัก')).toBe('CUSTOM')
    expect(normalizeBarcodeType('EAN13_STANDARD')).toBe('EAN13_STANDARD')
  })

  it('คำนวณ check digit ตรงกับสูตร EAN-13', () => {
    expect(ean13CheckDigit('885000000000')).toBe(3)
    expect(ean13CheckDigit('สั้นไป')).toBeNull()
    expect(isValidEan13('8850000000003')).toBe(true)
    expect(isValidEan13('8850000000000')).toBe(false)
  })
})

describe('สแกนขายออฟไลน์', () => {
  it('รหัสภายในที่ check digit ไม่ตรง ยังขายได้ตามค่าดิบที่เก็บไว้', () => {
    const items = [product(1, 'P000001', [['8850000000000', 'INTERNAL_13']])]

    const result = resolveScan('8850000000000', items)

    expect(result.kind).toBe('exact')
    if (result.kind !== 'exact') return
    expect(result.product.id).toBe(1)
    expect(result.barcode).toBe('8850000000000')
    expect(result.barcodeType).toBe('INTERNAL_13')
    expect(result.warning).toBeUndefined()
  })

  it('รหัสกำหนดเองที่ไม่ใช่ตัวเลขก็สแกนได้', () => {
    const items = [product(2, 'P000002', [['ABC-123/XY', 'CUSTOM']])]

    const result = resolveScan('ABC-123/XY', items)

    expect(result.kind).toBe('exact')
    if (result.kind !== 'exact') return
    expect(result.barcodeType).toBe('CUSTOM')
  })

  it('EAN-13 มาตรฐานที่ check digit เพี้ยน ยังขายได้แต่ต้องเตือน และห้ามแก้เลขให้เอง', () => {
    const items = [product(3, 'P000003', [['8850000000000', 'EAN13_STANDARD']])]

    const result = resolveScan('8850000000000', items)

    expect(result.kind).toBe('exact')
    if (result.kind !== 'exact') return
    expect(result.barcode).toBe('8850000000000')
    expect(result.warning).toContain('check digit ไม่ตรง')
  })

  it('EAN-13 ที่ถูกต้องไม่มีคำเตือน', () => {
    const items = [product(4, 'P000004', [['8850000000003', 'EAN13_STANDARD']])]

    const result = resolveScan('8850000000003', items)

    expect(result.kind).toBe('exact')
    if (result.kind !== 'exact') return
    expect(result.warning).toBeUndefined()
  })

  it('ค้นด้วยรหัสสินค้าตรง ๆ ได้', () => {
    const result = resolveScan('P000005', [product(5, 'P000005')])

    expect(result.kind).toBe('exact')
  })
})

describe('ป้ายเครื่องชั่ง', () => {
  it('ถอดรหัสได้และผูกกับสินค้าที่ลงทะเบียน PLU ไว้', () => {
    const items = [product(6, '800123', [], 200)]

    const result = resolveScan('800123' + '012550' + String(ean13CheckDigit('800123012550')), items)

    expect(result.kind).toBe('scale')
    if (result.kind !== 'scale') return
    expect(result.plu).toBe('800123')
    expect(result.totalPrice).toBe(125.5)
    expect(result.barcodeType).toBe('SCALE_WEIGHT')
    expect(result.product.id).toBe(6)
  })

  it('แก้ PLU บนป้ายแล้วสวมเป็นสินค้าอื่นไม่ได้ เพราะ check digit ไม่ตรง', () => {
    const valid = '800123' + '012550' + String(ean13CheckDigit('800123012550'))
    const tampered = '800124' + valid.slice(6)

    expect(decodeScaleLabel(tampered)).toBeNull()
    expect(resolveScan(tampered, [product(7, '800124', [], 200)]).kind).toBe('not-found')
  })

  it('สินค้านำเข้าที่ขึ้นต้น 800 และลงทะเบียนบาร์โค้ดไว้ ต้องอ่านเป็นสินค้า ไม่ใช่ป้ายชั่ง', () => {
    const label = '800123' + '012550' + String(ean13CheckDigit('800123012550'))
    const items = [product(8, 'P000008', [[label, 'INTERNAL_13']])]

    const result = resolveScan(label, items)

    expect(result.kind).toBe('exact')
    if (result.kind !== 'exact') return
    expect(result.barcodeType).toBe('INTERNAL_13')
  })

  it('ป้ายชั่งที่ยังไม่มีสินค้ารองรับ บอก PLU ให้คนหน้าร้านรู้ว่าขาดอะไร', () => {
    const result = resolveScan('800999' + '010000' + String(ean13CheckDigit('800999010000')), [])

    expect(result.kind).toBe('scale-unknown')
    if (result.kind !== 'scale-unknown') return
    expect(result.plu).toBe('800999')
  })
})
