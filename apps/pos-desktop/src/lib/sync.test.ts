import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from './api'
import { markQueue, queueItems } from './db'
import { syncCheckoutQueue } from './sync'
import type { QueueItem } from './types'

vi.mock('./api', () => ({
  api: { checkout: vi.fn() },
}))

vi.mock('./db', () => ({
  markQueue: vi.fn(),
  queueItems: vi.fn(),
}))

const queueItem = (id: string, status: QueueItem['status'] = 'pending'): QueueItem => ({
  id,
  status,
  attempts: 0,
  createdAt: '2026-07-29T05:00:00.000Z',
  payload: {
    branch_id: 1,
    shift_id: 2,
    cashier_id: 3,
    method: 'cash',
    payment_confirmed: true,
    vat_mode: 'included',
    items: [{ product_id: 1, qty: 1, unit_price: 100 }],
  },
})

describe('POS Desktop sync UAT', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('sends pending and failed bills, marks synced with server receipt number', async () => {
    vi.mocked(queueItems).mockResolvedValue([queueItem('pending-1'), queueItem('failed-1', 'failed'), queueItem('synced-1', 'synced')])
    vi.mocked(api.checkout).mockResolvedValue({ receipt_no: 'INV-0001' })

    await syncCheckoutQueue()

    expect(api.checkout).toHaveBeenCalledTimes(2)
    expect(markQueue).toHaveBeenNthCalledWith(1, 'pending-1', 'syncing')
    expect(markQueue).toHaveBeenNthCalledWith(2, 'pending-1', 'synced', undefined, 'INV-0001')
    expect(markQueue).toHaveBeenNthCalledWith(3, 'failed-1', 'syncing')
    expect(markQueue).toHaveBeenNthCalledWith(4, 'failed-1', 'synced', undefined, 'INV-0001')
  })

  it('stops after a failed request and leaves later bills for the next retry', async () => {
    vi.mocked(queueItems).mockResolvedValue([queueItem('first'), queueItem('second')])
    vi.mocked(api.checkout).mockRejectedValue(Object.assign(new Error('offline'), { name: 'PosUnreachableError' }))

    await syncCheckoutQueue()

    expect(api.checkout).toHaveBeenCalledTimes(1)
    expect(markQueue).toHaveBeenNthCalledWith(1, 'first', 'syncing')
    expect(markQueue).toHaveBeenNthCalledWith(2, 'first', 'failed', 'offline')
  })

  it('holds a rejected bill for review and keeps sending the next bills', async () => {
    vi.mocked(queueItems).mockResolvedValue([queueItem('first'), queueItem('second')])
    vi.mocked(api.checkout).mockRejectedValueOnce(new Error('ราคาไม่ตรง')).mockResolvedValueOnce({ receipt_no: 'R2' })

    await syncCheckoutQueue()

    expect(api.checkout).toHaveBeenCalledTimes(2)
    expect(markQueue).toHaveBeenCalledWith('first', 'needs_review', 'ราคาไม่ตรง')
    expect(markQueue).toHaveBeenCalledWith('second', 'synced', undefined, 'R2')
  })

  it('does not run two sync loops at the same time', async () => {
    let release!: (value: { receipt_no: string }) => void
    vi.mocked(queueItems).mockResolvedValue([queueItem('only-one')])
    vi.mocked(api.checkout).mockImplementation(() => new Promise((resolve) => { release = resolve }))

    const first = syncCheckoutQueue()
    await Promise.resolve()
    const second = syncCheckoutQueue()
    await second
    expect(api.checkout).toHaveBeenCalledTimes(1)
    release({ receipt_no: 'INV-LOCK' })
    await first
    expect(markQueue).toHaveBeenCalledWith('only-one', 'synced', undefined, 'INV-LOCK')
  })
})
