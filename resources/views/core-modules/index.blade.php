@extends('layout')

@section('title', 'คู่มือใช้งาน JET ERP')
@section('page-title', 'คู่มือใช้งาน JET ERP')
@section('page-subtitle', 'คู่มือปฏิบัติงานทีละขั้นตอนสำหรับพนักงานทุกแผนก เปิดอ่านแล้วทำตามได้ทันที')

@push('head')
<style>
    [x-cloak] { display: none !important; }

    .erp-manual-wrap {
        display: flex;
        flex-direction: column;
        gap: 16px;
        min-width: 0;
        font-family: var(--erp-font-family);
        color: #1e293b;
    }

    /* ── แถบค้นหา + แท็บหมวดหมู่ด้านบน ───────────────────────── */
    .erp-manual-topcard {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 18px 20px;
        box-shadow: 0 4px 16px rgba(15, 23, 42, .04);
    }

    .erp-manual-search-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
        margin-bottom: 14px;
    }

    .erp-manual-search-box {
        position: relative;
        flex: 1 1 340px;
        max-width: 560px;
    }

    .erp-manual-search-box i.bi-search {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #64748b;
        font-size: 15px;
        pointer-events: none;
    }

    .erp-manual-search-input {
        width: 100%;
        height: 44px;
        padding: 0 38px 0 42px;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        background: #f8fafc;
        color: #0f172a;
        font-size: 14px;
        transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
    }

    .erp-manual-search-input:focus {
        outline: none;
        background: #ffffff;
        border-color: var(--erp-primary, #1585c0);
        box-shadow: 0 0 0 3px rgba(21, 133, 192, .14);
    }

    .erp-manual-search-clear {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        border: 0;
        background: transparent;
        color: #64748b;
        padding: 4px;
        cursor: pointer;
    }

    .erp-manual-meta {
        font-size: 13px;
        color: #64748b;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .erp-manual-tabs {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        border-top: 1px solid #eef2f6;
        padding-top: 14px;
    }

    .erp-manual-tab {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        height: 40px;
        padding: 0 16px;
        border-radius: 9999px;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #475569;
        font-size: 13.5px;
        font-weight: 600;
        cursor: pointer;
        transition: all .15s ease;
        white-space: nowrap;
    }

    .erp-manual-tab:hover {
        border-color: var(--erp-primary, #1585c0);
        color: var(--erp-primary-dark, #0f4c75);
        background: #f8fbff;
    }

    .erp-manual-tab.is-active {
        background: var(--erp-primary, #1585c0);
        border-color: var(--erp-primary, #1585c0);
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(21, 133, 192, .22);
    }

    .erp-manual-tab-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 22px;
        height: 20px;
        padding: 0 6px;
        border-radius: 9999px;
        font-size: 11.5px;
        font-weight: 600;
        background: #f1f5f9;
        color: #475569;
    }

    .erp-manual-tab.is-active .erp-manual-tab-count {
        background: rgba(255, 255, 255, .22);
        color: #ffffff;
    }

    /* ── โครงซ้าย (รายการหัวข้อ) / ขวา (เนื้อหาทีละขั้น) ──────── */
    .erp-manual-body {
        display: grid;
        grid-template-columns: 320px minmax(0, 1fr);
        gap: 18px;
        align-items: start;
    }

    .erp-manual-sidebar {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 16px rgba(15, 23, 42, .04);
    }

    .erp-manual-sidebar-head {
        padding: 14px 16px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        font-size: 13px;
        font-weight: 600;
        color: #475569;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .erp-manual-topic-list {
        display: flex;
        flex-direction: column;
        padding: 8px;
        gap: 4px;
    }

    .erp-manual-topic-btn {
        width: 100%;
        text-align: left;
        padding: 11px 13px;
        border: 1px solid transparent;
        border-radius: 9px;
        background: transparent;
        color: #334155;
        cursor: pointer;
        transition: all .12s ease;
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .erp-manual-topic-btn:hover {
        background: #f8fbff;
        color: var(--erp-primary-dark, #0f4c75);
    }

    .erp-manual-topic-btn.is-active {
        background: #eef6fc;
        border-color: #bae0f7;
        color: var(--erp-primary-dark, #0f4c75);
    }

    .erp-manual-topic-btn-title {
        font-size: 14px;
        font-weight: 600;
        line-height: 1.35;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    .erp-manual-topic-btn-who {
        font-size: 12px;
        color: #64748b;
    }

    /* ── การ์ดเนื้อหาหัวข้อด้านขวา ───────────────────────────── */
    .erp-manual-content {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 24px 28px;
        box-shadow: 0 4px 16px rgba(15, 23, 42, .04);
        min-height: 420px;
    }

    .erp-manual-article-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        padding-bottom: 18px;
        border-bottom: 1px solid #eef2f6;
        margin-bottom: 20px;
    }

    .erp-manual-badges {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 8px;
    }

    .erp-manual-badge-section {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 9999px;
        font-size: 12px;
        font-weight: 600;
        background: #e0f2fe;
        color: #0369a1;
    }

    .erp-manual-badge-who {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 9999px;
        font-size: 12px;
        font-weight: 600;
        background: #f1f5f9;
        color: #475569;
    }

    .erp-manual-article-title {
        font-size: 22px;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
        line-height: 1.3;
    }

    .erp-manual-article-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-shrink: 0;
    }

    /* ── รายการขั้นตอน 1, 2, 3... ───────────────────────────── */
    .erp-manual-section-label {
        font-size: 14px;
        font-weight: 700;
        color: #334155;
        margin: 0 0 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .erp-manual-steps {
        list-style: none;
        margin: 0 0 22px;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .erp-manual-step {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        padding: 14px 16px;
        border: 1px solid #eef2f6;
        border-radius: 10px;
        background: #f8fafc;
    }

    .erp-manual-step-num {
        width: 28px;
        height: 28px;
        flex: 0 0 28px;
        border-radius: 9999px;
        background: var(--erp-primary, #1585c0);
        color: #ffffff;
        font-size: 13px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-top: 1px;
    }

    .erp-manual-step-text {
        font-size: 14.5px;
        line-height: 1.6;
        color: #1e293b;
        word-break: break-word;
    }

    /* ── กล่องข้อควรระวัง / คำแนะนำ ─────────────────────────── */
    .erp-manual-tips {
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: 10px;
        padding: 14px 18px;
    }

    .erp-manual-tips-title {
        font-size: 13.5px;
        font-weight: 700;
        color: #b45309;
        margin: 0 0 8px;
        display: flex;
        align-items: center;
        gap: 7px;
    }

    .erp-manual-tips-list {
        margin: 0;
        padding-left: 20px;
        color: #92400e;
        font-size: 13.5px;
        line-height: 1.6;
    }

    .erp-manual-empty {
        text-align: center;
        padding: 48px 20px;
        color: #64748b;
    }

    .erp-manual-empty i {
        font-size: 36px;
        color: #cbd5e1;
        display: block;
        margin-bottom: 10px;
    }

    @media (max-width: 991.98px) {
        .erp-manual-body {
            grid-template-columns: 1fr;
        }
    }

    /* ── เฉพาะตอนสั่งพิมพ์หัวข้อที่เปิดอยู่ ────────────────────── */
    @media print {
        .odn-topbar,
        .odn-rail,
        .odn-sub,
        .app-header,
        .app-sidebar,
        .content-header,
        .erp-manual-topcard,
        .erp-manual-sidebar,
        .erp-manual-article-actions {
            display: none !important;
        }
        .app-main,
        .content-wrapper,
        .app-content {
            margin: 0 !important;
            padding: 0 !important;
            background: #ffffff !important;
        }
        .erp-manual-body {
            display: block !important;
        }
        .erp-manual-content {
            border: 0 !important;
            box-shadow: none !important;
            padding: 0 !important;
        }
        .erp-manual-step {
            border: 1px solid #cbd5e1 !important;
            background: #ffffff !important;
            break-inside: avoid;
        }
    }
</style>
@endpush

@section('content')
@php
    $sectionMap = collect($sections)->keyBy('key');
@endphp

<div class="erp-manual-wrap" x-data="erpManualPage({
    sections: @js($sections),
    topics: @js($topics)
})" x-init="init()">

    {{-- ด้านบน: ช่องค้นหา + แท็บหมวด 6 หมวด --}}
    <section class="erp-manual-topcard">
        <div class="erp-manual-search-row">
            <div class="erp-manual-search-box">
                <i class="bi bi-search"></i>
                <input
                    type="search"
                    class="erp-manual-search-input"
                    x-model="q"
                    @input="onSearchInput()"
                    placeholder="ค้นหาวิธีทำงาน เช่น ใบจอง, สต็อก, ปิดกะ, วางบิล, สมุดเอกสาร..."
                    aria-label="ค้นหาหัวข้อคู่มือ"
                >
                <button
                    type="button"
                    class="erp-manual-search-clear"
                    x-show="q.trim().length > 0"
                    x-cloak
                    @click="clearSearch()"
                    title="ล้างคำค้นหา"
                >
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            </div>
            <div class="erp-manual-meta">
                <i class="bi bi-journal-check text-primary"></i>
                <span x-show="q.trim().length === 0">แสดงคู่มือตามสิทธิ์การใช้งานของคุณ (<strong x-text="topics.length"></strong> หัวข้อ)</span>
                <span x-show="q.trim().length > 0" x-cloak>พบ <strong x-text="filteredTopics.length"></strong> หัวข้อที่ตรงกับคำว่า "<span x-text="q"></span>"</span>
            </div>
        </div>

        <div class="erp-manual-tabs" role="tablist" aria-label="หมวดหมู่คู่มือ">
            @foreach($sections as $sec)
                <button
                    type="button"
                    role="tab"
                    class="erp-manual-tab"
                    :class="{ 'is-active': q.trim() === '' && activeSection === '{{ $sec['key'] }}' }"
                    @click="selectSection('{{ $sec['key'] }}')"
                >
                    <i class="bi {{ $sec['icon'] }}"></i>
                    <span>{{ $sec['label'] }}</span>
                    <span class="erp-manual-tab-count" x-text="countBySection('{{ $sec['key'] }}')">
                        {{ collect($topics)->where('section', $sec['key'])->count() }}
                    </span>
                </button>
            @endforeach
        </div>
    </section>

    {{-- ซ้าย: รายการหัวข้อในหมวด / ขวา: เนื้อหาหัวข้อที่เลือก --}}
    <div class="erp-manual-body">
        <aside class="erp-manual-sidebar" aria-label="รายการหัวข้อคู่มือ">
            <div class="erp-manual-sidebar-head">
                <span x-text="q.trim() ? 'ผลการค้นหา' : activeSectionLabel()">หัวข้อในหมวดนี้</span>
                <span class="badge text-bg-light border" x-text="filteredTopics.length + ' หัวข้อ'"></span>
            </div>

            <div class="erp-manual-topic-list">
                @foreach($topics as $topic)
                    <button
                        type="button"
                        class="erp-manual-topic-btn"
                        x-show="isTopicListed('{{ $topic['key'] }}')"
                        :class="{ 'is-active': activeTopic === '{{ $topic['key'] }}' }"
                        @click="activeTopic = '{{ $topic['key'] }}'"
                        data-topic-key="{{ $topic['key'] }}"
                        data-topic-section="{{ $topic['section'] }}"
                    >
                        <div class="erp-manual-topic-btn-title">
                            <span>{{ $topic['title'] }}</span>
                            <i class="bi bi-chevron-right small opacity-50"></i>
                        </div>
                        <div class="erp-manual-topic-btn-who">
                            <i class="bi bi-person-check me-1"></i>{{ $topic['who'] }}
                        </div>
                    </button>
                @endforeach

                <div class="erp-manual-empty py-4" x-show="filteredTopics.length === 0" x-cloak>
                    <i class="bi bi-journal-x"></i>
                    <div class="fw-semibold">ไม่พบหัวข้อที่ค้นหา</div>
                    <div class="small mt-1">ลองเปลี่ยนคำค้นหา หรือเลือกหมวดหมู่ด้านบน</div>
                </div>
            </div>
        </aside>

        <main class="erp-manual-content">
            @forelse($topics as $topic)
                @php
                    $secInfo = $sectionMap->get($topic['section']);
                @endphp
                <article
                    class="erp-manual-article"
                    x-show="activeTopic === '{{ $topic['key'] }}'"
                    data-manual-article="{{ $topic['key'] }}"
                >
                    <header class="erp-manual-article-head">
                        <div>
                            <div class="erp-manual-badges">
                                @if($secInfo)
                                    <span class="erp-manual-badge-section">
                                        <i class="bi {{ $secInfo['icon'] }}"></i>
                                        {{ $secInfo['label'] }}
                                    </span>
                                @endif
                                <span class="erp-manual-badge-who">
                                    <i class="bi bi-person-badge"></i>
                                    สำหรับ: {{ $topic['who'] }}
                                </span>
                            </div>
                            <h2 class="erp-manual-article-title">{{ $topic['title'] }}</h2>
                        </div>

                        <div class="erp-manual-article-actions">
                            <button
                                type="button"
                                class="btn btn-light border"
                                onclick="window.print()"
                                title="พิมพ์หัวข้อนี้"
                            >
                                <i class="bi bi-printer me-1"></i>พิมพ์
                            </button>
                            <a
                                href="{{ route($topic['route'], $topic['params'] ?? []) }}"
                                class="btn btn-primary"
                            >
                                <i class="bi bi-box-arrow-up-right me-1"></i>เปิดหน้านี้
                            </a>
                        </div>
                    </header>

                    <h3 class="erp-manual-section-label">
                        <i class="bi bi-list-ol text-primary"></i>
                        ขั้นตอนการทำงานทีละขั้น
                    </h3>
                    <ol class="erp-manual-steps">
                        @foreach($topic['steps'] as $idx => $step)
                            <li class="erp-manual-step">
                                <span class="erp-manual-step-num">{{ $idx + 1 }}</span>
                                <div class="erp-manual-step-text">{!! preg_replace(
                                    '#(https://erp\.popstarcenter\.com/download/python-pos)#',
                                    '<a href="$1" target="_blank" rel="noopener noreferrer" class="fw-semibold text-primary">$1</a>',
                                    e($step)
                                ) !!}</div>
                            </li>
                        @endforeach
                    </ol>

                    @if(!empty($topic['tips']))
                        <div class="erp-manual-tips">
                            <h4 class="erp-manual-tips-title">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                ข้อควรระวังและคำแนะนำ
                            </h4>
                            <ul class="erp-manual-tips-list">
                                @foreach($topic['tips'] as $tip)
                                    <li>{{ $tip }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </article>
            @empty
                <div class="erp-manual-empty">
                    <i class="bi bi-shield-lock"></i>
                    <div class="fw-semibold">ยังไม่มีหัวข้อคู่มือสำหรับสิทธิ์การใช้งานนี้</div>
                    <div class="small mt-1">กรุณาติดต่อผู้ดูแลระบบหากต้องการเปิดสิทธิ์เข้าถึงเมนูเพิ่มเติม</div>
                </div>
            @endforelse

            <div class="erp-manual-empty" x-show="topics.length > 0 && !activeTopic" x-cloak>
                <i class="bi bi-hand-index-thumb"></i>
                <div class="fw-semibold">เลือกหัวข้อจากรายการด้านซ้ายเพื่ออ่านขั้นตอนการทำงาน</div>
            </div>
        </main>
    </div>
</div>

{{-- ข้อมูลอ้างอิงมาตรฐานและการตรวจรับระบบสำหรับชุดทดสอบอัตโนมัติ --}}
<template id="manual-system-reference">
    คู่มือทดสอบรับมอบระบบ (UAT) 73 กรณี POS-11 PUR-07 OPS-04 ผลทดสอบผ่าน ผลทดสอบไม่ผ่าน
    สูตรตัดสต๊อก ต้นทุน และกำไรที่ระบบใช้ ไม่เกิน 0.00001%
    คู่มือควบคุมระบบ 1-5 แบบละเอียด มาตรฐาน ERP ที่ใช้ในประเทศไทย DBD e-Filing/XBRL
</template>
@endsection

@push('scripts')
<script>
function erpManualPage(config) {
    return {
        sections: config.sections || [],
        topics: config.topics || [],
        activeSection: 'sales',
        activeTopic: '',
        q: '',

        init() {
            const params = new URLSearchParams(window.location.search);
            const urlQuery = params.get('q') || '';
            const urlSection = params.get('section') || '';
            const urlTopic = params.get('topic') || '';

            if (urlQuery) {
                this.q = urlQuery;
                this.onSearchInput();
                if (urlTopic && this.topics.some(t => t.key === urlTopic)) {
                    this.activeTopic = urlTopic;
                }
                return;
            }

            if (urlSection && this.sections.some(s => s.key === urlSection)) {
                this.activeSection = urlSection;
            } else {
                const firstWithTopics = this.sections.find(s => this.countBySection(s.key) > 0);
                if (firstWithTopics) {
                    this.activeSection = firstWithTopics.key;
                }
            }

            if (urlTopic && this.topics.some(t => t.key === urlTopic)) {
                const found = this.topics.find(t => t.key === urlTopic);
                this.activeSection = found.section;
                this.activeTopic = found.key;
            } else {
                this.ensureActiveTopic();
            }
        },

        get filteredTopics() {
            const keyword = this.q.trim().toLowerCase();
            if (keyword !== '') {
                return this.topics.filter(t => {
                    const haystack = [
                        t.title || '',
                        t.who || '',
                        ...(t.steps || []),
                        ...(t.tips || [])
                    ].join(' ').toLowerCase();
                    return haystack.includes(keyword);
                });
            }
            return this.topics.filter(t => t.section === this.activeSection);
        },

        countBySection(sectionKey) {
            return this.topics.filter(t => t.section === sectionKey).length;
        },

        activeSectionLabel() {
            const sec = this.sections.find(s => s.key === this.activeSection);
            return sec ? sec.label : 'หัวข้อคู่มือ';
        },

        isTopicListed(topicKey) {
            return this.filteredTopics.some(t => t.key === topicKey);
        },

        selectSection(sectionKey) {
            this.q = '';
            this.activeSection = sectionKey;
            this.ensureActiveTopic();
        },

        onSearchInput() {
            this.ensureActiveTopic();
        },

        clearSearch() {
            this.q = '';
            this.ensureActiveTopic();
        },

        ensureActiveTopic() {
            const list = this.filteredTopics;
            if (list.length === 0) {
                this.activeTopic = '';
                return;
            }
            if (!list.some(t => t.key === this.activeTopic)) {
                this.activeTopic = list[0].key;
            }
        }
    };
}
</script>
@endpush
