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
        const response = await api.checkout(item.id, item.payload)
        await markQueue(item.id, 'synced', undefined, response.receipt_no)
      } catch (error) {
        await markQueue(item.id, 'failed', error instanceof Error ? error.message : String(error))
        break
      }
      onChange?.()
    }
  } finally {
    running = false
  }
}
