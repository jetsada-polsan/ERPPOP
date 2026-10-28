{{-- ปุ่มยกเลิกเอกสาร: ต้องใส่เหตุผล ถ้ายกเลิกไม่ได้บอกเหตุผลแทนปุ่ม (ใช้ DocumentVoidService::blockers เดียวกับฝั่งบันทึก) --}}
@php
    $voidService = app(\App\Services\Accounting\DocumentVoidService::class);
    $voidBlockers = in_array($doc->documentType?->code, \App\Services\Accounting\DocumentVoidService::VOIDABLE, true) ? $voidService->blockers($doc) : null;
@endphp
@if(in_array($doc->status, ['cancelled', 'void'], true))
    <span class="badge text-bg-danger fs-6 px-3 py-2"><i class="bi bi-x-octagon me-1"></i>ยกเลิกแล้ว</span>
@elseif($voidBlockers !== null && auth()->user()?->hasPermission('documents.void'))
    <div x-data="{ voidOpen: false }" class="d-inline-block">
        @if($voidBlockers === [])
            <button type="button" class="btn btn-outline-danger px-3" @click="voidOpen = true">
                <i class="bi bi-x-octagon me-1"></i> ยกเลิกเอกสาร
            </button>
        @else
            <button type="button" class="btn btn-outline-secondary px-3" disabled title="{{ implode(' / ', $voidBlockers) }}">
                <i class="bi bi-lock me-1"></i> ยกเลิกไม่ได้
            </button>
            <div class="small text-muted mt-1">{{ implode(' / ', $voidBlockers) }}</div>
        @endif
        <div x-show="voidOpen" x-cloak class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background: rgba(15,23,42,.45); z-index: 1060;" @click.self="voidOpen = false">
            <form method="post" action="{{ route('documents.void', $doc) }}" class="content-card p-4" style="width: min(480px, 92vw);"
                  data-confirm="ยืนยันยกเลิก {{ $doc->doc_number }}? ระบบจะกลับรายการสต๊อกและบัญชี ย้อนไม่ได้" data-confirm-ok="ยกเลิกเอกสาร" data-confirm-icon="warning">
                @csrf
                <h3 class="h6 fw-bold mb-1">ยกเลิก {{ $doc->doc_number }}</h3>
                <div class="text-muted small mb-3">กลับรายการสต๊อก GL และลูกหนี้/เจ้าหนี้ เก็บประวัติไว้ตรวจย้อน</div>
                <label class="form-label small fw-semibold">เหตุผล <span class="text-danger">*</span></label>
                <textarea name="reason" class="form-control mb-3" rows="3" maxlength="500" required placeholder="เช่น คีย์ลูกค้าผิด / ลูกค้ายกเลิกออเดอร์"></textarea>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-light border" @click="voidOpen = false">ปิด</button>
                    <button class="btn btn-danger px-4">ยกเลิกเอกสาร</button>
                </div>
            </form>
        </div>
    </div>
@endif
