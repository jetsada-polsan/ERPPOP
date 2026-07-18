@extends('layout')

@section('title', 'ผังบัญชี - POPSTAR ERP')
@section('page-title', 'ผังบัญชี')
@section('page-subtitle', 'รายการบัญชีแยกประเภท สำหรับใช้อ้างอิงในระบบบัญชีต่อไป')

@section('content')
    <div x-data="chartOfAccountPage()" x-cloak>
        <ul class="nav nav-pills mb-4">
            <li class="nav-item"><span class="nav-link active">ผังบัญชี</span></li>
            <li class="nav-item"><a href="{{ route('gl-journals.index') }}" class="nav-link">สมุดรายวันทั่วไป</a></li>
        </ul>

        <div class="list-toolbar">
            <div class="list-toolbar-left">
                <h2 class="h5 fw-bold mb-0">ผังบัญชี</h2>
                @include('partials.search-bar', ['q' => $q, 'placeholder' => 'ค้นหารหัส / ชื่อบัญชี'])
            </div>
            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <button type="button" class="btn btn-outline-primary rounded-pill px-4" @click="importOpen = true">
                    <i class="bi bi-file-earmark-arrow-up me-1"></i> นำเข้า Excel
                </button>
                <button type="button" class="btn btn-primary rounded-pill px-4" @click="openCreate()">
                    <i class="bi bi-plus-lg me-1"></i> เพิ่มบัญชี
                </button>
            </div>
        </div>

        <div class="content-card p-4 mb-4">
            <h3 class="h6 fw-bold mb-3">บัญชีเริ่มต้นสำหรับลงบัญชีอัตโนมัติ</h3>
            <div class="text-muted small mb-3">เมื่อตั้งครบทั้ง 3 บัญชี ระบบจะลงบัญชี (สมุดรายวันทั่วไป) ให้อัตโนมัติทุกครั้งที่มีการรับ/จ่ายชำระหนี้</div>
            <div class="row g-3">
                @foreach($roles as $roleKey => $roleLabel)
                <div class="col-md-4">
                    <div class="border rounded-3 p-3">
                        <div class="text-muted small">{{ $roleLabel }}</div>
                        @if($roleHolders->has($roleKey))
                            <div class="fw-bold">{{ $roleHolders[$roleKey]->code }} - {{ $roleHolders[$roleKey]->name_th }}</div>
                        @else
                            <div class="fw-bold text-warning"><i class="bi bi-exclamation-triangle me-1"></i>ยังไม่ได้ตั้งค่า</div>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        </div>

        @foreach($types as $typeKey => $typeLabel)
            <div class="content-card p-4 mb-4">
                <h3 class="h6 fw-bold mb-3">{{ $typeLabel }}</h3>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr><th style="width:140px">รหัสบัญชี</th><th>ชื่อบัญชี (ไทย)</th><th>ชื่อบัญชี (อังกฤษ)</th><th>บทบาทเริ่มต้น</th><th></th></tr>
                        </thead>
                        <tbody>
                            @forelse($accounts->get($typeKey, collect()) as $account)
                            <tr>
                                <td class="fw-semibold">{{ $account->code }}</td>
                                <td>{{ $account->name_th }}</td>
                                <td class="text-muted">{{ $account->name_en }}</td>
                                <td>
                                    @if($account->default_role)
                                        <span class="badge text-bg-info">{{ $roles[$account->default_role] ?? $account->default_role }}</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-light border"
                                        @click="openEdit({{ $account->id }}, '{{ $account->code }}', '{{ addslashes($account->name_th) }}', '{{ addslashes($account->name_en ?? '') }}', '{{ $typeKey }}', '{{ $account->default_role }}')">
                                        แก้ไข
                                    </button>
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">ยังไม่มีบัญชีในหมวดนี้</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach

        <div class="booking-modal-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="modalOpen = false">
            <div class="booking-modal" @click.outside="modalOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h5 fw-bold mb-0" x-text="editingId ? 'แก้ไขบัญชี' : 'เพิ่มบัญชี'"></h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="modalOpen = false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" :action="formAction">
                    @csrf
                    <template x-if="editingId"><input type="hidden" name="_method" value="PUT"></template>
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label text-muted small">รหัสบัญชี</label>
                                <input type="text" name="code" x-model="code" required class="form-control">
                            </div>
                            <div class="col-6">
                                <label class="form-label text-muted small">ประเภทบัญชี</label>
                                <select name="account_type" x-model="accountType" required class="form-select">
                                    @foreach($types as $typeKey => $typeLabel)
                                        <option value="{{ $typeKey }}">{{ $typeLabel }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อบัญชี (ไทย)</label>
                                <input type="text" name="name_th" x-model="nameTh" required class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อบัญชี (อังกฤษ)</label>
                                <input type="text" name="name_en" x-model="nameEn" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">บทบาทเริ่มต้น (สำหรับลงบัญชีอัตโนมัติ)</label>
                                <select name="default_role" x-model="defaultRole" class="form-select">
                                    <option value="">-- ไม่กำหนด --</option>
                                    @foreach($roles as $roleKey => $roleLabel)
                                        <option value="{{ $roleKey }}">{{ $roleLabel }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">การเลือกบทบาทนี้จะแทนที่บัญชีเดิมที่เคยตั้งบทบาทนี้ไว้โดยอัตโนมัติ</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="modalOpen = false">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="booking-modal-backdrop" x-show="importOpen" x-transition.opacity @keydown.escape.window="importOpen = false">
            <div class="booking-modal" @click.outside="importOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <div>
                        <h3 class="h5 fw-bold mb-1">นำเข้าผังบัญชี</h3>
                        <div class="text-muted small">รองรับไฟล์ Excel จาก BPlus: รหัสบัญชี, ชื่อบัญชีไทย, ชื่อบัญชีอังกฤษ, ประเภทบัญชี</div>
                    </div>
                    <button type="button" class="btn btn-light rounded-circle" @click="importOpen = false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" action="{{ route('chart-of-accounts.import') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-body px-4 pb-4">
                        <label class="form-label text-muted small">ไฟล์ .xls / .xlsx</label>
                        <input type="file" name="file" class="form-control" accept=".xls,.xlsx" required>
                        <label class="form-check mt-3">
                            <input type="hidden" name="assign_default_roles" value="0">
                            <input type="checkbox" name="assign_default_roles" value="1" class="form-check-input" checked>
                            <span class="form-check-label">ตั้งบัญชีเริ่มต้นสำหรับลง GL อัตโนมัติจากรหัสมาตรฐาน</span>
                        </label>
                        <div class="alert alert-info small mt-3 mb-0">
                            ถ้ารหัสบัญชีมีอยู่แล้ว ระบบจะอัปเดตชื่อ/ประเภทตามไฟล์ โดยไม่ลบรายการบัญชีเดิม
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="importOpen = false">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4">นำเข้า</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('head')
<style>
    [x-cloak] { display: none !important; }
    .booking-modal-backdrop {
        position: fixed; inset: 0; z-index: 2000;
        background: rgba(15, 23, 42, .42);
        display: flex; align-items: center; justify-content: center; padding: 24px;
    }
    .booking-modal {
        width: min(480px, 100%); background: #fff; border-radius: 18px;
        box-shadow: 0 24px 80px rgba(15, 23, 42, .24);
    }
</style>
@endpush

@push('scripts')
<script>
    function chartOfAccountPage() {
        return {
            modalOpen: false,
            importOpen: false,
            editingId: null,
            code: '', nameTh: '', nameEn: '', accountType: 'asset', defaultRole: '',
            openCreate() {
                this.editingId = null;
                this.code = ''; this.nameTh = ''; this.nameEn = ''; this.accountType = 'asset'; this.defaultRole = '';
                this.modalOpen = true;
            },
            openEdit(id, code, nameTh, nameEn, accountType, defaultRole) {
                this.editingId = id;
                this.code = code; this.nameTh = nameTh; this.nameEn = nameEn; this.accountType = accountType;
                this.defaultRole = defaultRole && defaultRole !== 'null' ? defaultRole : '';
                this.modalOpen = true;
            },
            get formAction() {
                return this.editingId
                    ? `{{ url('chart-of-accounts') }}/${this.editingId}`
                    : `{{ route('chart-of-accounts.store') }}`;
            },
        };
    }
</script>
@endpush
