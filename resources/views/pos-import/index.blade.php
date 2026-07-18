@extends('layout')

@section('title', 'POS Import - POPSTAR ERP')
@section('page-title', 'POS Import')
@section('page-subtitle', 'ดึง ตรวจสอบ และโพสต์ยอดขายจากเครื่อง POS')

@section('content')
    <div class="row g-4">
        <div class="col-12">
            <div class="content-card p-4">
                <div class="d-flex flex-column flex-xl-row justify-content-between gap-3">
                    <div>
                        <h2 class="h5 fw-bold mb-1">นำเข้าไฟล์ POS จริง</h2>
                        <div class="text-muted small">
                            รับไฟล์ ZIP จากเครื่อง POS เช่น 000120260630.Zip เพื่อเก็บหลักฐานและสร้าง batch ก่อนตรวจยอด
                        </div>
                    </div>
                    <form method="post" action="{{ route('pos-import.upload') }}" enctype="multipart/form-data" class="d-flex flex-wrap align-items-end gap-3">
                        @csrf
                        <div>
                            <label class="form-label small text-muted mb-1">ไฟล์ ZIP</label>
                            <input type="file" name="files[]" multiple required accept=".zip" class="form-control form-control-sm" style="min-width:320px">
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1">วันที่ขายสำรอง</label>
                            <input type="date" name="sale_date" class="form-control form-control-sm">
                        </div>
                        <button class="btn btn-success btn-sm px-4">
                            <i class="bi bi-file-earmark-zip me-1"></i> Upload ZIP
                        </button>
                    </form>
                </div>
                <div class="alert alert-light border small text-muted mt-3 mb-0">
                    ชื่อไฟล์แบบ 000120260630.Zip จะถูกอ่านเป็น POS 0001 วันที่ 2026-06-30 อัตโนมัติ หลัง upload แล้วให้กดดึงข้อมูลจาก MSSQL วันที่เดียวกันเพื่อแปลงเป็นใบเสร็จ
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="content-card p-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                    <div>
                        <h2 class="h5 fw-bold mb-1">ดึงข้อมูลจาก POS</h2>
                        <div class="text-muted small">เลือกเครื่อง POS และวันที่ขายเพื่อดึงข้อมูลเข้าพื้นที่ staging</div>
                    </div>
                    <form method="post" action="{{ route('pos-import.sync') }}" class="d-flex flex-wrap align-items-end gap-3">
                        @csrf
                        <div>
                            <label class="form-label small text-muted mb-1">POS / สาขา</label>
                            <select name="pos_code" required class="form-select form-select-sm min-w-[240px]">
                                @foreach($terminals as $t)
                                    <option value="{{ $t->code }}">{{ $t->code }} - {{ $t->branch?->name_th }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1">วันที่ขาย</label>
                            <input type="date" name="sale_date" required class="form-control form-control-sm">
                        </div>
                        <button class="btn btn-primary btn-sm px-4">
                            <i class="bi bi-cloud-arrow-down me-1"></i> ดึงข้อมูล
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="content-card p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h2 class="h5 fw-bold mb-0">Batch ทั้งหมด</h2>
                    <span class="badge text-bg-light border">{{ number_format($batches->total()) }} batch</span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>POS</th>
                                <th>สาขา</th>
                                <th>วันที่</th>
                                <th>สถานะ</th>
                                <th class="text-end">ใบเสร็จ</th>
                                <th class="text-end">Valid</th>
                                <th class="text-end">Error</th>
                                <th class="text-end">Posted</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($batches as $b)
                                @php
                                    $colors = [
                                        'uploaded' => 'text-bg-secondary',
                                        'parsed' => 'text-bg-secondary',
                                        'validated' => 'text-bg-primary',
                                        'has_error' => 'text-bg-danger',
                                        'confirmed' => 'text-bg-warning',
                                        'posted' => 'text-bg-success',
                                        'cancelled' => 'text-bg-light',
                                    ];
                                @endphp
                                <tr>
                                    <td class="fw-semibold">{{ $b->pos_code }}</td>
                                    <td>{{ $b->terminal?->branch?->name_th }}</td>
                                    <td>{{ $b->sale_date }}</td>
                                    <td><span class="badge {{ $colors[$b->status] ?? 'text-bg-light' }}">{{ $b->status }}</span></td>
                                    <td class="text-end">{{ number_format($b->receipts_count) }}</td>
                                    <td class="text-end text-primary">{{ number_format($b->valid_count) }}</td>
                                    <td class="text-end {{ $b->error_count > 0 ? 'text-danger fw-bold' : '' }}">{{ number_format($b->error_count) }}</td>
                                    <td class="text-end text-success">{{ number_format($b->posted_count) }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('pos-import.batches.page-show', $b) }}" class="btn btn-sm btn-light border">
                                            ดู
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="py-5 text-center text-muted">ยังไม่มีข้อมูล ลองดึงข้อมูลจากฟอร์มด้านบน</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">{{ $batches->links() }}</div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    if ('caches' in window) {
        caches.keys().then((keys) => {
            keys.filter((key) => key.startsWith('popstar-pos-')).forEach((key) => caches.delete(key));
        });
    }

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistration('/').then((registration) => {
            if (registration) registration.update();
        });
    }

    (() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('import_popup') !== '1') return;

        const created = Number(params.get('created') || 0);
        const skipped = Number(params.get('skipped') || 0);
        let text = `รับไฟล์ POS แล้ว ${created} ไฟล์`;
        if (skipped > 0) text += ` / ข้ามไฟล์เดิม ${skipped} ไฟล์`;

        Swal.fire({
            icon: 'success',
            title: created > 0 ? 'Import สำเร็จ' : 'ไฟล์นี้เคยนำเข้าแล้ว',
            text,
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#10b981',
            timer: 4200,
            timerProgressBar: true,
        });

        window.history.replaceState({}, document.title, window.location.pathname);
    })();
</script>
@endpush
