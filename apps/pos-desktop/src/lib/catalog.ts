import type { Product } from './types'

/** Resolve a pre-downloaded, server-approved price schedule without network access. */
export function productAtSaleTime(product: Product, at: Date, barcode?: string): Product {
  const time = at.getTime()
  const barcodeUnit = barcode ? product.barcodes.find((row) => row.barcode === barcode)?.unit_id : undefined
  const matching = (product.scheduled_prices || [])
    .filter((schedule) => {
      const starts = new Date(schedule.effective_from).getTime()
      const ends = schedule.effective_to ? new Date(schedule.effective_to).getTime() : Number.POSITIVE_INFINITY
      const unitMatches = barcodeUnit == null
        ? schedule.unit_id == null
        : schedule.unit_id === barcodeUnit || schedule.unit_id == null
      return unitMatches && starts <= time && time < ends
    })
    .sort((a, b) => new Date(b.effective_from).getTime() - new Date(a.effective_from).getTime())
  const active = matching[0]
  const base = Number(product.base_pos_price ?? product.pos_price)

  return active ? { ...product, pos_price: Number(active.price), normal_price: Number(active.price) } : { ...product, pos_price: base, normal_price: base }
}
