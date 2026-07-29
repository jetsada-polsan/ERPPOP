import { describe, expect, it } from 'vitest'
import { checkoutPayloadWithPricing, priceCart } from './pricing'
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
