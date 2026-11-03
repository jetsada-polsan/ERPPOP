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
  manualDiscount: number
  total: number
  vat: number
  beforeVat: number
  items: PricedLine[]
  /** ของแถมที่ส่งเข้า ERP ราคา 0 แสดงในตะกร้าและใบเสร็จด้วย ไม่งั้นแคชเชียร์ไม่รู้ว่าต้องหยิบให้ลูกค้า */
  freeItems: Array<{ product_id: number; sku_code: string; name_th: string; qty: number; promotion: string }>
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

/** โปรมีผลตามเวลาขายจริง ใช้ได้ทั้งออนไลน์และออฟไลน์จากข้อมูลที่ sync ไว้ */
export function promotionActive(promotion: QtyPromotion, now = new Date()) {
  const startsAt = promotion.starts_at ? new Date(promotion.starts_at) : null
  const endsAt = promotion.ends_at ? new Date(promotion.ends_at) : null

  return (!startsAt || startsAt <= now) && (!endsAt || endsAt >= now)
}

export function priceCart(cart: CartLine[], allPromotions: QtyPromotion[], vatRate = 7, now = new Date(), billDiscount = 0): CartPricing {
  const promotions = allPromotions.filter((promotion) => promotionActive(promotion, now))
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

  // โปรต่อบิล: ยอดหลังโปรต่อสินค้าต้องถึงขั้นต่ำ (คิดแบบเดียวกับ PosPricingGuard)
  const paid = subtotal - promoDiscount
  const billGifts: QtyPromotion[] = []
  for (const promotion of promotions) {
    if (promotion.promo_type !== 'bill_discount' && promotion.promo_type !== 'bill_free_item') continue
    if (paid + 0.0001 < num(promotion.min_amount)) continue
    if (promotion.promo_type === 'bill_free_item') { billGifts.push(promotion); continue }
    let discount = promotion.discount_type === 'percent' ? paid * num(promotion.discount_value) / 100 : num(promotion.discount_value)
    if (promotion.max_discount != null) discount = Math.min(discount, num(promotion.max_discount))
    promoDiscount += Math.min(paid, Math.max(0, discount))
  }

  promoDiscount = roundMoney(Math.min(subtotal, promoDiscount))
  const manualDiscount = roundMoney(Math.min(subtotal - promoDiscount, Math.max(0, num(billDiscount))))
  const total = roundMoney(Math.max(0, subtotal - promoDiscount - manualDiscount))
  const rate = Math.max(0, num(vatRate))
  const vat = roundMoney(total * rate / (100 + rate))
  const beforeVat = roundMoney(total - vat)
  const baseTotal = lines.reduce((sum, item) => sum + item.gross, 0)
  const items: PricedLine[] = lines.map(({ line, qty, gross }) => ({
    product_id: line.id,
    qty,
    unit_price: roundUnitPrice(Math.max(0, gross - (baseTotal ? (promoDiscount + manualDiscount) * gross / baseTotal : 0)) / (qty || 1)),
    barcode: line.scannedBarcode,
    // ส่งประเภทที่เครื่องใช้ตีความไปด้วย เซิร์ฟเวอร์จะได้ตรวจซ้ำได้ว่าเข้าใจตรงกัน
    // ไม่ใช่มาเดาเองจากรูปร่างตัวเลขตอนรับบิลเข้า
    barcode_type: line.scannedBarcodeType,
  }))

  const freeItems: CartPricing['freeItems'] = []
  for (const promotion of promotions) {
    if (promotion.promo_type !== 'free_item' || !promotion.free_product_id || !promotion.free_product) continue
    const matchedQuantity = lines
      .filter((item) => item.line.id === promotion.product_id)
      .reduce((sum, item) => sum + item.qty, 0)
    const sets = Math.floor(matchedQuantity / Math.max(1, num(promotion.min_qty)))
    const freeQty = sets * Math.max(0, num(promotion.free_qty))
    if (freeQty > 0) {
      items.push({ product_id: promotion.free_product_id, qty: freeQty, unit_price: 0 })
      freeItems.push({ product_id: promotion.free_product_id, sku_code: promotion.free_product.sku_code, name_th: promotion.free_product.name_th, qty: freeQty, promotion: promotion.name })
    }
  }

  for (const promotion of billGifts) {
    if (!promotion.free_product_id || !promotion.free_product) continue
    const freeQty = Math.max(0, num(promotion.free_qty))
    if (freeQty <= 0) continue
    items.push({ product_id: promotion.free_product_id, qty: freeQty, unit_price: 0 })
    freeItems.push({ product_id: promotion.free_product_id, sku_code: promotion.free_product.sku_code, name_th: promotion.free_product.name_th, qty: freeQty, promotion: promotion.name })
  }

  return { subtotal, promoDiscount, manualDiscount, total, vat, beforeVat, items, freeItems }
}

/** บิลในคิวจากรุ่น 1.5.5-1.5.6 ส่งราคาเต็มพร้อมส่วนลด ERP จึงปฏิเสธ ปรับเป็นราคาสุทธิก่อนส่งซ้ำ */
export function repairDiscountedPayload(payload: CheckoutPayload): CheckoutPayload {
  const discount = num(payload.manual_discount_amount)
  if (discount <= 0 || payload.net_items) return payload
  const sum = payload.items.reduce((total, item) => total + num(item.qty) * num(item.unit_price), 0)
  if (sum <= 0) return payload
  const factor = Math.max(0, sum - discount) / sum

  return {
    ...payload,
    net_items: true,
    items: payload.items.map((item) => ({ ...item, unit_price: roundUnitPrice(num(item.unit_price) * factor) })),
  }
}

export function checkoutPayloadWithPricing(payload: CheckoutPayload, pricing: CartPricing): CheckoutPayload {
  return { ...payload, items: pricing.items }
}
