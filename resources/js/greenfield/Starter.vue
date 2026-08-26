<script setup lang="ts">
import { ref, computed } from 'vue'

// ตัวอย่างจริงเล็ก ๆ: ตะกร้าเดโมให้เห็นว่า Vue + Tailwind + โทน JET ทำงานครบ
const items = ref([
  { name: 'หมูสามชั้นสไลซ์', price: 189, qty: 1 },
  { name: 'น้ำจิ้มสุกี้ POPSTAR', price: 69, qty: 2 },
])
const note = ref('')
const total = computed(() => items.value.reduce((s, i) => s + i.price * i.qty, 0))
const money = (n: number) => n.toLocaleString('th-TH', { minimumFractionDigits: 2 })
function bump(i: number, d: number) { items.value[i].qty = Math.max(1, items.value[i].qty + d) }
</script>

<template>
  <main class="mx-auto max-w-2xl px-6 py-10 font-sans text-ink">
    <header class="mb-8">
      <p class="text-xs font-semibold uppercase tracking-widest text-brand-ink">PopCentral · Greenfield</p>
      <h1 class="mt-1 text-3xl font-extrabold text-brand-dark">โครงเริ่มต้นโมดูลใหม่</h1>
      <p class="mt-2 text-muted">หน้านี้เขียนด้วย Vue 3 + Tailwind v4 ล้วน ผูกโทนสีกับ JET ผ่าน design token
        เป็นแพตเทิร์นสำหรับโมดูลใหม่ ไม่แตะหลังบ้านเดิม</p>
    </header>

    <section class="rounded-xl border border-line bg-white shadow-sm">
      <h2 class="border-b border-line px-5 py-3 text-sm font-bold text-brand-dark">ตัวอย่างตะกร้า</h2>
      <ul class="divide-y divide-line">
        <li v-for="(item, i) in items" :key="item.name" class="flex items-center gap-4 px-5 py-3">
          <div class="flex-1">
            <p class="font-semibold">{{ item.name }}</p>
            <p class="text-sm text-muted">{{ money(item.price) }} ฿ / หน่วย</p>
          </div>
          <div class="flex items-center gap-2">
            <button @click="bump(i, -1)" class="h-8 w-8 rounded-lg border border-line text-lg font-bold text-brand-ink hover:bg-brand-soft">−</button>
            <span class="w-8 text-center font-bold tabular-nums">{{ item.qty }}</span>
            <button @click="bump(i, 1)" class="h-8 w-8 rounded-lg border border-line text-lg font-bold text-brand-ink hover:bg-brand-soft">+</button>
          </div>
          <span class="w-24 text-right font-bold tabular-nums text-brand-dark">{{ money(item.price * item.qty) }}</span>
        </li>
      </ul>
      <div class="flex items-center justify-between border-t border-line px-5 py-4">
        <span class="text-sm font-semibold text-muted">ยอดรวม</span>
        <span class="text-2xl font-extrabold tabular-nums text-brand-dark">{{ money(total) }} ฿</span>
      </div>
    </section>

    <label class="mt-6 block">
      <span class="text-sm font-semibold text-ink">หมายเหตุ</span>
      <input v-model="note" type="text" placeholder="พิมพ์เพื่อทดสอบ binding…"
        class="mt-1 w-full rounded-lg border border-line px-3 py-2 outline-none focus:border-brand focus:ring-2 focus:ring-brand/25" />
    </label>

    <div class="mt-6 flex gap-3">
      <button class="rounded-lg bg-brand-ink px-5 py-2.5 font-bold text-white hover:bg-brand-dark">ปุ่มหลัก</button>
      <button class="rounded-lg border border-line bg-white px-5 py-2.5 font-bold text-ink hover:bg-brand-soft">ปุ่มรอง</button>
      <span v-if="note" class="self-center rounded-md bg-success/10 px-3 py-1 text-sm font-semibold text-success">พิมพ์แล้ว: {{ note }}</span>
    </div>
  </main>
</template>
