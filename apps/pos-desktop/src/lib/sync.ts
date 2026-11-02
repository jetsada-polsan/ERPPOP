import { repairDiscountedPayload } from './pricing'
import { api } from './api'
import { markQueue, queueItems } from './db'

let running = false

export async function syncCheckoutQueue(onChange?: () => void) {
  if (running) return
  running = true
  try {
    const pending = (await queueItems()).filter((item) => item.status !== 'synced')
    for (const item of pending) {
      await markQueue(item.id, 'syncing')
      onChange?.()
      try {
        const response = await api.checkout(item.id, repairDiscountedPayload(item.payload))
        await markQueue(item.id, 'synced', undefined, response.receipt_no)
      } catch (error) {
        const message = error instanceof Error ? error.message : String(error)
        if ((error as Error)?.name === 'PosUnreachableError') {
          await markQueue(item.id, 'failed', message)
          break
        }
        // ERP ปฏิเสธบิลนี้ เก็บไว้ตรวจแล้วส่งใบถัดไป ไม่ให้บิลเดียวบล็อกทั้งคิว
        await markQueue(item.id, 'needs_review', message)
      }
      onChange?.()
    }
  } finally {
    running = false
  }
}
