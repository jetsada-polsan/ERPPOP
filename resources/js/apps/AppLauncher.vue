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

const toneColors: Record<string, string> = {
    blue: '#1687c8', cyan: '#0891b2', teal: '#0f9f91', green: '#159a68',
    orange: '#d97706', amber: '#e0a11a', red: '#d94b5b', pink: '#c65086',
    indigo: '#5b68c7', purple: '#7959b8', slate: '#64748b', brown: '#a46a42',
};

function accentFor(tone: string | undefined, fallback = '#1687c8') {
    return toneColors[tone ?? ''] ?? fallback;
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
                    <em>{{ section.items.length }}</em>
                </h2>
                <div class="al-grid">
                    <a
                        v-for="item in section.items"
                        :key="item.url + item.label"
                        class="al-card"
                        :class="'t-' + item.tone"
                        :href="item.url"
                        :target="item.target ?? undefined"
                        :style="{ '--al-accent': accentFor(item.tone, accentFor(section.label)) }"
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
.al{display:grid;grid-template-columns:236px 1fr;gap:18px;align-items:start;max-width:1500px;margin:0 auto}

/* ── หมวดด้านซ้าย ─────────────────────────────────────────── */
.al-side{background:var(--erp-surface);border:1px solid var(--erp-border);border-radius:12px;
  padding:8px;position:sticky;top:16px;box-shadow:0 1px 3px rgba(29,59,82,.05)}
.al-side-head{font-size:10.5px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;
  color:var(--erp-muted);margin:8px 10px 8px}
.al-cat{display:flex;align-items:center;gap:10px;width:100%;background:none;border:0;
  border-radius:8px;padding:8px 10px;font:inherit;font-size:13.5px;color:var(--erp-text);
  text-align:left;cursor:pointer;position:relative;margin-bottom:1px}
.al-cat:hover{background:var(--erp-primary-soft)}
.al-cat.on{background:var(--erp-primary-soft);color:var(--erp-primary-dark);font-weight:600}
/* เส้น accent เป็นแท่งมนสั้น ๆ ไม่ใช่ขอบเต็มความสูง ดูสะอาดกว่า */
.al-cat.on::before{content:"";position:absolute;left:0;top:50%;transform:translateY(-50%);
  width:3px;height:18px;border-radius:0 3px 3px 0;background:var(--erp-primary)}
.al-cat i{font-size:14px;width:18px;text-align:center;opacity:.55;flex:none}
.al-cat.on i{opacity:1;color:var(--erp-primary)}
.al-cat span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.al-cat em{font-style:normal;font-size:11px;font-weight:600;color:var(--erp-muted);
  background:var(--erp-surface-2);border:1px solid var(--erp-border);
  border-radius:999px;padding:1px 7px;flex:none;min-width:26px;text-align:center}
.al-cat.on em{background:#fff;border-color:transparent;color:var(--erp-primary-dark)}

/* ── แถบค้นหา ─────────────────────────────────────────────── */
.al-bar{display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.al-search{position:relative;flex:1;max-width:400px}
.al-search input{width:100%;font:inherit;font-size:13.5px;color:var(--erp-text);
  background:var(--erp-surface);border:1px solid var(--erp-border);border-radius:9px;
  padding:9px 36px 9px 13px;box-shadow:0 1px 2px rgba(29,59,82,.04)}
.al-search input:focus{outline:0;border-color:var(--erp-primary);box-shadow:0 0 0 3px rgba(21,133,192,.16)}
.al-search i{position:absolute;right:13px;top:50%;transform:translateY(-50%);
  color:var(--erp-muted);font-size:13px;pointer-events:none}
.al-count{font-size:12.5px;color:var(--erp-muted)}

/* ── หัวหมวด ──────────────────────────────────────────────── */
.al-group{margin-bottom:26px}
.al-group-title{display:flex;align-items:center;gap:9px;font-size:13px;font-weight:700;
  letter-spacing:.2px;color:var(--erp-primary-dark);margin:0 0 12px;
  text-transform:uppercase}
.al-group-title i{opacity:.55;font-size:14px}
.al-group-title em{font-style:normal;font-size:11px;font-weight:600;color:var(--erp-muted);
  background:var(--erp-surface);border:1px solid var(--erp-border);border-radius:999px;padding:1px 8px}
/* เส้นคั่นยืดเต็มแถวหลังตัวเลข ทำให้หัวหมวดอ่านเป็นแถบเดียว */
.al-group-title::after{content:"";flex:1;height:1px;background:var(--erp-border)}

/* ── การ์ด ────────────────────────────────────────────────── */
.al-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(166px,1fr));gap:11px}
.al-card{display:flex;flex-direction:column;align-items:center;gap:10px;
  background:var(--erp-surface);border:1px solid var(--erp-border);border-radius:12px;
  padding:18px 12px 15px;text-decoration:none;color:var(--erp-text);text-align:center;
  box-shadow:0 1px 2px rgba(29,59,82,.05);
  transition:border-color .13s,box-shadow .13s,transform .13s}
.al-card:hover{border-color:var(--ti,var(--erp-primary));transform:translateY(-2px);
  box-shadow:0 10px 22px -12px color-mix(in srgb,var(--ti,#0f4c75) 55%,transparent)}
.al-card:focus-visible{outline:2px solid var(--ti,var(--erp-primary));outline-offset:2px}
/* สีประจำโมดูลมาจากข้อมูลที่ระบบกำหนดไว้แล้ว 10 โทน — ช่วยให้กวาดตาหาเจอเร็ว
   ทุกโทนคุมให้อยู่ตระกูลเดียวกับฟ้า JET และผ่านคอนทราสต์ AA */
.al-card-ico{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;
  background:var(--ts,var(--erp-primary-soft));color:var(--ti,var(--erp-primary-ink));
  font-size:20px;transition:background .13s,color .13s,transform .13s}
.al-card:hover .al-card-ico{background:var(--ti);color:#fff;transform:scale(1.06)}
.al-card-label{font-size:12.8px;font-weight:600;line-height:1.4;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}

@media (max-width:900px){
  .al{grid-template-columns:1fr}
  .al-side{position:static;display:flex;gap:6px;overflow-x:auto;padding:6px}
  .al-side-head{display:none}
  .al-cat{width:auto;white-space:nowrap;margin-bottom:0}
  .al-cat.on::before{display:none}
}
@media (prefers-reduced-motion:reduce){.al-card,.al-card-ico{transition:none}}

/* ── สีประจำโมดูล ─────────────────────────────────────────────
   ค่า tone มาจากข้อมูลเมนูที่ระบบกำหนดไว้อยู่แล้ว 10 โทน
   คุมทุกโทนให้อยู่ตระกูลเดียวกับฟ้า JET และผ่าน AA ทั้งคู่
   (ไอคอนบนพื้นอ่อน และตัวขาวบนพื้นทึบตอน hover)
   ───────────────────────────────────────────────────────── */
.t-blue   { --ti:#1274a8; --ts:#e9f2f9; }
.t-cyan   { --ti:#0e7490; --ts:#e6f2f6; }
.t-teal   { --ti:#0f766e; --ts:#e6f3f1; }
.t-indigo { --ti:#4054a8; --ts:#edeff9; }
.t-slate  { --ti:#52677d; --ts:#eef1f4; }
.t-amber  { --ti:#9b6400; --ts:#fbf3e3; }
.t-orange { --ti:#b0530a; --ts:#fbf0e6; }
.t-red    { --ti:#c62828; --ts:#fdedec; }
.t-pink   { --ti:#a3376b; --ts:#fbeef3; }
.t-brown  { --ti:#7c5233; --ts:#f6efe8; }
</style>
