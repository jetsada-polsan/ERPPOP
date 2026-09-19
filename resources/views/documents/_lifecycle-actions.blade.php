@if(auth()->user()?->hasPermission('sales.manage'))
<div class="content-card p-3 mb-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <strong>สถานะเอกสาร</strong>
            <span class="badge ms-2 {{ ['draft'=>'text-bg-secondary','pending_approval'=>'text-bg-warning','approved'=>'text-bg-success','rejected'=>'text-bg-danger','active'=>'text-bg-success'][$sale->status] ?? 'text-bg-light' }}">
                {{ ['draft'=>'ร่าง','pending_approval'=>'รอตรวจ','approved'=>'อนุมัติแล้ว','rejected'=>'ตีกลับ','active'=>'ยืนยันแล้ว'][$sale->status] ?? $sale->status }}
            </span>
            @if($sale->approval_note)<span class="text-muted small ms-2">{{ $sale->approval_note }}</span>@endif
        </div>
        <div class="d-flex gap-2">
            @if(in_array($sale->status, ['draft','rejected'], true))
                <form method="post" action="{{ route('documents.submit', $sale) }}">@csrf<button class="btn btn-sm btn-outline-primary">ส่งตรวจ</button></form>
            @endif
            @if($sale->status === 'pending_approval')
                <form method="post" action="{{ route('documents.approve', $sale) }}">@csrf<button class="btn btn-sm btn-success">อนุมัติ</button></form>
                <form method="post" action="{{ route('documents.reject', $sale) }}" class="d-flex gap-1">
                    @csrf<input name="note" required class="form-control form-control-sm" placeholder="เหตุผลตีกลับ">
                    <button class="btn btn-sm btn-outline-danger">ตีกลับ</button>
                </form>
            @endif
        </div>
    </div>
</div>
@endif
