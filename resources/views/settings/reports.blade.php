@extends('layout')

@section('title', 'เปิด/ปิดรายงาน - PopCentral')
@section('page-title', 'ทะเบียนรายงาน')
@section('page-subtitle', 'เปิดหรือปิดรายงานที่แสดงในเมนู โดยไม่ลบรายงานหรือประวัติ')

@section('content')
<div class="report-governance">
    <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-3">
        <div>
            <h2 class="h5 mb-1">ควบคุมรายงานจากจุดเดียว</h2>
            <p class="text-muted mb-0" style="font-size:13px">
                ปิดรายงาน = ซ่อนจากเมนูเท่านั้น ผู้ที่เคยดึงไปแล้วยังมีไฟล์ของตัวเอง และเปิดกลับได้ทุกเมื่อ
                ส่วนรายงานที่สถานะยังไม่ใช่ <code>available</code> จะเปิดไม่ได้จนกว่าจะมี mapping และ UAT เทียบยอดผ่าน
            </p>
        </div>
        <div class="d-flex gap-3 text-center">
            <div><div class="fw-bold fs-5">{{ $counts['total'] }}</div><div class="text-muted" style="font-size:12px">ทั้งหมด</div></div>
            <div><div class="fw-bold fs-5 text-success">{{ $counts['enabled'] }}</div><div class="text-muted" style="font-size:12px">เปิดอยู่</div></div>
            <div><div class="fw-bold fs-5 text-secondary">{{ $counts['planned'] }}</div><div class="text-muted" style="font-size:12px">ยังไม่มีหน้าจอ</div></div>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
    @endif
    @if(session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    @foreach($groups as $category => $definitions)
        <section class="card mb-3">
            <div class="card-header bg-light fw-semibold">
                {{ $definitions->first()->category_title }}
                <span class="text-muted fw-normal" style="font-size:12px">({{ $category }})</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>รายงาน</th>
                            <th style="width:130px">สิทธิ์ที่ต้องมี</th>
                            <th style="width:120px">เจ้าของ</th>
                            <th style="width:90px">ความถี่</th>
                            <th style="width:80px">ระดับ</th>
                            <th style="width:110px">สถานะ</th>
                            <th style="width:120px" class="text-end">แสดงในเมนู</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($definitions as $definition)
                        <tr>
                            <td>
                                {{ $definition->name }}
                                <div class="text-muted" style="font-size:11px">
                                    <code>{{ $definition->code }}</code>
                                    @if($definition->legacy_code)
                                        · เดิม: {{ $definition->legacy_code }}
                                    @endif
                                </div>
                            </td>
                            <td><code style="font-size:11px">{{ $definition->view_permission }}</code></td>
                            <td style="font-size:12px">{{ $definition->owner_role ?? '—' }}</td>
                            <td style="font-size:12px">{{ $definition->frequency }}</td>
                            <td style="font-size:12px">{{ $definition->priority ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $definition->status === 'available' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">
                                    {{ $definition->status }}
                                </span>
                            </td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('settings.reports.update') }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="report_id" value="{{ $definition->id }}">
                                    <input type="hidden" name="enabled" value="{{ $definition->enabled ? 0 : 1 }}">
                                    <button type="submit"
                                            class="btn btn-sm {{ $definition->enabled ? 'btn-success' : 'btn-outline-secondary' }}"
                                            @disabled(! $definition->enabled && $definition->status !== 'available')>
                                        {{ $definition->enabled ? 'เปิดอยู่' : 'ปิดอยู่' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach
</div>
@endsection
