@extends('layout')

@section('title', 'ศูนย์ควบคุมโมดูล - PopCentral')
@section('page-title', 'ศูนย์ควบคุมโมดูล')
@section('page-subtitle', 'หน้าตั้งค่า ข้อมูลตั้งต้น และกติกาการแก้ไขเอกสารทุกวงจร')

@section('content')
<div class="module-control-shell" x-data="{tab:'master', taxonomy:'category'}">
    <div class="module-head">
        <div>
            <h2>ควบคุมข้อมูลและเอกสารจากจุดเดียว</h2>
            <p>ข้อมูลตั้งต้นใช้เพิ่ม แก้ไข และปิดใช้งาน ส่วนเอกสารที่ลง Stock, ลูกหนี้, VAT หรือ GL แล้วต้องยกเลิกหรือกลับรายการ ห้ามลบออกจากประวัติ</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('core-modules.index') }}#inventory-formulas" class="btn btn-light border"><i class="bi bi-calculator me-1"></i>สูตรคำนวณ</a>
            <a href="{{ route('settings.index') }}" class="btn btn-light border"><i class="bi bi-gear me-1"></i>ตั้งค่าระบบ</a>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
    @endif

    <div class="control-tabs">
        <button type="button" :class="tab==='master'&&'active'" @click="tab='master'">ข้อมูลตั้งต้น</button>
        <button type="button" :class="tab==='documents'&&'active'" @click="tab='documents'">วงจรเอกสาร</button>
        <button type="button" :class="tab==='taxonomy'&&'active'" @click="tab='taxonomy'">หมวด / แผนก / ยี่ห้อสินค้า</button>
    </div>

    <section x-show="tab==='master'">
        <div class="control-summary">
            <div><span>แฟ้มหลัก</span><strong>{{ count($masterModules) }}</strong></div>
            <div><span>มีหน้าจัดการ</span><strong>{{ count($masterModules) }}</strong></div>
            <div><span>หลักการลบ</span><strong>Archive</strong></div>
        </div>
        <div class="content-card overflow-hidden">
            <div class="table-responsive">
                <table class="table align-middle mb-0 module-table">
                    <thead><tr><th>โมดูล</th><th>คำสั่งที่รองรับ</th><th>จุดควบคุม</th><th></th></tr></thead>
                    <tbody>
                    @foreach($masterModules as $module)
                        <tr>
                            <td><strong>{{ $module[0] }}</strong></td>
                            <td><span class="action-badge">{{ $module[2] }}</span></td>
                            <td>{{ $module[3] }}</td>
                            <td><a href="{{ route($module[1]) }}" class="open-module" title="เปิดโมดูล"><i class="bi bi-box-arrow-up-right"></i></a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section x-show="tab==='documents'" x-cloak>
        <div class="document-rule">
            <i class="bi bi-shield-check"></i>
            <div><strong>กติกาเอกสาร ERP</strong><span>ร่างแก้ไข/ลบได้ · อนุมัติแล้วใช้ยกเลิกหรือเอกสารกลับรายการ · ปิดงวดแล้วต้องเปิดงวดโดยผู้มีสิทธิ์ก่อน</span></div>
        </div>
        <div class="content-card overflow-hidden">
            <div class="table-responsive">
                <table class="table align-middle mb-0 module-table document-table">
                    <thead><tr><th>วงจร</th><th>สถานะการทำงาน</th><th>วิธีแก้รายการผิด</th><th></th></tr></thead>
                    <tbody>
                    @foreach($documentModules as $module)
                        <tr>
                            <td><strong>{{ $module[0] }}</strong></td>
                            <td>{{ $module[2] }}</td>
                            <td>{{ $module[3] }}</td>
                            <td><a href="{{ route($module[1]) }}" class="open-module" title="เปิดโมดูล"><i class="bi bi-box-arrow-up-right"></i></a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section x-show="tab==='taxonomy'" x-cloak>
        <div class="taxonomy-tabs">
            @foreach($taxonomies as $key => $taxonomy)
                <button type="button" :class="taxonomy==='{{ $key }}'&&'active'" @click="taxonomy='{{ $key }}'">{{ $taxonomy['label'] }} ({{ $taxonomy['items']->count() }})</button>
            @endforeach
        </div>

        @foreach($taxonomies as $key => $taxonomy)
            <div x-show="taxonomy==='{{ $key }}'" x-cloak>
                <form method="post" action="{{ route('settings.module-controls.taxonomies.store', $key) }}" class="taxonomy-create">
                    @csrf
                    <label>รหัส<input name="code" maxlength="20" required></label>
                    <label>ชื่อภาษาไทย<input name="name_th" maxlength="150" required></label>
                    <label>ชื่อภาษาอังกฤษ<input name="name_en" maxlength="150"></label>
                    <button class="btn btn-success"><i class="bi bi-plus-lg me-1"></i>เพิ่ม{{ $taxonomy['label'] }}</button>
                </form>

                <div class="content-card overflow-hidden">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 taxonomy-table">
                            <thead><tr><th>รหัส</th><th>ชื่อไทย</th><th>ชื่ออังกฤษ</th><th class="text-end">สินค้าที่ใช้</th><th></th></tr></thead>
                            <tbody>
                            @forelse($taxonomy['items'] as $item)
                                <tr>
                                    <td><input form="taxonomy-{{ $key }}-{{ $item->id }}" name="code" value="{{ $item->code }}" maxlength="20" required></td>
                                    <td><input form="taxonomy-{{ $key }}-{{ $item->id }}" name="name_th" value="{{ $item->name_th }}" maxlength="150" required></td>
                                    <td><input form="taxonomy-{{ $key }}-{{ $item->id }}" name="name_en" value="{{ $item->name_en }}" maxlength="150"></td>
                                    <td class="text-end"><strong>{{ number_format($item->products_count) }}</strong></td>
                                    <td class="taxonomy-actions">
                                        <form id="taxonomy-{{ $key }}-{{ $item->id }}" method="post" action="{{ route('settings.module-controls.taxonomies.update', [$key, $item->id]) }}">
                                            @csrf @method('PUT')
                                            <button class="icon-action save" title="บันทึก"><i class="bi bi-check-lg"></i></button>
                                        </form>
                                        <form method="post" action="{{ route('settings.module-controls.taxonomies.destroy', [$key, $item->id]) }}" data-confirm="ลบ {{ $taxonomy['label'] }} {{ $item->code }} หรือไม่">
                                            @csrf @method('DELETE')
                                            <button class="icon-action delete" title="{{ $item->products_count ? 'มีสินค้าใช้งานอยู่ จึงลบไม่ได้' : 'ลบ' }}" @disabled($item->products_count)><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีข้อมูล</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endforeach
    </section>
</div>
@endsection

@push('head')
<style>
[x-cloak]{display:none!important}
.module-control-shell{display:grid;gap:12px;max-width:1500px;min-width:0;margin:auto}.module-control-shell>section,.module-control-shell .content-card,.module-control-shell .table-responsive{min-width:0;max-width:100%}.module-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;min-width:0;padding:16px 18px;border:1px solid var(--erp-border);background:#fff;overflow:hidden}.module-head>div:first-child{min-width:0}.module-head h2{margin:0;color:#173f5b;font-size:20px;font-weight:900;overflow-wrap:anywhere}.module-head p{max-width:900px;margin:4px 0 0;color:#657d8e;font-size:12px;overflow-wrap:anywhere}.control-tabs,.taxonomy-tabs{display:flex;gap:4px;max-width:100%;padding:4px;border:1px solid var(--erp-border);background:#eef4f8;overflow-x:auto}.control-tabs button,.taxonomy-tabs button{border:0;background:transparent;padding:8px 13px;color:#557084;font-size:12px;font-weight:800;white-space:nowrap}.control-tabs button.active,.taxonomy-tabs button.active{background:#fff;color:#087fb9;box-shadow:0 2px 7px rgba(24,67,95,.1)}.control-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:10px}.control-summary div{padding:10px 12px;border-left:3px solid var(--erp-primary);background:#fff}.control-summary span,.control-summary strong{display:block}.control-summary span{color:var(--erp-muted);font-size:10px}.control-summary strong{color:#1d465f;font-size:18px}.module-table{min-width:900px}.module-table th,.taxonomy-table th{background:#eaf3f8;color:#48687c;font-size:11px}.module-table td,.taxonomy-table td{color:#36566b;font-size:11.5px}.action-badge{display:inline-block;padding:4px 7px;background:#e7f7f1;color:#08775a;font-weight:800}.open-module{display:grid;place-items:center;width:29px;height:29px;border:1px solid #bcd5e4;color:#1288c2}.document-rule{display:flex;align-items:center;gap:10px;margin-bottom:10px;padding:11px 13px;border-left:4px solid #1b9c72;background:#edf9f4;color:#286754}.document-rule i{font-size:22px}.document-rule strong,.document-rule span{display:block}.document-rule span{font-size:11px}.taxonomy-tabs{margin-bottom:10px}.taxonomy-create{display:grid;grid-template-columns:160px 1fr 1fr auto;align-items:end;gap:8px;margin-bottom:10px;padding:11px;border:1px solid var(--erp-border);background:#fff}.taxonomy-create label{display:grid;gap:3px;color:#587084;font-size:10px;font-weight:800}.taxonomy-create input,.taxonomy-table input{width:100%;height:32px;border:1px solid #cfdae2;padding:4px 7px;color:var(--erp-primary-dark);font-size:12px}.taxonomy-table{min-width:760px}.taxonomy-actions{display:flex;justify-content:flex-end;gap:4px}.taxonomy-actions form{margin:0}.icon-action{display:grid;place-items:center;width:29px;height:29px;border:1px solid #cbd8e0;background:#fff}.icon-action.save{color:#07805e}.icon-action.delete{color:#c24141}.icon-action:disabled{opacity:.35}@media(max-width:760px){.control-summary{grid-template-columns:1fr}.taxonomy-create{grid-template-columns:1fr}.module-head{display:grid}.module-head>.d-flex{flex-wrap:wrap}}
</style>
@endpush
