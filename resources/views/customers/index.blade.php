@extends('layout')

@section('title', 'ลูกค้า - PopCentral')
@section('page-title', 'ลูกค้า')
@section('page-subtitle', 'ทะเบียนลูกค้าและยอดค้างชำระ (AR)')

@section('content')
    <div x-data="customerPage()" x-cloak>
        <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
            <form method="get" class="d-flex gap-2" style="max-width: 520px;">
                <input type="text" name="q" value="{{ $q }}" class="form-control" placeholder="ค้นหารหัส/ชื่อลูกค้า">
                <input type="hidden" name="status" value="{{ $status }}">
                <button class="btn btn-light border"><i class="bi bi-search"></i></button>
            </form>
            <button type="button" class="btn btn-primary rounded-pill px-4" @click="modalOpen = true">
                <i class="bi bi-plus-lg me-1"></i> เพิ่มลูกค้า
            </button>
        </div>

        <div class="row g-3 mb-4">
            @foreach([
                ['key' => 'all', 'label' => 'ลูกค้าทั้งหมด', 'tone' => 'primary', 'icon' => 'bi-people-fill'],
                ['key' => 'active', 'label' => 'กำลังใช้งาน', 'tone' => 'success', 'icon' => 'bi-person-check-fill'],
                ['key' => 'inactive', 'label' => 'ไม่ใช้งาน / พักไว้', 'tone' => 'secondary', 'icon' => 'bi-person-dash-fill'],
            ] as $summary)
                <div class="col-md-4">
                    <a href="{{ route('customers.index', array_filter(['q' => $q, 'status' => $summary['key'] === 'all' ? null : $summary['key']])) }}" class="status-summary {{ $status === $summary['key'] ? 'is-selected' : '' }}">
                        <span class="status-summary-icon text-{{ $summary['tone'] }}"><i class="bi {{ $summary['icon'] }}"></i></span>
                        <span><small>{{ $summary['label'] }}</small><strong>{{ number_format($counts[$summary['key']]) }}</strong></span>
                    </a>
                </div>
            @endforeach
        </div>

        @if($counts['missing_name'] > 0)
            <div class="alert alert-warning border-0 d-flex align-items-center justify-content-between gap-3 mb-4" role="alert">
                <div>
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    พบลูกค้า <strong>{{ number_format($counts['missing_name']) }} ราย</strong> ที่ไม่มีชื่อในข้อมูลเดิม ระบบจะแสดงสถานะให้แก้ไขแทนการแสดงรหัสซ้ำเป็นชื่อลูกค้า
                </div>
                <span class="badge text-bg-warning text-nowrap">รอตรวจชื่อ</span>
            </div>
        @endif

        <div class="content-card p-4">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>รหัส</th><th>ชื่อลูกค้า</th><th>สาขา</th><th>ผู้ดูแล</th><th>สายการขาย</th>
                            <th class="text-end">วงเงินเครดิต</th><th class="text-end">ค้างชำระ</th><th>สถานะ</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($customers as $customer)
                            @php($needsName = trim((string) $customer->name_th) === trim((string) $customer->code))
                            <tr>
                                <td class="fw-semibold">{{ $customer->code }}</td>
                                <td>
                                    @if($needsName)
                                        <span class="text-danger fw-semibold"><i class="bi bi-exclamation-circle me-1"></i>ยังไม่ระบุชื่อลูกค้า</span>
                                        <div class="small text-muted">ไม่มีชื่อในแฟ้มลูกค้าเดิม</div>
                                    @else
                                        {{ $customer->name_th }}
                                    @endif
                                </td>
                                <td>{{ $customer->branch?->name_th ?? '-' }}</td>
                                <td>{{ $customer->salesUser?->name ?? '-' }}<div class="text-muted small">{{ $customer->salesUser?->username }}</div></td>
                                <td>{{ $customer->salesArea ? $customer->salesArea->code.' - '.$customer->salesArea->name : '-' }}</td>
                                <td class="text-end">{{ number_format($customer->credit_limit, 2) }}</td>
                                <td class="text-end {{ ($customer->outstanding_balance ?? 0) > 0 ? 'text-danger fw-semibold' : '' }}">
                                    {{ number_format($customer->outstanding_balance ?? 0, 2) }}
                                </td>
                                <td>
                                    <span class="badge {{ $customer->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                                        {{ $customer->is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('customers.show', $customer) }}" class="btn btn-sm {{ $needsName ? 'btn-warning' : 'btn-light border' }}">{{ $needsName ? 'แก้ไขชื่อ' : 'ดู' }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="py-5 text-center text-muted">ไม่พบลูกค้า</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $customers->links() }}</div>
        </div>

        <div class="booking-modal-backdrop" x-show="modalOpen" x-transition.opacity @keydown.escape.window="modalOpen = false">
            <div class="booking-modal" style="width: min(560px, 100%);" @click.outside="modalOpen = false" x-transition>
                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <h3 class="h4 fw-bold mb-0">เพิ่มลูกค้าใหม่</h3>
                    <button type="button" class="btn btn-light rounded-circle" @click="modalOpen = false"><i class="bi bi-x-lg"></i></button>
                </div>
                <form method="post" action="{{ route('customers.store') }}">
                    @csrf
                    <div class="modal-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label text-muted small">รหัสลูกค้า</label>
                                <input type="text" name="code" required class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small">สาขา</label>
                                <select name="branch_id" class="form-select">
                                    <option value="">-- ไม่ระบุ --</option>
                                    @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อลูกค้า (ไทย)</label>
                                <input type="text" name="name_th" required class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label text-muted small">ชื่อลูกค้า (อังกฤษ)</label>
                                <input type="text" name="name_en" class="form-control">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label text-muted small">เลขประจำตัวผู้เสียภาษี (ใช้ในรายงานภาษีขาย)</label>
                                <input type="text" name="tax_id" maxlength="20" class="form-control" placeholder="13 หลัก">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small">สาขาที่</label>
                                <input type="text" name="tax_branch" maxlength="10" class="form-control" placeholder="00000">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted small">วงเงินเครดิต</label>
                                <input type="number" step="0.01" min="0" name="credit_limit" value="0" class="form-control">
                            </div>
                            @if(auth()->user()->hasPermission('sales.assign'))
                                <div class="col-md-6">
                                    <label class="form-label text-muted small">ผู้ดูแลลูกค้า</label>
                                    <select name="sales_user_id" x-model="salesUserId" @change="syncAreaFromUser()" class="form-select">
                                        <option value="">-- ยังไม่ระบุ --</option>
                                        @foreach($salesUsers as $salesUser)
                                            <option value="{{ $salesUser->id }}">{{ $salesUser->username }} - {{ $salesUser->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-muted small">สายการขาย / สายส่ง</label>
                                    <select name="sales_area_id" x-model="salesAreaId" class="form-select">
                                        <option value="">-- ใช้สายประจำของผู้ดูแล --</option>
                                        @foreach($salesAreas as $area)
                                            <option value="{{ $area->id }}">{{ $area->code }} - {{ $area->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @else
                                <div class="col-12">
                                    <div class="alert alert-info py-2 mb-0 small">ระบบจะผูกคุณเป็นผู้ดูแล และใช้สายการขายประจำของคุณอัตโนมัติ</div>
                                </div>
                            @endif
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="checkbox" name="is_active" value="1" checked class="form-check-input" id="newCustomerActive">
                                    <label class="form-check-label" for="newCustomerActive">ใช้งาน</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0">
                        <button type="button" class="btn btn-light border px-4" @click="modalOpen = false">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2-circle me-1"></i> บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('head')
<style>
    .status-summary { display:flex; align-items:center; gap:12px; height:78px; padding:14px 18px; background:#fff; border:1px solid #e5e7eb; border-radius:14px; color:inherit; text-decoration:none; transition:.15s ease; }
    .status-summary:hover, .status-summary.is-selected { border-color:#93c5fd; box-shadow:0 6px 18px rgba(30,64,175,.10); transform:translateY(-1px); }
    .status-summary-icon { width:40px; height:40px; display:grid; place-items:center; border-radius:12px; background:var(--erp-surface-2); font-size:20px; }
    .status-summary small { display:block; color:var(--erp-muted); font-size:12px; }
    .status-summary strong { display:block; color:#172b4d; font-size:22px; line-height:1.1; }
    [x-cloak] { display: none !important; }
    .booking-modal-backdrop {
        position: fixed; inset: 0; z-index: 2000;
        background: rgba(15, 23, 42, .42);
        display: flex; align-items: center; justify-content: center; padding: 24px;
    }
    .booking-modal { background: #fff; border-radius: 18px; box-shadow: 0 24px 80px rgba(15, 23, 42, .24); max-height: calc(100vh - 48px); overflow: auto; }
</style>
@endpush

@push('scripts')
<script>
    function customerPage() {
        return {
            modalOpen: false,
            salesUserId: '',
            salesAreaId: '',
            users: @js($salesUsers->map(fn ($user) => [
                'id' => (string) $user->id,
                'sales_area_id' => $user->sales_area_id ? (string) $user->sales_area_id : '',
            ])->values()),
            syncAreaFromUser() {
                const selected = this.users.find(user => user.id === String(this.salesUserId));
                if (selected?.sales_area_id) this.salesAreaId = selected.sales_area_id;
            },
        };
    }
</script>
@endpush
