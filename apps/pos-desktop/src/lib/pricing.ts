import type { BarcodeType } from './barcode'
import type { CartLine, CheckoutPayload, QtyPromotion } from './types'

export type PricedLine = {
  product_id: number
  qty: number
  unit_price: number
  barcode?: string
  barcode_type?: BarcodeType
}

export type CartPricing = {
  subtotal: number
  promoDiscount: number
  total: number
  vat: number
  beforeVat: number
  items: PricedLine[]
}

function roundMoney(value: number): number {
  return Math.round((Number(value) || 0) * 100) / 100
}

function roundUnitPrice(value: number): number {
  return Math.round((Number(value) || 0) * 10000) / 10000
}

function num(value: unknown): number {
  return Number(value) || 0
}

export function priceCart(cart: CartLine[], promotions: QtyPromotion[], vatRate = 7): CartPricing {
  const lines = cart.map((line) => ({
    line,
    qty: Math.max(0, num(line.qty)),
    gross: Math.max(0, num(line.pos_price) * num(line.qty)),
  }))
  const subtotal = roundMoney(lines.reduce((sum, item) => sum + item.gross, 0))
  let promoDiscount = 0

  for (const promotion of promotions) {
    if (promotion.promo_type !== 'discount' && promotion.promo_type !== 'bundle_price') continue
    const matched = lines.filter((item) => item.line.id === promotion.product_id)
    const quantity = matched.reduce((sum, item) => sum + item.qty, 0)
    const minimum = Math.max(1, num(promotion.min_qty))
    const sets = Math.floor(quantity / minimum)
    if (!sets || !matched.length) continue

    const unitPrice = num(matched[0].line.pos_price)
    if (promotion.promo_type === 'bundle_price') {
      promoDiscount += Math.max(0, sets * (minimum * unitPrice - num(promotion.bundle_price)))
      continue
    }

    const base = sets * minimum * unitPrice
    const discount = promotion.discount_type === 'percent'
      ? base * num(promotion.discount_value) / 100
      : num(promotion.discount_value) * sets
    promoDiscount += Math.min(base, Math.max(0, discount))
  }

  promoDiscount = roundMoney(Math.min(subtotal, promoDiscount))
  const total = roundMoney(Math.max(0, subtotal - promoDiscount))
  const rate = Math.max(0, num(vatRate))
  const vat = roundMoney(total * rate / (100 + rate))
  const beforeVat = roundMoney(total - vat)
  const baseTotal = lines.reduce((sum, item) => sum + item.gross, 0)
  const items: PricedLine[] = lines.map(({ line, qty, gross }) => ({
    product_id: line.id,
    qty,
    unit_price: roundUnitPrice(Math.max(0, gross - (baseTotal ? promoDiscount * gross / baseTotal : 0)) / (qty || 1)),
    barcode: line.scannedBarcode,
    // ส่งประเภทที่เครื่องใช้ตีความไปด้วย เซิร์ฟเวอร์จะได้ตรวจซ้ำได้ว่าเข้าใจตรงกัน
    // ไม่ใช่มาเดาเองจากรูปร่างตัวเลขตอนรับบิลเข้า
    barcode_type: line.scannedBarcodeType,
  }))

  for (const promotion of promotions) {
    if (promotion.promo_type !== 'free_item' || !promotion.free_product_id || !promotion.free_product) continue
    const matchedQuantity = lines
      .filter((item) => item.line.id === promotion.product_id)
      .reduce((sum, item) => sum + item.qty, 0)
    const sets = Math.floor(matchedQuantity / Math.max(1, num(promotion.min_qty)))
    const freeQty = sets * Math.max(0, num(promotion.free_qty))
    if (freeQty > 0) {
      items.push({ product_id: promotion.free_product_id, qty: freeQty, unit_price: 0 })
    }
  }

  return { subtotal, promoDiscount, total, vat, beforeVat, items }
}

export function checkoutPayloadWithPricing(payload: CheckoutPayload, pricing: CartPricing): CheckoutPayload {
  return { ...payload, items: pricing.items }
}
