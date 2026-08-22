import { describe, expect, it } from 'vitest'
import { normalizeServerUrl } from './server-url'

describe('normalizeServerUrl', () => {
  it('เติม http:// ให้เมื่อพนักงานพิมพ์แต่ IP', () => {
    expect(normalizeServerUrl('27.254.143.219')).toBe('http://27.254.143.219')
  })

  it('ตัดช่องว่างที่ติดมาจากการ copy', () => {
    expect(normalizeServerUrl('  http://27.254.143.219 ')).toBe('http://27.254.143.219')
    expect(normalizeServerUrl('http://27.254.143. 219')).toBe('http://27.254.143.219')
  })

  it('ตัด / ปิดท้าย และ path ของ API ที่วางติดมา', () => {
    expect(normalizeServerUrl('http://27.254.143.219/')).toBe('http://27.254.143.219')
    expect(normalizeServerUrl('http://27.254.143.219/api')).toBe('http://27.254.143.219')
    expect(normalizeServerUrl('http://27.254.143.219/api/pos')).toBe('http://27.254.143.219')
    expect(normalizeServerUrl('http://27.254.143.219/api/pos/')).toBe('http://27.254.143.219')
  })

  it('เก็บ port และ sub-path ของ ERP ไว้', () => {
    expect(normalizeServerUrl('http://erp.local:8080/jeterp/')).toBe('http://erp.local:8080/jeterp')
    expect(normalizeServerUrl('https://erp.example.com')).toBe('https://erp.example.com')
  })

  it('ทิ้ง query/hash ที่ไม่เกี่ยวออก', () => {
    expect(normalizeServerUrl('http://27.254.143.219/?x=1#y')).toBe('http://27.254.143.219')
  })

  it('ฟ้องเมื่อว่างหรือใช้ไม่ได้ แทนที่จะปล่อยไปพังตอน fetch', () => {
    expect(() => normalizeServerUrl('')).toThrow('กรุณาระบุที่อยู่เซิร์ฟเวอร์ ERP')
    expect(() => normalizeServerUrl('   ')).toThrow('กรุณาระบุที่อยู่เซิร์ฟเวอร์ ERP')
    expect(() => normalizeServerUrl('http://')).toThrow('ที่อยู่เซิร์ฟเวอร์ไม่ถูกต้อง')
  })
})
