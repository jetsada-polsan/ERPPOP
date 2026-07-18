@extends('layout')
@section('title', 'บัญชีธนาคาร - POPSTAR ERP')
@section('page-title', 'บัญชีธนาคาร')
@section('page-subtitle', 'ทะเบียนบัญชีธนาคารบริษัท สำหรับรับโอนและอ้างอิงในเอกสารชำระ')
@section('content')
    <div x-data="bankAccountPage()" x-cloak>
        <div class="list-toolbar">
            <div class="list-toolbar-left">
                <h2 class="h5 fw-bold mb-0">รายการบัญชีธนาคาร</h2>
                @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหาธนาคาร / เลขบัญชี'])
            </div>
            <button type="button" class="btn btn-primary rounded-pill px-4" @click="openCreate()"><i class="bi bi-plus-lg me-1"></i> เพิ่มบัญชี</button>
        </div>
        <div class="content-card p-4">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>ธนาคาร</th><th>เลขที่บัญชี</th><th>ชื่อบัญชี</th><th>สาขา</th><th></th></tr></thead>
                    <tbody>
                        @forelse($accounts as $acc)
                        <tr>
                            <td class="fw-semibold">{{ $acc->bank_name }}</td>
                            <td>{{ $acc->account_no }}</td>
                            <td>{{ $acc->account_name }}</td>
                            <td>{{ $acc->branch?->name_th ?? '-' }}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-light border"
                                    @click="openEdit({{ $acc->id }}, '{{ addslashes($acc->bank_name) }}', '{{ $acc->account_no }}', '{{ addslashes($acc->account_name ?? '') }}', {{ $acc->branch_id ?? 'null' }})">แก้ไข</button>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="py-5 text-center text-muted">ยังไม่มีบัญชีธนาคาร</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $accounts->links() }}</div>
        </div>

        <div class="booking-modal-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="modalOpen=false">
            <div class="booking-modal" style="width:min(520px,100%)" @click.outside="modalOpen=false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h5 fw-bold mb-0" x-text="editingId ? 'แก้ไขบัญชีธนาคาร' : 'เพิ่มบัญชีธนาคาร'"></h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="modalOpen=false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" :action="formAction">
                    @csrf
                    <template x-if="editingId"><input type="hidden" name="_method" value="PUT"></template>
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-12"><label class="form-label text-muted small">ธนาคาร</label><input type="text" name="bank_name" x-model="bankName" required class="form-control" placeholder="เช่น กสิกรไทย, ไทยพาณิชย์"></div>
                            <div class="col-6"><label class="form-label text-muted small">เลขที่บัญชี</label><input type="text" name="account_no" x-model="accountNo" required class="form-control"></div>
                            <div class="col-6"><label class="form-label text-muted small">ชื่อบัญชี</label><input type="text" name="account_name" x-model="accountName" class="form-control"></div>
                            <div class="col-12"><label class="form-label text-muted small">สาขา</label>
                                <select name="branch_id" x-model="branchId" class="form-select">
                                    <option value="">-- ไม่ระบุ --</option>
                                    @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} - {{ $b->name_th }}</option>@endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="modalOpen=false">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
@push('head')<style>[x-cloak]{display:none!important}.booking-modal-backdrop{position:fixed;inset:0;z-index:2000;background:rgba(15,23,42,.42);display:flex;align-items:center;justify-content:center;padding:24px}.booking-modal{background:#fff;border-radius:18px;box-shadow:0 24px 80px rgba(15,23,42,.24)}</style>@endpush
@push('scripts')
<script>
function bankAccountPage() {
    return {
        modalOpen: false, editingId: null, bankName: '', accountNo: '', accountName: '', branchId: '',
        openCreate() { this.editingId=null; this.bankName=''; this.accountNo=''; this.accountName=''; this.branchId=''; this.modalOpen=true; },
        openEdit(id,bankName,accountNo,accountName,branchId) { this.editingId=id; this.bankName=bankName; this.accountNo=accountNo; this.accountName=accountName; this.branchId=branchId||''; this.modalOpen=true; },
        get formAction() { return this.editingId ? `{{ url('bank-accounts') }}/${this.editingId}` : `{{ route('bank-accounts.store') }}`; },
    };
}
</script>
@endpush
