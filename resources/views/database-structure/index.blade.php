@extends('layout')

@section('title', 'โครงสร้างฐานข้อมูล - POPSTAR ERP')
@section('page-title', 'โครงสร้างฐานข้อมูลทั้งระบบ')
@section('page-subtitle', 'แผนผังตาราง คอลัมน์ คีย์ และความสัมพันธ์ของ POPSTAR ERP')

@push('head')
<style>
    [x-cloak] { display: none !important; }
    .db-shell { display: grid; gap: 12px; min-width: 0; color: #1d3b52; }
    .db-overview { overflow: hidden; background: #fff; border: 1px solid #dbe7ef; border-radius: 7px; }
    .db-overview-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; padding: 16px 18px; border-top: 4px solid #1599d3; }
    .db-overview-head h2 { margin: 0 0 4px; color: #15364d; font-size: 18px; font-weight: 900; }
    .db-overview-head p { margin: 0; color: #6a8191; font-size: 12px; line-height: 1.5; }
    .db-engine { flex: 0 0 auto; padding: 7px 10px; border: 1px solid #cfe0e9; border-radius: 6px; color: #315f80; background: #f6fafc; font: 700 11px Consolas, monospace; }
    .db-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); border-top: 1px solid #e5edf2; }
    .db-stat { min-width: 0; padding: 12px 16px; border-right: 1px solid #e5edf2; }
    .db-stat:last-child { border-right: 0; }
    .db-stat span { display: block; color: #738998; font-size: 10px; font-weight: 800; }
    .db-stat strong { display: block; margin-top: 2px; color: #183c54; font-size: 20px; }

    .db-workspace { display: grid; grid-template-columns: minmax(280px, 330px) minmax(0, 1fr); gap: 12px; align-items: start; }
    .db-browser, .db-detail { min-width: 0; overflow: hidden; background: #fff; border: 1px solid #dbe7ef; border-radius: 7px; }
    .db-browser { position: sticky; top: 76px; }
    .db-toolbar { display: grid; gap: 8px; padding: 11px; border-bottom: 1px solid #e5edf2; background: #f8fbfd; }
    .db-search { position: relative; }
    .db-search i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #7a909f; }
    .db-search input, .db-module-select { width: 100%; height: 38px; border: 1px solid #cbdbe5; border-radius: 6px; color: #23465e; background: #fff; font-size: 12px; }
    .db-search input { padding: 0 12px 0 36px; }
    .db-module-select { padding: 0 10px; }
    .db-search input:focus, .db-module-select:focus { outline: 2px solid rgba(21, 153, 211, .15); border-color: #1599d3; }
    .db-table-list { max-height: calc(100vh - 270px); overflow-y: auto; padding: 6px; }
    .db-table-link { display: grid; grid-template-columns: 28px minmax(0, 1fr) auto; gap: 8px; align-items: center; min-height: 48px; padding: 7px 8px; border-radius: 6px; color: #385a70; text-decoration: none; }
    .db-table-link:hover { color: #146c96; background: #f0f8fc; }
    .db-table-link.active { color: #fff; background: #167fae; }
    .db-table-icon { width: 28px; height: 28px; display: grid; place-items: center; border-radius: 5px; color: #167fae; background: #e8f5fb; }
    .db-table-link.active .db-table-icon { color: #fff; background: rgba(255, 255, 255, .17); }
    .db-table-name { min-width: 0; }
    .db-table-name strong, .db-table-name small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .db-table-name strong { font: 700 11px Consolas, monospace; }
    .db-table-name small { margin-top: 2px; color: #7a909f; font-size: 10px; }
    .db-table-link.active .db-table-name small { color: #d8f1fb; }
    .db-table-count { color: #7890a1; font-size: 10px; font-weight: 800; }
    .db-table-link.active .db-table-count { color: #fff; }
    .db-empty { padding: 20px 12px; color: #7890a1; text-align: center; font-size: 12px; }

    .db-detail-head { display: flex; justify-content: space-between; gap: 14px; align-items: flex-start; padding: 16px 18px; border-bottom: 1px solid #e5edf2; }
    .db-title-row { display: flex; gap: 9px; align-items: center; flex-wrap: wrap; }
    .db-detail-head h2 { margin: 0; color: #15364d; font: 800 20px Consolas, monospace; overflow-wrap: anywhere; }
    .db-detail-label { margin: 5px 0 0; color: #667f90; font-size: 12px; }
    .db-module-badge { display: inline-flex; gap: 5px; align-items: center; padding: 5px 7px; border-radius: 5px; font-size: 10px; font-weight: 900; }
    .db-module-blue, .db-module-cyan { color: #0f6d99; background: #e4f4fb; }
    .db-module-teal { color: #0f766e; background: #d9f7f1; }
    .db-module-amber, .db-module-orange { color: #8a5a00; background: #fef3c7; }
    .db-module-red, .db-module-pink { color: #a52a35; background: #fee7e9; }
    .db-module-indigo { color: #4f46a5; background: #eeecff; }
    .db-module-slate, .db-module-brown { color: #51616d; background: #edf1f3; }
    .db-print { width: 36px; height: 36px; display: grid; place-items: center; flex: 0 0 auto; border: 1px solid #cbdbe5; border-radius: 6px; color: #315f80; background: #fff; }
    .db-print:hover { color: #1599d3; border-color: #1599d3; }

    .db-table-meta { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); background: #f8fbfd; border-bottom: 1px solid #e5edf2; }
    .db-meta-item { min-width: 0; padding: 11px 14px; border-right: 1px solid #e5edf2; }
    .db-meta-item:last-child { border-right: 0; }
    .db-meta-item span, .db-meta-item strong { display: block; }
    .db-meta-item span { color: #7890a1; font-size: 9px; font-weight: 800; }
    .db-meta-item strong { margin-top: 3px; overflow: hidden; color: #264a61; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }

    .db-section { border-bottom: 1px solid #e5edf2; }
    .db-section:last-child { border-bottom: 0; }
    .db-section-head { display: flex; justify-content: space-between; gap: 10px; align-items: center; padding: 12px 15px; background: #fff; }
    .db-section-head h3 { margin: 0; color: #20455e; font-size: 13px; font-weight: 900; }
    .db-section-head span { color: #7890a1; font-size: 10px; }
    .db-relations { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid #edf2f5; }
    .db-relation-group { min-width: 0; padding: 11px 14px 14px; }
    .db-relation-group + .db-relation-group { border-left: 1px solid #edf2f5; }
    .db-relation-group h4 { margin: 0 0 8px; color: #61798a; font-size: 10px; font-weight: 900; }
    .db-relation-list { display: grid; gap: 6px; }
    .db-relation { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; align-items: center; padding: 8px 9px; border-left: 3px solid #1599d3; background: #f5fafc; color: #3b5c70; text-decoration: none; }
    .db-relation:hover { color: #0d79a8; background: #ecf8fd; }
    .db-relation strong { display: block; font: 700 11px Consolas, monospace; overflow-wrap: anywhere; }
    .db-relation small { display: block; margin-top: 2px; color: #7890a1; font-size: 9px; }
    .db-relation i { color: #1599d3; }
    .db-none { padding: 10px; color: #8a9ca8; background: #f7f9fa; font-size: 10px; text-align: center; }

    .db-table-wrap { overflow-x: auto; border-top: 1px solid #edf2f5; }
    .db-columns { width: 100%; min-width: 880px; border-collapse: collapse; }
    .db-columns th { padding: 9px 11px; color: #607989; background: #f7fafc; border-bottom: 1px solid #dbe7ef; font-size: 9px; font-weight: 900; text-align: left; }
    .db-columns td { padding: 9px 11px; color: #456276; border-bottom: 1px solid #edf2f5; font-size: 10px; vertical-align: top; }
    .db-columns tbody tr:last-child td { border-bottom: 0; }
    .db-column-name { color: #15364d; font: 700 11px Consolas, monospace; }
    .db-type { color: #0f766e; font: 700 10px Consolas, monospace; }
    .db-key-tags { display: flex; gap: 4px; flex-wrap: wrap; }
    .db-key { min-width: 24px; padding: 3px 4px; border-radius: 4px; text-align: center; font-size: 8px; font-weight: 900; }
    .db-key-pk { color: #8a5a00; background: #fef3c7; }
    .db-key-fk { color: #0f6d99; background: #e4f4fb; }
    .db-key-uq { color: #5a4bb2; background: #eeecff; }
    .db-key-ai { color: #047857; background: #d9f7e9; }
    .db-default { display: block; max-width: 220px; color: #596f7d; font: 9px Consolas, monospace; white-space: normal; overflow-wrap: anywhere; }
    .db-index-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px; padding: 0 14px 14px; }
    .db-index { min-width: 0; padding: 9px 10px; border: 1px solid #e1eaf0; border-radius: 6px; }
    .db-index strong { display: block; overflow: hidden; color: #274b63; font: 700 10px Consolas, monospace; text-overflow: ellipsis; white-space: nowrap; }
    .db-index span { display: block; margin-top: 4px; color: #738998; font-size: 9px; overflow-wrap: anywhere; }
    .db-legend { display: flex; gap: 10px; flex-wrap: wrap; padding: 11px 14px; color: #6e8392; background: #f8fbfd; font-size: 9px; }

    @media (max-width: 980px) {
        .db-workspace { grid-template-columns: 280px minmax(0, 1fr); }
        .db-table-meta { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .db-meta-item:nth-child(2) { border-right: 0; }
        .db-meta-item:nth-child(-n+2) { border-bottom: 1px solid #e5edf2; }
        .db-relations { grid-template-columns: 1fr; }
        .db-relation-group + .db-relation-group { border-left: 0; border-top: 1px solid #edf2f5; }
    }
    @media (max-width: 720px) {
        .db-overview-head { flex-direction: column; }
        .db-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .db-stat:nth-child(2) { border-right: 0; }
        .db-stat:nth-child(-n+2) { border-bottom: 1px solid #e5edf2; }
        .db-workspace { grid-template-columns: 1fr; }
        .db-browser { position: static; }
        .db-table-list { max-height: 320px; }
        .db-detail-head { padding: 14px; }
        .db-detail-head h2 { font-size: 17px; }
        .db-index-list { grid-template-columns: 1fr; }
    }
    @media print {
        .app-header, .app-sidebar, .db-browser, .db-print { display: none !important; }
        .app-main { margin: 0 !important; }
        .app-content { padding: 0 !important; }
        .db-workspace { display: block; }
        .db-detail { border: 0; }
        .db-columns { min-width: 0; }
    }
</style>
@endpush

@section('content')
<div class="db-shell" x-data="{ query: '', module: 'all' }">
    <section class="db-overview">
        <div class="db-overview-head">
            <div>
                <h2>แผนผังข้อมูล POPSTAR ERP</h2>
                <p>แสดงเฉพาะโครงสร้างฐานข้อมูล ไม่มีข้อมูลลูกค้า ยอดขาย รหัสผ่าน หรือค่าลับในหน้านี้</p>
            </div>
            <div class="db-engine">{{ strtoupper($driver) }} · {{ $database }}</div>
        </div>
        <div class="db-stats">
            <div class="db-stat"><span>ตารางทั้งหมด</span><strong>{{ number_format($summary['tables']) }}</strong></div>
            <div class="db-stat"><span>คอลัมน์ทั้งหมด</span><strong>{{ number_format($summary['columns']) }}</strong></div>
            <div class="db-stat"><span>ความสัมพันธ์ Foreign Key</span><strong>{{ number_format($summary['relations']) }}</strong></div>
            <div class="db-stat"><span>ดัชนีและคีย์</span><strong>{{ number_format($summary['indexes']) }}</strong></div>
        </div>
    </section>

    <div class="db-workspace">
        <aside class="db-browser">
            <div class="db-toolbar">
                <label class="db-search">
                    <i class="bi bi-search"></i>
                    <input type="search" x-model="query" placeholder="ค้นหาชื่อตาราง..." aria-label="ค้นหาตาราง">
                </label>
                <select class="db-module-select" x-model="module" aria-label="เลือกหมวดระบบ">
                    <option value="all">ทุกหมวดระบบ ({{ count($tables) }})</option>
                    @foreach($modules as $module)
                        <option value="{{ $module['key'] }}">{{ $module['name'] }} ({{ $module['table_count'] }})</option>
                    @endforeach
                </select>
            </div>
            <nav class="db-table-list" aria-label="รายชื่อตารางฐานข้อมูล">
                @foreach($tables as $table)
                    @php($searchText = strtolower($table['name'].' '.$table['label'].' '.$table['module']['name']))
                    <a
                        class="db-table-link {{ $selected && $selected['name'] === $table['name'] ? 'active' : '' }}"
                        href="{{ route('database-structure.index', ['table' => $table['name']]) }}"
                        data-search="{{ $searchText }}"
                        data-module="{{ $table['module_key'] }}"
                        x-show="(module === 'all' || module === $el.dataset.module) && (!query || $el.dataset.search.includes(query.toLowerCase()))"
                    >
                        <span class="db-table-icon"><i class="bi {{ $table['module']['icon'] }}"></i></span>
                        <span class="db-table-name">
                            <strong>{{ $table['name'] }}</strong>
                            <small>{{ $table['label'] }}</small>
                        </span>
                        <span class="db-table-count">{{ count($table['columns']) }}</span>
                    </a>
                @endforeach
                <div
                    class="db-empty"
                    x-show="!Array.from($el.parentElement.querySelectorAll('.db-table-link')).some(link => (module === 'all' || module === link.dataset.module) && (!query || link.dataset.search.includes(query.toLowerCase())))"
                    x-cloak
                >ไม่พบตารางที่ค้นหา</div>
            </nav>
        </aside>

        @if($selected)
            <main class="db-detail">
                <header class="db-detail-head">
                    <div>
                        <div class="db-title-row">
                            <h2>{{ $selected['name'] }}</h2>
                            <span class="db-module-badge db-module-{{ $selected['module']['tone'] }}">
                                <i class="bi {{ $selected['module']['icon'] }}"></i>{{ $selected['module']['name'] }}
                            </span>
                        </div>
                        <p class="db-detail-label">{{ $selected['label'] }}</p>
                    </div>
                    <button class="db-print" type="button" onclick="window.print()" title="พิมพ์โครงสร้างตาราง" aria-label="พิมพ์โครงสร้างตาราง">
                        <i class="bi bi-printer-fill"></i>
                    </button>
                </header>

                <div class="db-table-meta">
                    <div class="db-meta-item"><span>จำนวนคอลัมน์</span><strong>{{ number_format(count($selected['columns'])) }}</strong></div>
                    <div class="db-meta-item"><span>ความสัมพันธ์ออก</span><strong>{{ number_format(count($selected['foreign_keys'])) }}</strong></div>
                    <div class="db-meta-item"><span>ถูกอ้างอิงจาก</span><strong>{{ number_format(count($selected['referenced_by'])) }} ตาราง</strong></div>
                    <div class="db-meta-item"><span>จำนวนรายการโดยประมาณ</span><strong>{{ $rowEstimate === null ? '-' : number_format($rowEstimate) }}</strong></div>
                </div>

                <section class="db-section">
                    <div class="db-section-head">
                        <h3><i class="bi bi-diagram-3-fill me-1"></i>ความสัมพันธ์ของตาราง</h3>
                        <span>คลิกชื่อตารางเพื่อเปิดดูต่อ</span>
                    </div>
                    <div class="db-relations">
                        <div class="db-relation-group">
                            <h4>ตารางนี้อ้างอิงไปยัง</h4>
                            <div class="db-relation-list">
                                @forelse($selected['foreign_keys'] as $foreign)
                                    <a class="db-relation" href="{{ route('database-structure.index', ['table' => $foreign['foreign_table']]) }}">
                                        <span>
                                            <strong>{{ implode(', ', $foreign['columns']) }} → {{ $foreign['foreign_table'] }}.{{ implode(', ', $foreign['foreign_columns']) }}</strong>
                                            <small>เมื่อลบ: {{ strtoupper($foreign['on_delete'] ?? 'NO ACTION') }}</small>
                                        </span>
                                        <i class="bi bi-arrow-right"></i>
                                    </a>
                                @empty
                                    <div class="db-none">ตารางนี้ไม่มี Foreign Key ออก</div>
                                @endforelse
                            </div>
                        </div>
                        <div class="db-relation-group">
                            <h4>ตารางอื่นอ้างอิงมายังตารางนี้</h4>
                            <div class="db-relation-list">
                                @forelse($selected['referenced_by'] as $foreign)
                                    <a class="db-relation" href="{{ route('database-structure.index', ['table' => $foreign['table']]) }}">
                                        <span>
                                            <strong>{{ $foreign['table'] }}.{{ implode(', ', $foreign['columns']) }} → {{ implode(', ', $foreign['foreign_columns']) }}</strong>
                                            <small>เมื่อลบ: {{ strtoupper($foreign['on_delete'] ?? 'NO ACTION') }}</small>
                                        </span>
                                        <i class="bi bi-arrow-left"></i>
                                    </a>
                                @empty
                                    <div class="db-none">ยังไม่มีตารางอื่นอ้างอิงเข้ามา</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </section>

                <section class="db-section">
                    <div class="db-section-head">
                        <h3><i class="bi bi-layout-three-columns me-1"></i>คอลัมน์และกฎข้อมูล</h3>
                        <span>{{ count($selected['columns']) }} คอลัมน์</span>
                    </div>
                    <div class="db-table-wrap">
                        <table class="db-columns">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>ชื่อคอลัมน์</th>
                                    <th>ชนิดข้อมูล</th>
                                    <th>คีย์</th>
                                    <th>ว่างได้</th>
                                    <th>ค่าเริ่มต้น</th>
                                    <th>ความหมาย</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($selected['columns'] as $index => $column)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>
                                            <span class="db-column-name">{{ $column['name'] }}</span>
                                            @if($column['foreign'])
                                                <a href="{{ route('database-structure.index', ['table' => $column['foreign']['foreign_table']]) }}" class="d-block mt-1">
                                                    {{ $column['foreign']['foreign_table'] }}.{{ implode(', ', $column['foreign']['foreign_columns']) }}
                                                </a>
                                            @endif
                                        </td>
                                        <td><span class="db-type">{{ $column['type'] }}</span></td>
                                        <td>
                                            <div class="db-key-tags">
                                                @if($column['is_primary'])<span class="db-key db-key-pk" title="Primary Key">PK</span>@endif
                                                @if($column['foreign'])<span class="db-key db-key-fk" title="Foreign Key">FK</span>@endif
                                                @if($column['is_unique'] && !$column['is_primary'])<span class="db-key db-key-uq" title="Unique">UQ</span>@endif
                                                @if($column['auto_increment'])<span class="db-key db-key-ai" title="Auto Increment">AI</span>@endif
                                            </div>
                                        </td>
                                        <td>{{ $column['nullable'] ? 'ได้' : 'ไม่ได้' }}</td>
                                        <td><code class="db-default">{{ $column['default'] === null ? '-' : $column['default'] }}</code></td>
                                        <td>{{ $column['meaning'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="db-legend">
                        <span><strong>PK</strong> Primary Key</span>
                        <span><strong>FK</strong> Foreign Key</span>
                        <span><strong>UQ</strong> ห้ามค่าซ้ำ</span>
                        <span><strong>AI</strong> เลขรันอัตโนมัติ</span>
                    </div>
                </section>

                <section class="db-section">
                    <div class="db-section-head">
                        <h3><i class="bi bi-lightning-charge-fill me-1"></i>ดัชนีและคีย์ค้นหา</h3>
                        <span>{{ count($selected['indexes']) }} รายการ</span>
                    </div>
                    <div class="db-index-list">
                        @forelse($selected['indexes'] as $index)
                            <div class="db-index">
                                <strong>{{ $index['name'] }}</strong>
                                <span>{{ implode(', ', $index['columns']) }} · {{ $index['primary'] ? 'PRIMARY' : ($index['unique'] ? 'UNIQUE' : 'INDEX') }}</span>
                            </div>
                        @empty
                            <div class="db-none">ไม่พบดัชนีของตารางนี้</div>
                        @endforelse
                    </div>
                </section>
            </main>
        @else
            <main class="db-detail"><div class="db-empty">ยังไม่พบตารางในฐานข้อมูล</div></main>
        @endif
    </div>
</div>
@endsection
