import { describe, expect, it } from 'vitest'
import { checkoutPayloadWithPricing, priceCart, promotionActive, repairDiscountedPayload } from './pricing'
import type { CartLine, CheckoutPayload, QtyPromotion } from './types'

const product = (id: number, price: number): CartLine => ({
  id,
  sku_code: String(id),
  name_th: `สินค้า ${id}`,
  pos_price: price,
  qty: 1,
  barcodes: [],
})

describe('POS Desktop pricing UAT', () => {
  it('applies bundle price and preserves the discounted checkout total', () => {
    const cart = [{ ...product(1, 40), qty: 3 }]
    const promotions: QtyPromotion[] = [{ id: 1, code: '3X100', name: '3 ชิ้น 100', promo_type: 'bundle_price', product_id: 1, min_qty: 3, bundle_price: 100 }]

    const result = priceCart(cart, promotions)

    expect(result.subtotal).toBe(120)
    expect(result.promoDiscount).toBe(20)
    expect(result.total).toBe(100)
    expect(result.items[0].unit_price).toBe(33.3333)
  })

  it('applies baht and percent promotions only to complete sets', () => {
    const cart = [{ ...product(1, 25), qty: 5 }]
    const baht: QtyPromotion = { id: 1, code: '2OFF10', name: 'ครบ 2 ลด 10', promo_type: 'discount', product_id: 1, min_qty: 2, discount_type: 'baht', discount_value: 10 }
    const percent: QtyPromotion = { id: 2, code: '2P10', name: 'ครบ 2 ลด 10%', promo_type: 'discount', product_id: 1, min_qty: 2, discount_type: 'percent', discount_value: 10 }

    expect(priceCart(cart, [baht]).promoDiscount).toBe(20)
    expect(priceCart(cart, [percent]).promoDiscount).toBe(10)
  })

  it('calculates included VAT from the post-promotion total', () => {
    const result = priceCart([{ ...product(1, 107), qty: 1 }], [], 7)

    expect(result.total).toBe(107)
    expect(result.vat).toBe(7)
    expect(result.beforeVat).toBe(100)
  })

  it('adds a qualifying free item to checkout without increasing the payable total', () => {
    const promotion: QtyPromotion = {
      id: 3,
      code: 'BUY2GET1',
      name: 'ซื้อ 2 แถม 1',
      promo_type: 'free_item',
      product_id: 1,
      min_qty: 2,
      free_product_id: 2,
      free_qty: 1,
      free_product: { id: 2, sku_code: '2', name_th: 'ของแถม' },
    }

    const result = priceCart([{ ...product(1, 50), qty: 4 }], [promotion])

    expect(result.total).toBe(200)
    expect(result.items).toContainEqual({ product_id: 2, qty: 2, unit_price: 0 })
  })

  it('keeps the server checkout contract and replaces only priced items', () => {
    const payload: CheckoutPayload = { branch_id: 1, shift_id: 2, cashier_id: 3, method: 'cash', payment_confirmed: true, vat_mode: 'included', items: [] }
    const pricing = priceCart([{ ...product(1, 40), qty: 3 }], [{ id: 1, code: '3X100', name: '3 ชิ้น 100', promo_type: 'bundle_price', product_id: 1, min_qty: 3, bundle_price: 100 }])

    expect(checkoutPayloadWithPricing(payload, pricing).items).toEqual(pricing.items)
    expect(checkoutPayloadWithPricing(payload, pricing).branch_id).toBe(1)
  })
})

describe('bill discount', () => {
  it('reduces the payable total without changing line prices', () => {
    const line = { id: 1, pos_price: 70, qty: 1 } as unknown as CartLine
    const pricing = priceCart([line], [], 7, new Date(), 10)
    expect(pricing.manualDiscount).toBe(10)
    expect(pricing.total).toBe(60)
    expect(pricing.items[0].unit_price).toBe(60)
  })

  it('repairs queued bills that still carry the full price', () => {
    const payload = { manual_discount_amount: 10, items: [{ product_id: 1, qty: 1, unit_price: 70 }] } as unknown as CheckoutPayload
    expect(repairDiscountedPayload(payload).items[0].unit_price).toBe(60)
    expect(repairDiscountedPayload(repairDiscountedPayload(payload)).items[0].unit_price).toBe(60)
  })
})

describe('promotion time window', () => {
  const promotion = { id: 1, code: 'P', name: 'x', promo_type: 'bundle_price', product_id: 1, min_qty: 3, bundle_price: 100,
    starts_at: '2026-11-02T05:00:00Z', ends_at: '2026-11-03T05:00:00Z' } as const

  it('applies only between the start and end time', () => {
    expect(promotionActive(promotion, new Date('2026-11-02T04:59:00Z'))).toBe(false)
    expect(promotionActive(promotion, new Date('2026-11-02T05:00:00Z'))).toBe(true)
    expect(promotionActive(promotion, new Date('2026-11-03T05:01:00Z'))).toBe(false)
  })
})

describe('bill promotions', () => {
  const billOff: QtyPromotion = { id: 9, code: 'B1000', name: 'ครบ 1000 ลด 50', promo_type: 'bill_discount', product_id: null, min_qty: 1, min_amount: 1000, discount_type: 'baht', discount_value: 50 }

  it('takes a bill discount once the bill reaches the minimum', () => {
    expect(priceCart([{ ...product(1, 100), qty: 10 }], [billOff]).total).toBe(950)
    expect(priceCart([{ ...product(1, 100), qty: 9 }], [billOff]).total).toBe(900)
  })

  it('caps a percent bill discount', () => {
    const pct: QtyPromotion = { ...billOff, discount_type: 'percent', discount_value: 10, max_discount: 60, min_amount: 500 }
    expect(priceCart([{ ...product(1, 100), qty: 10 }], [pct]).total).toBe(940)
  })

  it('adds a bill gift at zero price and lists it for the cashier', () => {
    const gift: QtyPromotion = { id: 10, code: 'G500', name: 'ครบ 500 แถมน้ำ', promo_type: 'bill_free_item', product_id: null, min_qty: 1, min_amount: 500, free_product_id: 2, free_qty: 1, free_product: { id: 2, sku_code: '2', name_th: 'น้ำ' } }
    const result = priceCart([{ ...product(1, 100), qty: 5 }], [gift])
    expect(result.total).toBe(500)
    expect(result.items).toContainEqual({ product_id: 2, qty: 1, unit_price: 0 })
    expect(result.freeItems[0]).toMatchObject({ name_th: 'น้ำ', qty: 1 })
  })
})

describe('mix and add-on promotions', () => {
  it('prices any three from the group at the set price', () => {
    const mix: QtyPromotion = { id: 20, code: 'MIX3', name: 'คละ 3 ชิ้น 100', promo_type: 'mix_bundle', product_id: null, product_ids: [1, 2], min_qty: 3, bundle_price: 100 }
    const result = priceCart([{ ...product(1, 40), qty: 2 }, { ...product(2, 35), qty: 2 }], [mix])
    // 40+40+35 = 115 -> 100 (ลด 15) ชิ้นที่ 4 ราคาปกติ 35
    expect(result.total).toBe(135)
  })

  it('gives the cheapest item free for buy three get one from the group', () => {
    const mix: QtyPromotion = { id: 21, code: 'MIXF', name: 'คละ 3 แถม 1', promo_type: 'mix_free', product_id: null, product_ids: [1, 2], min_qty: 3, free_qty: 1 }
    expect(priceCart([{ ...product(1, 40), qty: 3 }, { ...product(2, 30), qty: 1 }], [mix]).total).toBe(120)
  })

  it('sells the add-on at the special price once the rest of the bill reaches the minimum', () => {
    const addon: QtyPromotion = { id: 22, code: 'ADD', name: 'ครบ 300 แลกซื้อ 19', promo_type: 'bill_addon', product_id: 2, min_qty: 1, min_amount: 300, bundle_price: 19, free_qty: 1 }
    expect(priceCart([{ ...product(1, 100), qty: 3 }, { ...product(2, 50), qty: 2 }], [addon]).total).toBe(369)
    expect(priceCart([{ ...product(1, 100), qty: 2 }, { ...product(2, 50), qty: 1 }], [addon]).total).toBe(250)
  })
})
