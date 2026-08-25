<script setup lang="ts">
import { computed, ref } from 'vue';

interface LauncherItem {
    label: string;
    icon: string;
    tone: string;
    url: string;
    target: string | null;
}

interface LauncherSection {
    label: string;
    title: string;
    icon: string;
    items: LauncherItem[];
}

const props = defineProps<{ sections: LauncherSection[] }>();

const query = ref('');
const activeLabel = ref<string | null>(null);

const normalised = (value: string) => value.toLowerCase().trim();

/** ค้นหาข้ามทุกหมวดเสมอ — ของที่หาอยู่มักไม่ได้อยู่ในหมวดที่เปิดค้างไว้ */
const matching = computed(() => {
    const q = normalised(query.value);

    return props.sections
        .map((section) => ({
            ...section,
            items: q ? section.items.filter((item) => normalised(item.label).includes(q)) : section.items,
        }))
        .filter((section) => section.items.length > 0);
});

/** ตอนค้นหาให้แสดงทุกหมวดที่ตรง ไม่งั้นแสดงเฉพาะหมวดที่เลือก */
const visible = computed(() => {
    if (normalised(query.value)) {
        return matching.value;
    }

    if (!activeLabel.value) {
        return matching.value;
    }

    return matching.value.filter((section) => section.label === activeLabel.value);
});

const total = computed(() => matching.value.reduce((sum, section) => sum + section.items.length, 0));

const countFor = (label: string) =>
    props.sections.find((section) => section.label === label)?.items.length ?? 0;

function pick(label: string | null) {
    activeLabel.value = label;
    query.value = '';
}
</script>

<template>
    <div class="al">
        <aside class="al-side">
            <p class="al-side-head">หมวดโมดูล</p>
            <button type="button" class="al-cat" :class="{ on: activeLabel === null }" @click="pick(null)">
                <i class="bi bi-grid-3x3-gap-fill"></i>
                <span>ทั้งหมด</span>
                <em>{{ sections.reduce((sum, section) => sum + section.items.length, 0) }}</em>
            </button>
            <button
                v-for="section in sections"
                :key="section.label"
                type="button"
                class="al-cat"
                :class="{ on: activeLabel === section.label }"
                @click="pick(section.label)"
            >
                <i class="bi" :class="section.icon"></i>
                <span>{{ section.title }}</span>
                <em>{{ countFor(section.label) }}</em>
            </button>
        </aside>

        <div class="al-main">
            <div class="al-bar">
                <div class="al-search">
                    <label for="al-q" class="visually-hidden">ค้นหาโมดูล</label>
                    <input id="al-q" v-model="query" type="search" placeholder="ค้นหาโมดูล…" autocomplete="off">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </div>
                <span class="al-count">{{ total }} รายการ</span>
            </div>

            <div v-if="visible.length === 0" class="al-empty">
                <i class="bi bi-search"></i>
                <p>ไม่พบโมดูลที่ตรงกับ “{{ query }}”</p>
            </div>

            <section v-for="section in visible" :key="section.label" class="al-group">
                <h2 class="al-group-title">
                    <i class="bi" :class="section.icon"></i>{{ section.title }}
                </h2>
                <div class="al-grid">
                    <a
                        v-for="item in section.items"
                        :key="item.url + item.label"
                        class="al-card"
                        :href="item.url"
                        :target="item.target ?? undefined"
                    >
                        <span class="al-card-ico"><i class="bi" :class="item.icon"></i></span>
                        <span class="al-card-label">{{ item.label }}</span>
                    </a>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
/* สีทั้งหมดอ้าง token ของ JET ERP ไม่ประกาศสีดิบเอง
   สลับธีมหรือปรับขนาดตัวอักษรแล้วหน้านี้จึงตามไปด้วย */
.al { display: grid; grid-template-columns: 232px 1fr; gap: 16px; align-items: start; }

.al-side {
    background: var(--erp-surface, #fff);
    border: 1px solid var(--erp-border, #dbe7ef);
    border-radius: 10px;
    padding: 8px;
    position: sticky;
    top: 96px;
}
.al-side-head {
    font-size: 11px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase;
    color: var(--erp-muted, #627481); margin: 6px 8px 8px;
}
.al-cat {
    display: flex; align-items: center; gap: 9px; width: 100%;
    background: none; border: 0; border-left: 3px solid transparent; border-radius: 6px;
    padding: 7px 10px; font: inherit; font-size: 13.5px; color: var(--erp-text, #1d3b52);
    text-align: left; cursor: pointer;
}
.al-cat:hover { background: var(--erp-primary-soft, #eef4f9); }
.al-cat.on {
    background: var(--erp-primary-soft, #eef4f9);
    color: var(--erp-primary-dark, #0f4c75);
    border-left-color: var(--erp-primary, #1585c0);
    font-weight: 600;
}
.al-cat i { font-size: 14px; width: 17px; text-align: center; opacity: .65; flex: none; }
.al-cat.on i { opacity: 1; color: var(--erp-primary, #1585c0); }
.al-cat span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.al-cat em {
    font-style: normal; font-size: 11.5px; color: var(--erp-muted, #627481);
    background: var(--erp-surface-2, #f8fbfd); border-radius: 999px; padding: 1px 8px; flex: none;
}

.al-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
.al-search { position: relative; flex: 1; max-width: 380px; }
.al-search input {
    width: 100%; font: inherit; font-size: 13.5px; color: var(--erp-text, #1d3b52);
    background: var(--erp-surface, #fff); border: 1px solid var(--erp-border, #dbe7ef);
    border-radius: 8px; padding: 8px 34px 8px 12px;
}
.al-search input:focus {
    outline: 0; border-color: var(--erp-primary, #1585c0);
    box-shadow: 0 0 0 3px rgba(21, 133, 192, .18);
}
.al-search i {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    color: var(--erp-muted, #627481); font-size: 13px; pointer-events: none;
}
.al-count { font-size: 12.5px; color: var(--erp-muted, #627481); }

.al-group { margin-bottom: 22px; }
.al-group-title {
    display: flex; align-items: center; gap: 8px;
    font-size: 14px; font-weight: 700; color: var(--erp-primary-dark, #0f4c75);
    margin: 0 0 10px; padding-bottom: 7px; border-bottom: 1px solid var(--erp-border, #dbe7ef);
}
.al-group-title i { opacity: .7; }

.al-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(168px, 1fr)); gap: 10px; }
.al-card {
    display: flex; flex-direction: column; align-items: center; gap: 9px;
    background: var(--erp-surface, #fff); border: 1px solid var(--erp-border, #dbe7ef);
    border-radius: 10px; padding: 16px 12px; text-decoration: none;
    color: var(--erp-text, #1d3b52); text-align: center;
    box-shadow: 0 1px 3px rgba(29, 59, 82, .06);
    transition: border-color .12s, box-shadow .12s, transform .12s;
}
.al-card:hover {
    border-color: var(--erp-primary, #1585c0);
    box-shadow: 0 6px 18px -8px rgba(15, 76, 117, .4);
    transform: translateY(-1px);
    color: var(--erp-primary-dark, #0f4c75);
}
.al-card:focus-visible { outline: 2px solid var(--erp-primary, #1585c0); outline-offset: 2px; }
.al-card-ico {
    width: 42px; height: 42px; border-radius: 11px; display: grid; place-items: center;
    background: var(--erp-primary-soft, #eef4f9); color: var(--erp-primary-ink, #147db5); font-size: 19px;
}
.al-card:hover .al-card-ico { background: var(--erp-primary, #1585c0); color: #fff; }
.al-card-label { font-size: 13px; font-weight: 600; line-height: 1.35; }

.al-empty { text-align: center; padding: 48px 16px; color: var(--erp-muted, #627481); }
.al-empty i { font-size: 30px; opacity: .4; display: block; margin-bottom: 10px; }
.al-empty p { margin: 0; font-size: 13.5px; }

@media (max-width: 900px) {
    .al { grid-template-columns: 1fr; }
    .al-side { position: static; display: flex; gap: 6px; overflow-x: auto; }
    .al-side-head { display: none; }
    .al-cat { width: auto; white-space: nowrap; border-left: 0; border-bottom: 3px solid transparent; }
    .al-cat.on { border-left: 0; border-bottom-color: var(--erp-primary, #1585c0); }
}
@media (prefers-reduced-motion: reduce) { .al-card { transition: none; } }
</style>
