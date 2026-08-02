import { describe, expect, it } from 'vitest'
import { productAtSaleTime } from './catalog'
import type { Product } from './types'

const product: Product = {
  id: 1, sku_code: 'P1', name_th: 'สินค้าทดสอบ', pos_price: 100, base_pos_price: 100, barcodes: [],
  scheduled_prices: [{ id: 1, price: 80, effective_from: '2026-08-02T13:00:00+07:00', effective_to: '2026-08-02T15:00:00+07:00' }],
}

describe('POS scheduled catalog', () => {
  it('activates a downloaded price only inside its approved time window', () => {
    expect(productAtSaleTime(product, new Date('2026-08-02T12:59:59+07:00')).pos_price).toBe(100)
    expect(productAtSaleTime(product, new Date('2026-08-02T13:00:00+07:00')).pos_price).toBe(80)
    expect(productAtSaleTime(product, new Date('2026-08-02T15:00:00+07:00')).pos_price).toBe(100)
  })
})
