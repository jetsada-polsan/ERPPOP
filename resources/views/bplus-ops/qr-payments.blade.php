@extends('layout')
@section('title', 'QR PromptPay / EDC')
@section('page-title', 'QR PromptPay / EDC')
@section('page-subtitle', 'ตั้งค่า PromptPay ID เพื่อให้ POS สร้าง QR รับเงินอัตโนมัติตามยอดบิล')

@section('content')
<div x-data="qrPage()" x-cloak>

{{-- Add form --}}
<div class="content-card p-4 mb-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h3 class="h6 fw-bold mb-1">เพิ่มช่องทาง QR / PromptPay</h3>
            <div class="text-muted small">
                <strong>PromptPay ID</strong> = เบอร์มือถือ 10 หลัก หรือเลขบัตร ปชช./เลขภาษี 13 หลัก ที่ผูก PromptPay กับธนาคารไว้
            </div>
        </div>
    </div>
    <form method="post" action="{{ route('bplus.qr-payments.store') }}" class="row g-2 align-items-end">
        @csrf
        <div class="col-md-1">
            <label class="form-label small fw-semibold text-muted">รหัส</label>
            <input name="code" required class="form-control form-control-sm" placeholder="PP01">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold text-muted">ชื่อ</label>
            <input name="name" required class="form-control form-control-sm" placeholder="PromptPay ส่วนตัว TTB">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold text-muted">ประเภท</label>
            <select name="qr_type" class="form-select form-select-sm">
                <option value="dynamic">Dynamic QR (มียอด)</option>
                <option value="static">Static QR (ไม่มียอด)</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold text-muted">บัญชีธนาคาร (รับเงินเข้า)</label>
            <select name="bank_account_id" class="form-select form-select-sm">
                <option value="">-- ไม่ระบุ --</option>
                @foreach($bankAccounts as $acc)
                    <option value="{{ $acc->id }}">{{ $acc->bank_name }} {{ $acc->account_no }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold text-muted">
                PromptPay ID <span class="text-danger">*</span>
            </label>
            <input name="merchant_ref" required class="form-control form-control-sm font-monospace"
                   placeholder="0812345678" inputmode="numeric" maxlength="13">
        </div>
        <div class="col-md-2 d-flex align-items-center gap-2">
            <div class="form-check mb-0">
                <input type="checkbox" name="is_active" value="1" checked class="form-check-input" id="qaActive">
                <label class="form-check-label small" for="qaActive">ใช้งาน</label>
            </div>
            <button type="submit" class="btn btn-primary btn-sm px-3">
                <i class="bi bi-plus-lg me-1"></i>เพิ่ม
            </button>
        </div>
    </form>
</div>

{{-- List --}}
<div class="content-card p-4 mb-4">
    <h3 class="h6 fw-bold mb-3">
        ช่องทาง QR ที่ตั้งค่าแล้ว
        <span class="badge text-bg-light border ms-1">{{ $configs->total() }}</span>
    </h3>

    @if($configs->isEmpty())
    <div class="text-center text-muted py-5">
        <i class="bi bi-qr-code fs-1 d-block mb-2 opacity-25"></i>
        ยังไม่มี config — กรอกแบบฟอร์มด้านบน
    </div>
    @else
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>รหัส</th>
                    <th>ชื่อ</th>
                    <th>PromptPay ID</th>
                    <th>บัญชีธนาคาร</th>
                    <th>สถานะ POS</th>
                    <th class="text-end">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                @foreach($configs as $cfg)
                @php
                    $hasId = $cfg->merchant_ref && $cfg->merchant_ref !== 'REPLACE_ME';
                    $ready = $cfg->is_active && $hasId;
                @endphp
                <tr>
                    <td class="fw-semibold">{{ $cfg->code }}</td>
                    <td>{{ $cfg->name }}</td>
                    <td>
                        @if($hasId)
                            <code class="fw-bold text-success">{{ $cfg->merchant_ref }}</code>
                        @else
                            <span class="badge text-bg-warning">
                                <i class="bi bi-exclamation-triangle me-1"></i>ยังไม่ได้ใส่ ID
                            </span>
                        @endif
                    </td>
                    <td class="text-muted small">
                        @if($cfg->bankAccount)
                            <div class="fw-semibold text-dark">{{ $cfg->bankAccount->bank_name }}</div>
                            <div>{{ $cfg->bankAccount->account_no }}</div>
                            @if($cfg->bankAccount->account_name)
                            <div class="text-muted">{{ $cfg->bankAccount->account_name }}</div>
                            @endif
                        @else
                            <span class="text-muted">-</span>
                        @endif
                    </td>
                    <td>
                        @if($ready)
                            <span class="badge text-bg-success">
                                <i class="bi bi-qr-code me-1"></i>พร้อมใช้งานบน POS
                            </span>
                        @elseif(!$hasId)
                            <span class="badge text-bg-warning">รอ PromptPay ID</span>
                        @else
                            <span class="badge text-bg-secondary">ปิดใช้งาน</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-light border"
                                @click="openEdit({{ $cfg->id }}, '{{ $cfg->code }}', '{{ addslashes($cfg->name) }}', '{{ $cfg->merchant_ref ?? '' }}', '{{ $cfg->qr_type }}', {{ $cfg->bank_account_id ?? 'null' }}, {{ $cfg->is_active ? 'true' : 'false' }})">
                                <i class="bi bi-pencil me-1"></i>แก้ไข
                            </button>
                            <button type="button" class="btn btn-light border text-danger"
                                @click="confirmDelete({{ $cfg->id }}, '{{ addslashes($cfg->name) }}')">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </div>
                        {{-- Hidden delete form --}}
                        <form :id="'del-'+{{ $cfg->id }}" method="post" action="{{ route('bplus.qr-payments.destroy', $cfg) }}" style="display:none">
                            @csrf @method('DELETE')
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    {{ $configs->links() }}
    @endif
</div>

{{-- Info card --}}
<div class="content-card p-4" style="border:1.5px dashed #e2e8f0">
    <div class="fw-bold mb-2 small"><i class="bi bi-info-circle me-1 text-primary"></i>PromptPay ID คืออะไร?</div>
    <div class="row g-2">
        <div class="col-md-4">
            <div class="border rounded-3 p-3">
                <div class="small fw-semibold mb-1"><i class="bi bi-phone me-1 text-success"></i>เบอร์มือถือ</div>
                <code class="text-success">0812345678</code>
                <div class="text-muted" style="font-size:11px;margin-top:3px">10 หลัก ผูกผ่าน mobile banking</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="border rounded-3 p-3">
                <div class="small fw-semibold mb-1"><i class="bi bi-person me-1 text-primary"></i>เลขบัตรประชาชน</div>
                <code class="text-primary">3XXXXXXXXXXXX</code>
                <div class="text-muted" style="font-size:11px;margin-top:3px">13 หลัก บุคคลธรรมดา</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="border rounded-3 p-3">
                <div class="small fw-semibold mb-1"><i class="bi bi-building me-1 text-warning"></i>เลขประจำตัวผู้เสียภาษี</div>
                <code class="text-warning">0105XXXXXXXXX</code>
                <div class="text-muted" style="font-size:11px;margin-top:3px">13 หลัก นิติบุคคล</div>
            </div>
        </div>
    </div>
</div>

{{-- Edit modal --}}
<div class="booking-modal-backdrop" x-show="editOpen" x-transition.opacity @keydown.escape.window="editOpen=false">
    <div class="booking-modal" @click.outside="editOpen=false" x-transition>
        <div class="p-4 border-bottom d-flex align-items-center justify-content-between">
            <div>
                <div class="fw-bold fs-6">แก้ไข QR / PromptPay</div>
                <div class="text-muted small" x-text="editName"></div>
            </div>
            <button type="button" class="btn btn-light btn-sm rounded-circle" @click="editOpen=false">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <form method="post" :action="editAction">
            @csrf @method('PUT')
            <input type="hidden" name="code" :value="editCode">
            <div class="p-4 row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold small">ชื่อ</label>
                    <input type="text" name="name" x-model="editName" required class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">ประเภท</label>
                    <select name="qr_type" x-model="editType" class="form-select">
                        <option value="dynamic">Dynamic QR (มียอด)</option>
                        <option value="static">Static QR (ไม่มียอด)</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">บัญชีธนาคาร</label>
                    <select name="bank_account_id" x-model="editBankId" class="form-select">
                        <option value="">-- ไม่ระบุ --</option>
                        @foreach($bankAccounts as $acc)
                            <option value="{{ $acc->id }}">{{ $acc->bank_name }} {{ $acc->account_no }} {{ $acc->account_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">PromptPay ID <span class="text-danger">*</span></label>
                    <input type="text" name="merchant_ref" x-model="editRef" required
                        class="form-control form-control-lg font-monospace fw-bold"
                        placeholder="เบอร์มือถือ 10 หลัก หรือเลขบัตร/เลขภาษี 13 หลัก"
                        inputmode="numeric" maxlength="13">
                    <div class="form-text">เช่น <code>0812345678</code> หรือ <code>3450300xxxxxx</code></div>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" x-model="editActive" class="form-check-input" id="editActive">
                        <label class="form-check-label fw-semibold small" for="editActive">เปิดใช้งานบน POS</label>
                    </div>
                </div>
            </div>
            <div class="px-4 pb-4 d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light border px-4" @click="editOpen=false">ยกเลิก</button>
                <button type="submit" class="btn btn-success px-4">
                    <i class="bi bi-check-circle me-1"></i>บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

</div>
@endsection

@push('head')
<style>
[x-cloak]{display:none!important}
.booking-modal-backdrop{position:fixed;inset:0;z-index:2000;background:rgba(15,23,42,.5);display:flex;align-items:center;justify-content:center;padding:24px}
.booking-modal{background:#fff;border-radius:16px;box-shadow:0 24px 80px rgba(15,23,42,.3);width:min(560px,100%);max-height:calc(100vh - 48px);overflow-y:auto}
</style>
@endpush

@push('scripts')
<script>
function qrPage() {
    return {
        editOpen: false,
        editId: null, editCode: '', editName: '', editRef: '',
        editType: 'dynamic', editBankId: '', editActive: true,

        openEdit(id, code, name, ref, type, bankId, active) {
            this.editId = id; this.editCode = code; this.editName = name;
            this.editRef = (ref && ref !== 'REPLACE_ME') ? ref : '';
            this.editType = type || 'dynamic';
            this.editBankId = bankId ? String(bankId) : '';
            this.editActive = active;
            this.editOpen = true;
            this.$nextTick(() => document.querySelector('[name=merchant_ref]')?.focus());
        },

        get editAction() {
            return `{{ url('bplus/qr-payments') }}/${this.editId}`;
        },

        confirmDelete(id, name) {
            Swal.fire({
                title: 'ลบ QR นี้?',
                text: `"${name}" จะถูกลบออกจากระบบ`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#ef4444',
            }).then(r => {
                if (r.isConfirmed) document.getElementById('del-' + id).submit();
            });
        },
    };
}
</script>
@endpush
