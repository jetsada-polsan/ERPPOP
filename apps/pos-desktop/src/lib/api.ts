import { fetch as tauriFetch } from '@tauri-apps/plugin-http'
import { invoke } from '@tauri-apps/api/core'
import type { Cashier, CheckoutPayload, DeviceProfile, HeldBill, Product, QtyPromotion, Shift } from './types'

let baseUrl = ''

export function setServerUrl(url: string) {
  baseUrl = url.replace(/\/$/, '')
}

async function token(): Promise<string> {
  return invoke<string>('read_device_token')
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const secret = await token()
  const response = await tauriFetch(`${baseUrl}/api/pos${path}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${secret}`,
      ...init.headers,
    },
  })
  const data = await response.json().catch(() => ({})) as Record<string, unknown>
  if (!response.ok) throw new Error(String(data.message || `HTTP ${response.status}`))
  return data as T
}

export async function connect(serverUrl: string, deviceToken: string): Promise<DeviceProfile> {
  setServerUrl(serverUrl)
  await invoke('save_device_token', { token: deviceToken })
  const result = await request<any>('/ping')
  return {
    serverUrl: serverUrl.replace(/\/$/, ''),
    deviceName: result.device.name,
    terminalCode: result.device.terminal_code || '',
    branchId: result.branch_id,
    branchName: result.branch_name || '',
    vatRate: Number(result.vat_rate || 7),
    company: result.company || {},
    receiptTemplate: result.receipt_template,
    hardwareProfile: result.hardware_profile,
  }
}

export const api = {
  ping: () => request<any>('/ping'),
  cashiers: async () => (await request<{ cashiers: Cashier[] }>('/cashiers')).cashiers,
  cashierLogin: (pin: string, cashierId?: number, code?: string) => request<{ cashier?: Cashier; cashiers?: Cashier[]; selection_required?: boolean; must_change_pin?: boolean; offline_credential?: Cashier['offline_credential'] }>('/cashier/login', { method: 'POST', body: JSON.stringify({ pin, ...(cashierId ? { cashier_id: cashierId } : {}), ...(code ? { code } : {}) }) }),
  changeCashierPin: (code: string, currentPin: string, newPin: string) => request<{ message: string }>('/cashier/pin', { method: 'POST', body: JSON.stringify({ code, current_pin: currentPin, new_pin: newPin }) }),
  products: (branchId: number) => request<Product[]>(`/products?all=1&branch_id=${branchId}`),
  promotions: (branchId: number) => request<QtyPromotion[]>(`/promotions?branch_id=${branchId}`),
  activeShift: async (branchId: number, cashierId: number) => (await request<{ shift: Shift | null }>(`/shift?branch_id=${branchId}&cashier_id=${cashierId}`)).shift,
  openShift: async (branchId: number, cashierId: number, openingCash: number) => (await request<{ shift: Shift }>('/shift/open', { method: 'POST', body: JSON.stringify({ branch_id: branchId, cashier_id: cashierId, opening_cash: openingCash }) })).shift,
  closeShift: async (shiftId: number, countedCash: number) => (await request<{ shift: Shift }>('/shift/close', { method: 'POST', body: JSON.stringify({ shift_id: shiftId, counted_cash: countedCash }) })).shift,
  heldBills: async (branchId: number, cashierId: number) => (await request<{ held_bills: HeldBill[] }>(`/held-bills?branch_id=${branchId}&cashier_id=${cashierId}`)).held_bills,
  holdBill: (payload: Record<string, unknown>) => request<{ held_bill_id: number; message: string }>('/held-bills', { method: 'POST', body: JSON.stringify(payload) }),
  resumeHeldBill: async (id: number) => (await request<{ held_bill: HeldBill }>(`/held-bills/${id}/resume`, { method: 'POST' })).held_bill,
  cashMovement: (payload: Record<string, unknown>) => request<{ shift: Shift; message: string }>('/shift/cash-movement', { method: 'POST', body: JSON.stringify(payload) }),
  checkout: (id: string, payload: CheckoutPayload) => request<any>('/checkout', { method: 'POST', headers: { 'Idempotency-Key': id }, body: JSON.stringify(payload) }),
}
