@extends('layout')
@section('title', 'ผู้ใช้และสิทธิ์ - POPSTAR ERP')
@section('page-title', 'ผู้ใช้และสิทธิ์')
@section('page-subtitle', 'จัดการผู้ใช้ บทบาท และสิทธิ์การใช้งานตามแบบฉบับ BPlus')
@section('content')
<div x-data="userPage()" x-cloak>

    <div class="content-card p-4 mb-3">
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="uf-head-icon"><i class="bi" :class="editId ? 'bi-pencil-square' : 'bi-person-plus'"></i></span>
            <div>
                <h2 class="h5 fw-bold mb-0" x-text="editId ? 'แก้ไขผู้ใช้: ' + editUsername : 'เพิ่มผู้ใช้ใหม่'"></h2>
                <p class="text-muted small mb-0">รหัสผ่านต้องยาวอย่างน้อย 8 ตัว มีตัวพิมพ์เล็ก พิมพ์ใหญ่ และตัวเลข (เก็บแบบเข้ารหัส bcrypt)</p>
            </div>
        </div>

        <form method="post" :action="editId ? '{{ url('users') }}/' + editId : '{{ route('users.store') }}'" class="user-form"
              @submit="if (!form.role_ids || form.role_ids.length === 0) { $event.preventDefault(); roleError = true; window.scrollTo({top:0,behavior:'smooth'}); }">
            @csrf
            <template x-if="editId"><input type="hidden" name="_method" value="PUT"></template>

            <div class="form-section-title"><i class="bi bi-person-badge"></i> ข้อมูลผู้ใช้</div>
            <div class="row g-3 mb-3">
                <div class="col-md-6 col-lg-4">
                    <label class="form-label small text-muted">ชื่อผู้ใช้ (username)</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-at"></i></span>
                        <input name="username" x-model="form.username" :readonly="!!editId" :required="!editId" class="form-control" autocomplete="off">
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label small text-muted">ชื่อ-นามสกุล (เต็ม)</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input name="name" x-model="form.name" required class="form-control" placeholder="เช่น สมศักดิ์ ใจดี">
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label small text-muted">ตำแหน่ง</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-briefcase"></i></span>
                        <input name="position" x-model="form.position" class="form-control" placeholder="เช่น แคชเชียร์สาขาวาริน">
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label small text-muted">โทรศัพท์</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                        <input name="phone" x-model="form.phone" class="form-control">
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label small text-muted">อีเมล</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="email" x-model="form.email" class="form-control">
                    </div>
                </div>
            </div>

            <div class="form-section-title"><i class="bi bi-shield-lock"></i> การเข้าถึง &amp; POS</div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label small text-muted">สาขาประจำ <span class="text-danger">(POS ล็อกสาขานี้)</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-shop"></i></span>
                        <select name="branch_id" x-model="form.branch_id" class="form-select">
                            <option value="">-- ทุกสาขา / ส่วนกลาง --</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->code }} - {{ $b->name_th }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label small text-muted">รหัสพนักงานขาย/แคชเชียร์ <span class="text-danger">(POS ขายในชื่อนี้)</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-vcard"></i></span>
                        <select name="salesman_id" x-model="form.salesman_id" class="form-select">
                            <option value="">-- ไม่ใช่คนขาย (ขาย POS ไม่ได้) --</option>
                            @foreach($salesmen as $s)
                                <option value="{{ $s->id }}">{{ $s->code }} - {{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label small text-muted d-flex align-items-center flex-wrap gap-1">
                        <i class="bi bi-shield-check" style="color:var(--fa-blue)"></i>
                        <span>บทบาท / สิทธิ์</span>
                        <span class="text-muted">— เลือกได้หลายอัน (เช่น แคชเชียร์ที่รับ/โอนของด้วย ติ๊ก <b>Cashier</b> + <b>คลังสินค้า</b>)</span>
                    </label>
                    <div class="role-grid" @change="roleError = false">
                        @foreach($roles as $role)
                            <label class="role-chip">
                                <input type="checkbox" name="role_ids[]" value="{{ $role->id }}" x-model.number="form.role_ids">
                                <span>{{ $role->name }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="text-danger small mt-1" x-show="roleError" x-cloak>กรุณาเลือกบทบาทอย่างน้อย 1 อัน</div>
                </div>
            </div>

            <div class="form-section-title"><i class="bi bi-key"></i> รหัสผ่าน</div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small text-muted" x-text="editId ? 'รหัสผ่านใหม่ (เว้นว่าง = ไม่เปลี่ยน)' : 'รหัสผ่าน'"></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-key"></i></span>
                        <input type="password" name="password" :required="!editId" class="form-control" autocomplete="new-password">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">ยืนยันรหัสผ่าน</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                        <input type="password" name="password_confirmation" :required="!editId" class="form-control" autocomplete="new-password">
                    </div>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <template x-if="editId">
                        <div class="form-check form-switch mb-2">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" class="form-check-input" id="uActive" x-model="form.is_active">
                            <label class="form-check-label small" for="uActive">เปิดใช้งานบัญชีนี้</label>
                        </div>
                    </template>
                </div>
            </div>

            <hr class="my-4" style="border-color:#eef2f6">
            <div class="d-flex gap-2">
                <button class="btn btn-primary px-5"><i class="bi bi-check-lg me-1"></i><span x-text="editId ? 'บันทึกการแก้ไข' : 'เพิ่มผู้ใช้'"></span></button>
                <button type="button" class="btn btn-light border" x-show="editId" @click="resetForm()">ยกเลิกแก้ไข</button>
            </div>
        </form>
    </div>

    <div class="content-card p-4 mb-3">
        <h2 class="h5 fw-bold mb-3"><i class="bi bi-people-fill me-2" style="color:var(--fa-blue)"></i>รายชื่อผู้ใช้</h2>
        <div class="table-responsive">
            <table class="table align-middle user-list-table">
                <thead><tr><th>ผู้ใช้</th><th>ชื่อ-นามสกุล</th><th>ตำแหน่ง</th><th>สาขา</th><th>บทบาท</th><th>เข้าใช้ล่าสุด</th><th>สถานะ</th><th></th></tr></thead>
                <tbody>
                @forelse($users as $user)
                    <tr>
                        <td class="fw-semibold">{{ $user->username }}</td>
                        <td>{{ $user->name }}<div class="text-muted small">{{ $user->phone }}</div></td>
                        <td class="small">{{ $user->position ?? '-' }}</td>
                        <td class="small">{{ $user->branch?->name_th ?? 'ส่วนกลาง' }}</td>
                        <td>
                            @foreach($user->roles as $role)
                                <span class="badge text-bg-primary">{{ $role->name }}</span>
                            @endforeach
                        </td>
                        <td class="small text-muted">{{ $user->last_login_at?->thaiDate(true) ?? 'ยังไม่เคย' }}</td>
                        <td><span class="badge {{ $user->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $user->is_active ? 'ใช้งาน' : 'ปิด' }}</span></td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-light border"
                                @click="editUser({{ json_encode(['id' => $user->id, 'username' => $user->username, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'position' => $user->position, 'branch_id' => $user->branch_id, 'salesman_id' => $user->salesman_id, 'role_ids' => $user->roles->pluck('id'), 'is_active' => $user->is_active]) }})">
                                แก้ไข
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-5">ยังไม่มีผู้ใช้ — เพิ่มคนแรกด้านบน</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $users->links() }}
    </div>

    <div class="content-card p-4">
        <h2 class="h5 fw-bold mb-3"><i class="bi bi-diagram-3-fill me-2" style="color:var(--fa-blue)"></i>บทบาทและสิทธิ์ (ตามแบบ BPlus)</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>บทบาท</th><th>สิทธิ์ที่ได้รับ</th></tr></thead>
                <tbody>
                @foreach($roles as $role)
                    <tr>
                        <td class="fw-semibold text-nowrap">{{ $role->name }} <span class="text-muted small">({{ $role->code }})</span></td>
                        <td>
                            @foreach($role->permissions ?? [] as $perm)
                                <span class="badge text-bg-light border me-1">{{ $perm->name }}</span>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-muted small mb-0">หมายเหตุ: สิทธิ์ถูกบันทึกไว้ในระบบแล้ว การบังคับใช้จริง (login + จำกัดเมนูตามสิทธิ์) เป็นขั้นตอนถัดไปก่อนใช้งานจริง</p>
    </div>
</div>
@endsection

@push('head')<style>
    [x-cloak]{display:none!important}
    .uf-head-icon{
        width:44px;height:44px;flex:0 0 44px;border-radius:13px;display:grid;place-items:center;
        background:linear-gradient(135deg,#e0f2fe,#bae6fd);color:var(--fa-blue-deep,#1585c0);font-size:20px;
    }
    .form-section-title{
        display:flex;align-items:center;gap:.5rem;
        font-weight:700;font-size:.88rem;color:var(--fa-blue-deep,#1585c0);
        margin:.35rem 0 .9rem;padding-bottom:.45rem;border-bottom:1px dashed #dbe7f1;
    }
    .form-section-title i{color:var(--fa-blue,#1a9bdc);font-size:1rem}
    .user-form .form-label{margin-bottom:.25rem;font-weight:600}
    .user-form .input-group-text{background:#f2f8fc;border-color:#e2e8f0;color:#7fa1bd}
    .user-form .input-group:focus-within .input-group-text{border-color:#10b981;color:var(--fa-blue)}
    .user-form .role-grid{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.15rem}
    .user-form .role-chip{margin:0;cursor:pointer}
    .user-form .role-chip input{position:absolute;opacity:0;width:0;height:0}
    .user-form .role-chip span{
        display:inline-block;padding:.4rem .85rem;border-radius:999px;
        border:1.5px solid #e2e8f0;background:#fafbfc;color:#52708a;
        font-size:.85rem;font-weight:600;transition:all .12s;user-select:none;
    }
    .user-form .role-chip:hover span{border-color:#bcd9ec;color:var(--fa-blue-deep,#1585c0)}
    .user-form .role-chip input:checked + span{
        border-color:var(--fa-blue,#1a9bdc);background:#e3f3fc;color:var(--fa-blue-deep,#1585c0);
    }
    .user-form .role-chip input:checked + span::before{content:"\2713 ";font-weight:800}
    .user-form .role-chip input:focus-visible + span{box-shadow:0 0 0 3px rgba(26,155,220,.28)}
    .user-list-table{font-family:var(--erp-font-family);font-size:13px}
    .user-list-table td{font-weight:400;letter-spacing:0;line-height:1.35}
    .user-list-table td.fw-semibold{font-weight:700!important}
    .user-list-table .badge{font-family:inherit;font-size:11px;font-weight:700}
</style>@endpush

@push('scripts')
<script>
function userPage() {
    return {
        editId: null, editUsername: '', roleError: false,
        form: { username: '', name: '', email: '', phone: '', position: '', branch_id: '', salesman_id: '', role_ids: [], is_active: true },

        editUser(user) {
            this.editId = user.id;
            this.editUsername = user.username;
            this.form = {
                username: user.username,
                name: user.name || '',
                email: user.email || '',
                phone: user.phone || '',
                position: user.position || '',
                branch_id: user.branch_id || '',
                salesman_id: user.salesman_id || '',
                role_ids: user.role_ids || [],
                is_active: !!user.is_active,
            };
            this.roleError = false;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        resetForm() {
            this.editId = null;
            this.editUsername = '';
            this.roleError = false;
            this.form = { username: '', name: '', email: '', phone: '', position: '', branch_id: '', salesman_id: '', role_ids: [], is_active: true };
        },
    };
}
</script>
@endpush
