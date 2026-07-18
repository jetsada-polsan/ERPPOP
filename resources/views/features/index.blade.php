@extends('layout')

@section('title', 'JET ERP Flow')
@section('page-title', 'JET ERP Flow')
@section('page-subtitle', 'เมนู ERP แบบใช้งานง่าย อิงลำดับงานจาก BPlus')

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
        border: 1px solid #dfe5ef;
        border-radius: 8px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .05);
        min-height: 0;
        overflow: hidden;
    }

    .flow-head {
        padding: 14px 16px;
        border-bottom: 1px solid #e8edf5;
        background: #f8fafc;
    }

    .flow-head-title {
        font-size: 16px;
        font-weight: 800;
        color: #0f172a;
        margin: 0;
    }

    .flow-head-sub {
        color: #64748b;
        font-size: 12px;
        margin-top: 3px;
    }

    .flow-tabs {
        display: flex;
        gap: 6px;
        overflow-x: auto;
        padding: 10px 10px 0;
        background: #fff;
    }

    .flow-tab {
        border: 1px solid #dce3ee;
        background: #fff;
        border-radius: 999px;
        color: #334155;
        font-weight: 800;
        font-size: 12px;
        padding: 7px 10px;
        white-space: nowrap;
    }

    .flow-tab.active {
        border-color: #0f9aaa;
        background: #e8f6f8;
        color: #0f766e;
    }

    .flow-search {
        border: 1px solid #dce3ee;
        border-radius: 8px;
        padding: 9px 12px;
        width: 100%;
        outline: 0;
    }

    .flow-steps {
        height: calc(100% - 168px);
        overflow: auto;
        padding: 10px;
    }

    .flow-step {
        border: 1px solid #e4e9f2;
        background: #fff;
        border-radius: 8px;
        padding: 11px 12px;
        margin-bottom: 8px;
        cursor: pointer;
        display: grid;
        grid-template-columns: 32px 1fr;
        gap: 10px;
        color: #0f172a;
    }

    .flow-step:hover {
        border-color: #b9cadf;
        background: #fbfdff;
    }

    .flow-step.active {
        border-color: #0f9aaa;
        background: #eefcfc;
    }

    .step-no {
        width: 28px;
        height: 28px;
        border-radius: 6px;
        display: grid;
        place-items: center;
        background: #e2e8f0;
        color: #334155;
        font-weight: 900;
        font-size: 12px;
    }

    .flow-step.active .step-no {
        background: #0f9aaa;
        color: #fff;
    }

    .step-title {
        font-weight: 850;
        line-height: 1.25;
    }

    .step-note {
        color: #64748b;
        font-size: 12px;
        margin-top: 4px;
        line-height: 1.35;
    }

    .detail-wrap {
        height: 100%;
        display: grid;
        grid-template-rows: auto auto 1fr auto;
    }

    .detail-hero {
        padding: 20px 22px;
        border-bottom: 1px solid #e8edf5;
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    }

    .detail-kicker {
        color: #0f9aaa;
        font-size: 12px;
        font-weight: 900;
        letter-spacing: .04em;
        text-transform: uppercase;
    }

    .detail-title {
        font-size: 28px;
        font-weight: 900;
        color: #0f172a;
        margin: 4px 0 6px;
    }

    .detail-summary {
        color: #475569;
        font-size: 15px;
        max-width: 760px;
    }

    .action-strip {
        padding: 14px 22px;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        border-bottom: 1px solid #e8edf5;
    }

    .action-button {
        border: 1px solid #0f9aaa;
        background: #0f9aaa;
        color: #fff;
        border-radius: 8px;
        padding: 9px 14px;
        font-weight: 800;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .action-button:hover {
        color: #fff;
        background: #0b7f8b;
    }

    .secondary-button {
        background: #fff;
        color: #0f9aaa;
    }

    .secondary-button:hover {
        color: #0b7f8b;
        background: #edfafa;
    }

    .detail-body {
        padding: 18px 22px;
        overflow: auto;
    }

    .quick-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .legacy-doc-box {
        border: 1px solid #dbeafe;
        background: #f8fbff;
        border-radius: 8px;
        padding: 12px;
        margin-bottom: 16px;
    }

    .legacy-doc-title {
        color: #0f172a;
        font-size: 13px;
        font-weight: 900;
        margin-bottom: 8px;
    }

    .legacy-code-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .legacy-code-chip {
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #0f172a;
        border-radius: 7px;
        padding: 5px 9px;
        font-size: 12px;
        font-weight: 900;
        text-decoration: none;
        line-height: 1;
    }

    .legacy-code-chip:hover {
        border-color: #0f9aaa;
        color: #0f766e;
        background: #eefcfc;
    }

    .bplus-flow-box {
        border: 1px solid #e4e9f2;
        border-radius: 8px;
        background: #fff;
        padding: 12px;
        margin-bottom: 16px;
    }

    .bplus-flow-title {
        color: #0f172a;
        font-size: 13px;
        font-weight: 900;
        margin-bottom: 10px;
    }

    .bplus-flow-list {
        display: grid;
        gap: 8px;
    }

    .bplus-flow-row {
        display: grid;
        grid-template-columns: 28px minmax(0, 1fr);
        gap: 9px;
        align-items: start;
    }

    .bplus-flow-no {
        width: 26px;
        height: 26px;
        border-radius: 7px;
        display: grid;
        place-items: center;
        background: #e0f2fe;
        color: #0369a1;
        font-size: 11px;
        font-weight: 900;
    }

    .bplus-flow-name {
        color: #0f172a;
        font-size: 13px;
        font-weight: 850;
        line-height: 1.25;
    }

    .bplus-flow-codes {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        margin-top: 5px;
    }

    .quick-link {
        border: 1px solid #e3e9f2;
        border-radius: 8px;
        padding: 12px;
        text-decoration: none;
        color: #0f172a;
        background: #fff;
        min-height: 78px;
    }

    .quick-link:hover {
        border-color: #0f9aaa;
        background: #f5ffff;
        color: #0f172a;
    }

    .quick-title {
        font-weight: 850;
        line-height: 1.25;
    }

    .quick-note {
        color: #64748b;
        font-size: 12px;
        margin-top: 5px;
    }

    .flow-footer {
        padding: 10px 22px;
        border-top: 1px solid #e8edf5;
        color: #64748b;
        display: flex;
        justify-content: space-between;
        gap: 12px;
        font-size: 12px;
        background: #f8fafc;
    }

    @media (max-width: 1199.98px) {
        .flow-shell {
            grid-template-columns: 1fr;
            height: auto;
        }

        .detail-wrap {
            min-height: 520px;
        }
    }

    @media (max-width: 767.98px) {
        .app-content { padding: 12px; }
        .flow-shell {
            grid-template-columns: 1fr;
            min-height: 0;
        }

        .flow-steps {
            height: auto;
            max-height: 360px;
        }

        .quick-grid {
            grid-template-columns: 1fr;
        }
    }
</style>
@endpush

@section('content')
@php
    $flowModules = collect($modules)->values()->map(function ($module, $index) {
        return [
            'index' => $index,
            'key' => $module['key'],
            'title' => $module['title'],
            'icon' => $module['icon'],
            'accent' => $module['accent'],
            'summary' => $module['summary'],
            'legacy_title' => $module['legacy_title'] ?? null,
            'legacy_codes' => collect($module['legacy_codes'] ?? [])->map(fn ($code) => [
                'code' => $code,
                'url' => route('documents.browser', ['legacy_type' => $code, 'year' => now()->subMonth()->year, 'month' => now()->subMonth()->month]),
            ])->values()->all(),
            'bplus_flow' => collect($module['bplus_flow'] ?? [])->values()->map(function ($step, $stepIndex) {
                return [
                    'no' => sprintf('%02d', $stepIndex + 1),
                    'name' => $step['name'],
                    'codes' => collect($step['codes'] ?? [])->map(fn ($code) => [
                        'code' => $code,
                        'url' => route('documents.browser', ['legacy_type' => $code, 'year' => now()->subMonth()->year, 'month' => now()->subMonth()->month]),
                    ])->values()->all(),
                ];
            })->all(),
            'items' => collect($module['items'])->values()->map(function ($item, $itemIndex) {
                return [
                    'code' => sprintf('%02d', $itemIndex + 1),
                    'name' => $item['name'],
                    'note' => $item['note'] ?? 'เปิดหน้าจอทำงานตาม flow เดิม',
                    'url' => ! empty($item['route']) ? route($item['route'][0], $item['route'][1]) : '#',
                    'status' => $item['status'],
                ];
            })->all(),
        ];
    })->all();
    $totalItems = collect($flowModules)->sum(fn ($module) => count($module['items']));
@endphp

<div class="flow-shell" x-data="popstarFlow(@js($flowModules))" x-cloak>
    <section class="flow-panel">
        <div class="flow-head">
            <h2 class="flow-head-title">Workflow</h2>
            <div class="flow-head-sub">เมนูโมดูลอยู่ด้านซ้ายของระบบแล้ว หน้านี้ใช้ค้นหาและเปิดงาน</div>
            <div class="mt-2">
                <input class="flow-search" x-model="query" placeholder="ค้นหางาน เช่น ใบจอง, ภาษี, QR">
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
            <div class="detail-kicker" x-text="activeModule.title"></div>
            <h2 class="detail-title" x-text="activeItem?.name || activeModule.title"></h2>
            <div class="detail-summary" x-text="activeItem?.note || activeModule.summary"></div>
        </div>

        <div class="action-strip">
            <a class="action-button" :href="activeItem?.url || '#'">
                <i class="bi bi-box-arrow-up-right"></i>
                เปิดหน้าจอ
            </a>
            <a class="action-button secondary-button" href="{{ route('reports.index') }}">
                <i class="bi bi-clipboard-data"></i>
                รายงาน
            </a>
            <a class="action-button secondary-button" href="{{ route('dashboard') }}">
                <i class="bi bi-bar-chart-line"></i>
                แดชบอร์ด
            </a>
        </div>

        <div class="detail-body">
            <div class="legacy-doc-box" x-show="activeModule.legacy_codes?.length">
                <div class="legacy-doc-title">
                    รหัสเอกสาร BPlus ในหมวดนี้
                    <span class="text-muted fw-semibold" x-text="activeModule.legacy_title ? '· ' + activeModule.legacy_title : ''"></span>
                </div>
                <div class="legacy-code-grid">
                    <template x-for="docCode in activeModule.legacy_codes" :key="docCode.code">
                        <a class="legacy-code-chip" :href="docCode.url" x-text="docCode.code"></a>
                    </template>
                </div>
            </div>
            <h3 class="h6 fw-bold mb-3">งานในหมวดนี้</h3>
            <div class="bplus-flow-box" x-show="activeModule.bplus_flow?.length">
                <div class="bplus-flow-title">ลำดับงานอิง BPlus</div>
                <div class="bplus-flow-list">
                    <template x-for="step in activeModule.bplus_flow" :key="step.no + step.name">
                        <div class="bplus-flow-row">
                            <span class="bplus-flow-no" x-text="step.no"></span>
                            <span>
                                <span class="bplus-flow-name" x-text="step.name"></span>
                                <span class="bplus-flow-codes">
                                    <template x-for="docCode in step.codes" :key="docCode.code">
                                        <a class="legacy-code-chip" :href="docCode.url" x-text="docCode.code"></a>
                                    </template>
                                </span>
                            </span>
                        </div>
                    </template>
                </div>
            </div>
            <div class="quick-grid">
                <template x-for="item in activeModule.items" :key="item.code + item.name">
                    <a class="quick-link" :href="item.url" @mouseenter="activeItem = item">
                        <div class="quick-title"><span x-text="item.code"></span> · <span x-text="item.name"></span></div>
                        <div class="quick-note" x-text="item.note"></div>
                    </a>
                </template>
            </div>
        </div>

        <div class="flow-footer">
            <span>JET ERP Flow · อิงลำดับงาน BPlus</span>
            <span>{{ count($flowModules) }} โมดูล · {{ $totalItems }} งาน</span>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script>
function popstarFlow(modules) {
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
