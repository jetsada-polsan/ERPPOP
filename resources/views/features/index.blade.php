@extends('layout')

@section('title', 'รวมเมนูและขั้นตอนการทำงาน - JET ERP')
@section('page-title', 'รวมเมนูและขั้นตอนการทำงาน')
@section('page-subtitle', 'คู่มือและแผนผังขั้นตอนการทำงาน ERP ตามระบบปัจจุบัน')

@push('head')
<style>
    .app-content {
        padding: 18px;
    }

    .flow-shell {
        height: calc(100vh - 108px);
        min-height: 680px;
        display: grid;
        grid-template-columns: minmax(360px, 460px) 1fr;
        gap: 14px;
    }

    .flow-panel {
        background: #fff;
        border: 1px solid var(--erp-border, #dbe7ef);
        border-radius: 8px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .05);
        min-height: 0;
        overflow: hidden;
    }

    .flow-head {
        padding: 14px 16px;
        border-bottom: 1px solid var(--erp-border, #dbe7ef);
        background: var(--erp-surface-2, #f8fafc);
    }

    .flow-head-title {
        font-size: 16px;
        font-weight: 800;
        color: var(--erp-text, #0f172a);
        margin: 0;
    }

    .flow-head-sub {
        color: var(--erp-muted, #64748b);
        font-size: 12px;
        margin-top: 3px;
    }

    .flow-tabs {
        display: flex;
        gap: 6px;
        overflow-x: auto;
        padding: 10px 10px 0;
        background: #fff;
        border-bottom: 1px solid var(--erp-border, #dbe7ef);
    }

    .flow-tab {
        border: 1px solid var(--erp-border, #dbe7ef);
        background: #fff;
        border-radius: 999px;
        color: var(--erp-text, #0f172a);
        font-weight: 700;
        font-size: 12px;
        padding: 7px 12px;
        white-space: nowrap;
        cursor: pointer;
        transition: all 120ms ease;
    }

    .flow-tab:hover {
        background: var(--erp-surface-2, #f8fafc);
        border-color: #cbd5e1;
    }

    .flow-tab.active {
        border-color: var(--erp-primary, #1585c0);
        background: rgba(21, 133, 192, 0.08);
        color: var(--erp-primary, #1585c0);
        font-weight: 800;
    }

    .flow-search {
        border: 1px solid var(--erp-border, #dbe7ef);
        border-radius: 8px;
        padding: 9px 12px;
        width: 100%;
        outline: 0;
        font-size: 13px;
    }
    .flow-search:focus {
        border-color: var(--erp-primary, #1585c0);
        box-shadow: 0 0 0 3px rgba(21, 133, 192, 0.15);
    }

    .flow-steps {
        height: calc(100% - 170px);
        overflow-y: auto;
        padding: 10px;
    }

    .flow-step {
        border: 1px solid var(--erp-border, #dbe7ef);
        background: #fff;
        border-radius: 8px;
        padding: 11px 12px;
        margin-bottom: 8px;
        cursor: pointer;
        display: grid;
        grid-template-columns: 32px 1fr;
        gap: 10px;
        color: var(--erp-text, #0f172a);
        transition: all 120ms ease;
    }

    .flow-step:hover {
        border-color: #cbd5e1;
        background: var(--erp-surface-2, #f8fafc);
    }

    .flow-step.active {
        border-color: var(--erp-primary, #1585c0);
        background: rgba(21, 133, 192, 0.06);
    }

    .step-no {
        width: 28px;
        height: 28px;
        border-radius: 6px;
        display: grid;
        place-items: center;
        background: #e2e8f0;
        color: #334155;
        font-weight: 800;
        font-size: 12px;
    }

    .flow-step.active .step-no {
        background: var(--erp-primary, #1585c0);
        color: #fff;
    }

    .step-title {
        font-weight: 700;
        line-height: 1.25;
        font-size: 13.5px;
    }

    .step-note {
        color: var(--erp-muted, #64748b);
        font-size: 12px;
        margin-top: 4px;
        line-height: 1.35;
        display: block;
    }

    .detail-wrap {
        height: 100%;
        display: grid;
        grid-template-rows: auto auto 1fr auto;
    }

    .detail-hero {
        padding: 20px 24px;
        border-bottom: 1px solid var(--erp-border, #dbe7ef);
        background: linear-gradient(180deg, #ffffff 0%, var(--erp-surface-2, #f8fafc) 100%);
    }

    .detail-kicker {
        color: var(--erp-primary, #1585c0);
        font-size: 12px;
        font-weight: 800;
        letter-spacing: .04em;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .detail-title {
        font-size: 24px;
        font-weight: 800;
        color: var(--erp-text, #0f172a);
        margin: 6px 0 8px;
    }

    .detail-summary {
        color: #475569;
        font-size: 14.5px;
        line-height: 1.5;
        max-width: 800px;
    }

    .detail-role {
        margin-top: 10px;
        font-size: 13px;
        color: #0f766e;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(15, 118, 110, 0.08);
        padding: 4px 10px;
        border-radius: 6px;
        font-weight: 600;
    }

    .action-strip {
        padding: 12px 24px;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        border-bottom: 1px solid var(--erp-border, #dbe7ef);
        background: #fff;
    }

    .action-button {
        border: 1px solid var(--erp-primary, #1585c0);
        background: var(--erp-primary, #1585c0);
        color: #fff;
        border-radius: 8px;
        padding: 8px 16px;
        font-weight: 700;
        font-size: 13.5px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 120ms ease;
    }

    .action-button:hover {
        color: #fff;
        background: #0f6fa1;
    }

    .secondary-button {
        background: #fff;
        border-color: var(--erp-border, #dbe7ef);
        color: #334155;
    }

    .secondary-button:hover {
        color: var(--erp-primary, #1585c0);
        border-color: var(--erp-primary, #1585c0);
        background: rgba(21, 133, 192, 0.05);
    }

    .detail-body {
        padding: 20px 24px;
        overflow-y: auto;
    }

    .workflow-box {
        border: 1px solid var(--erp-border, #dbe7ef);
        border-radius: 8px;
        background: #ffffff;
        padding: 16px;
        margin-bottom: 20px;
    }

    .workflow-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 14px;
    }

    .workflow-title {
        color: var(--erp-text, #0f172a);
        font-size: 14.5px;
        font-weight: 800;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .workflow-list {
        display: grid;
        gap: 10px;
    }

    .workflow-step-row {
        display: grid;
        grid-template-columns: 28px 1fr;
        gap: 12px;
        align-items: start;
        padding: 8px 10px;
        background: var(--erp-surface-2, #f8fafc);
        border-radius: 6px;
        border: 1px solid rgba(219, 231, 239, 0.6);
    }

    .workflow-step-num {
        width: 26px;
        height: 26px;
        border-radius: 6px;
        display: grid;
        place-items: center;
        background: var(--erp-primary, #1585c0);
        color: #fff;
        font-size: 12px;
        font-weight: 800;
    }

    .workflow-step-text {
        font-size: 13.5px;
        color: #334155;
        line-height: 1.45;
        padding-top: 2px;
    }

    .quick-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .quick-link {
        border: 1px solid var(--erp-border, #dbe7ef);
        background: #fff;
        border-radius: 8px;
        padding: 12px 14px;
        text-decoration: none;
        color: var(--erp-text, #0f172a);
        display: block;
        transition: all 120ms ease;
    }

    .quick-link:hover {
        border-color: var(--erp-primary, #1585c0);
        background: rgba(21, 133, 192, 0.04);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
    }

    .quick-title {
        font-weight: 700;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 6px;
        color: var(--erp-primary-dark, #0f4c75);
    }

    .quick-note {
        font-size: 12px;
        color: var(--erp-muted, #64748b);
        margin-top: 4px;
        line-height: 1.4;
    }

    .flow-footer {
        padding: 12px 24px;
        border-top: 1px solid var(--erp-border, #dbe7ef);
        background: var(--erp-surface-2, #f8fafc);
        display: flex;
        justify-content: space-between;
        font-size: 12px;
        color: var(--erp-muted, #64748b);
    }

    @media (max-width: 1024px) {
        .flow-shell {
            grid-template-columns: 1fr;
            height: auto;
        }
        .flow-steps {
            max-height: 280px;
        }
        .quick-grid {
            grid-template-columns: 1fr;
        }
    }
</style>
@endpush

@section('content')
@php
    $user = auth()->user();
    $flowModules = collect($modules)->values()->map(function ($module, $index) use ($user) {
        return [
            'index' => $index,
            'key' => $module['key'],
            'title' => $module['title'],
            'icon' => $module['icon'],
            'accent' => $module['accent'],
            'summary' => $module['summary'],
            'role' => $module['role'] ?? null,
            'workflow' => $module['workflow'] ?? [],
            'items' => collect($module['items'])->values()->map(function ($item, $itemIndex) use ($user) {
                $routeName = ! empty($item['route']) ? $item['route'][0] : null;
                $permission = $routeName ? \App\Support\RoutePermissions::resolve($routeName) : null;
                $accessible = $permission === null || ($user?->hasPermission($permission) ?? false);
                return [
                    'code' => sprintf('%02d', $itemIndex + 1),
                    'name' => $item['name'],
                    'note' => $item['note'] ?? 'เปิดหน้าจอทำงานในระบบ',
                    'url' => ! empty($item['route']) ? route($item['route'][0], $item['route'][1]) : '#',
                    'status' => $item['status'],
                    'accessible' => $accessible,
                ];
            })->all(),
        ];
    })->all();
    $totalItems = collect($flowModules)->sum(fn ($module) => count($module['items']));
@endphp

<div class="flow-shell" x-data="erpFlow(@js($flowModules))" x-cloak>
    <section class="flow-panel">
        <div class="flow-head">
            <h2 class="flow-head-title"><i class="bi bi-diagram-3-fill me-2" style="color:var(--erp-primary)"></i>ขั้นตอนการทำงาน (Workflow)</h2>
            <div class="flow-head-sub">เลือกหมวดงานและคลิกรายการเพื่อดูขั้นตอนและเปิดหน้าจอ</div>
            <div class="mt-2">
                <input class="flow-search" x-model="query" placeholder="ค้นหางาน เช่น ใบจอง, POS, โอนย้าย, ภาษี">
            </div>
        </div>
        <div class="flow-tabs">
            <template x-for="module in modules" :key="module.key">
                <button type="button" class="flow-tab" :class="{ active: activeKey === module.key }" @click="selectModule(module.key)" x-text="module.title"></button>
            </template>
        </div>
        <div class="flow-steps">
            <template x-for="item in filteredItems" :key="item.code + item.name">
                <div class="flow-step" :class="{ active: activeItem?.name === item.name }" @click="selectItem(item)">
                    <span class="step-no" x-text="item.code"></span>
                    <span>
                        <span class="step-title" x-text="item.name"></span>
                        <span class="step-note" x-text="item.note"></span>
                    </span>
                </div>
            </template>
            <div class="text-center text-muted py-5" x-show="filteredItems.length === 0">ไม่พบรายการที่ค้นหา</div>
        </div>
    </section>

    <section class="flow-panel detail-wrap">
        <div class="detail-hero">
            <div class="detail-kicker">
                <i class="bi" :class="activeModule.icon || 'bi-folder'"></i>
                <span x-text="activeModule.title"></span>
            </div>
            <h2 class="detail-title" x-text="activeItem?.name || activeModule.title"></h2>
            <div class="detail-summary" x-text="activeItem?.note || activeModule.summary"></div>
            <div class="detail-role" x-show="activeModule.role">
                <i class="bi bi-person-badge"></i>
                <span x-text="activeModule.role"></span>
            </div>
        </div>

        <div class="action-strip">
            <a class="action-button" :href="activeItem?.url || '#'">
                <i class="bi bi-box-arrow-up-right"></i>
                เปิดหน้าจอทำงาน
            </a>
            <a class="action-button secondary-button" href="{{ route('core-modules.index') }}">
                <i class="bi bi-book"></i>
                คู่มือการใช้งาน
            </a>
            <a class="action-button secondary-button" href="{{ route('reports.index') }}">
                <i class="bi bi-clipboard-data"></i>
                รายงาน
            </a>
            <a class="action-button secondary-button" href="{{ route('dashboard') }}">
                <i class="bi bi-bar-chart-line"></i>
                ภาพรวมกิจการ
            </a>
        </div>

        <div class="detail-body">
            {{-- ขั้นตอนการทำงานจริง 3–6 ข้อ --}}
            <div class="workflow-box" x-show="activeModule.workflow?.length">
                <div class="workflow-head">
                    <div class="workflow-title">
                        <i class="bi bi-check2-circle text-primary"></i>
                        ขั้นตอนการทำงานตามระบบปัจจุบัน
                    </div>
                    <span class="badge text-bg-light border" x-text="activeModule.workflow?.length + ' ขั้นตอน'"></span>
                </div>
                <div class="workflow-list">
                    <template x-for="(stepText, stepIdx) in activeModule.workflow" :key="stepIdx">
                        <div class="workflow-step-row">
                            <span class="workflow-step-num" x-text="stepIdx + 1"></span>
                            <span class="workflow-step-text" x-text="stepText"></span>
                        </div>
                    </template>
                </div>
            </div>

            <h3 class="h6 fw-bold mb-3"><i class="bi bi-grid-fill me-1 text-primary"></i>เมนูการทำงานที่เกี่ยวข้อง</h3>
            <div class="quick-grid">
                <template x-for="item in activeModule.items" :key="item.code + item.name">
                    <a class="quick-link" :href="item.url" @mouseenter="activeItem = item">
                        <div class="quick-title">
                            <span x-text="item.code"></span> · <span x-text="item.name"></span>
                        </div>
                        <div class="quick-note" x-text="item.note"></div>
                    </a>
                </template>
            </div>
        </div>

        <div class="flow-footer">
            <span>JET ERP · แผนผังและขั้นตอนการทำงานตามระบบปัจจุบัน</span>
            <span>{{ count($flowModules) }} หมวดงาน · {{ $totalItems }} รายการ</span>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script>
function erpFlow(modules) {
    return {
        modules,
        query: '',
        activeKey: modules[0]?.key || '',
        activeItem: modules[0]?.items?.[0] || null,
        get activeModule() {
            return this.modules.find(module => module.key === this.activeKey) || this.modules[0] || { title: '', items: [] };
        },
        get filteredItems() {
            const needle = this.query.trim().toLowerCase();
            if (!needle) return this.activeModule.items;
            return this.activeModule.items.filter(item =>
                `${item.name} ${item.note}`.toLowerCase().includes(needle)
            );
        },
        selectModule(key) {
            this.activeKey = key;
            this.query = '';
            this.activeItem = this.activeModule.items[0] || null;
        },
        selectItem(item) {
            this.activeItem = item;
        },
    };
}
</script>
@endpush
