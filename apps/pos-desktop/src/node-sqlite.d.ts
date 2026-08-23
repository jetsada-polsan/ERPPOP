/**
 * ส่วนของ node:sqlite เท่าที่เทสต์ migration ใช้
 *
 * โมดูลนี้มากับ Node 22 แต่ @types/node ที่ล็อกไว้ยังไม่มีนิยามให้
 * และเพิ่ม dependency ไม่ได้เพราะ release build ติดตั้งด้วย --frozen-lockfile
 * จึงประกาศเท่าที่ใช้จริง แทนที่จะปิดการตรวจทั้งไฟล์ด้วย ts-ignore
 */
declare module 'node:module' {
  export function createRequire(path: string | URL): (id: string) => unknown
}

declare module 'node:sqlite' {
  export class DatabaseSync {
    constructor(path: string)
    exec(sql: string): void
    prepare(sql: string): {
      run(...params: unknown[]): { changes: number; lastInsertRowid: number }
      get(...params: unknown[]): unknown
      all(...params: unknown[]): unknown[]
    }
    close(): void
  }
}
