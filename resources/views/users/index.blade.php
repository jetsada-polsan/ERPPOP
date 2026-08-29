@extends('layout')
@section('title', 'ผู้ใช้และสิทธิ์ - PopCentral')
@section('page-title', 'ผู้ใช้และสิทธิ์')
@section('page-subtitle', 'จัดการผู้ใช้ บทบาท และสิทธิ์การใช้งานตามแบบฉบับ BPlus')
@section('content')
@php($resetResult = session('reset_pos_pin_result') ?? session('reset_password_result'))
<div x-data="userPage()" x-cloak>
    @if($errors->has('pos_pin'))
        <div class="alert alert-danger d-flex align-items-start gap-2 mb-3" role="alert">
            <i class="bi bi-exclamation-octagon-fill mt-1"></i>
            <div><strong>ออก PIN POS ไม่สำเร็จ</strong><div>{{ $errors->first('pos_pin') }}</div></div>
        </div>
    @endif

    <div class="content-card p-3 mb-3">
        <form method="get" class="d-flex align-items-end gap-2 flex-wrap">
            <div style="min-width:min(360px,100%)">
                <label class="form-label small text-muted mb-1">ค้นหาผู้ใช้</label>
                <input name="q" value="{{ $q }}" class="form-control" placeholder="รหัสพนักงาน / ชื่อ / ตำแหน่ง / โทรศัพท์">
            </div>
            <div style="min-width:190px">
                <label class="form-label small text-muted mb-1">สถานะ</label>
                <select name="status" class="form-select">
                    <option value="">ทุกสถานะ</option>
                    <option value="active" @selected($status === 'active')>เปิดใช้งาน</option>
                    <option value="inactive" @selected($status === 'inactive')>ปิดใช้งาน</option>
                    <option value="must_change" @selected($status === 'must_change')>รอเปลี่ยนรหัสผ่าน</option>
                </select>
            </div>
            <button class="btn btn-primary"><i class="bi bi-search me-1"></i>ค้นหา</button>
            <a href="{{ route('users.index') }}" class="btn btn-light border">ล้าง</a>
        </form>
    </div>

    <div class="content-card pos-users-panel p-3 mb-3" x-data="{ quick: '' }">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-2">
            <div>
                <h2 class="h5 fw-bold mb-1"><i class="bi bi-shop-window me-2" style="color:var(--erp-primary)"></i>ผู้ใช้สำหรับ POS</h2>
                <p class="text-muted small mb-0">เลือกคนที่กำลังจะเข้าเครื่อง POS ได้จากหน้านี้ทันที</p>
            </div>
            <div class="pos-quick-search input-group" style="max-width:320px">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input class="form-control" x-model="quick" placeholder="ค้นหาชื่อ / username / รหัสแคชเชียร์" autocomplete="off">
            </div>
        </div>
        <div class="pos-user-grid">
            @forelse($posUsers as $user)
                @php($posCashier = $user->salesman ?? $user->posCashierProfile)
                @php($posBranchMismatch = $user->branch_id && $posCashier?->branch_id && (int) $user->branch_id !== (int) $posCashier->branch_id)
                <article class="pos-user-card" x-show="!quick || '{{ strtolower($user->name.' '.$user->username.' '.($posCashier?->code ?? '')) }}'.includes(quick.toLowerCase())">
                    <div class="d-flex align-items-center gap-2">
                        <div class="pos-user-avatar"><i class="bi bi-person-fill"></i></div>
                        <div class="min-w-0"><strong class="d-block text-truncate">{{ $user->name }}</strong><span class="text-muted small">{{ $user->username }}</span></div>
                    </div>
                    <div class="pos-user-meta"><span><i class="bi bi-shop me-1"></i>{{ $user->branch?->code ?? 'ส่วนกลาง' }}</span><span><i class="bi bi-person-vcard me-1"></i>{{ $posCashier?->code ?? 'ยังไม่ผูก' }}</span></div>
                    <div class="pos-user-status {{ $posBranchMismatch ? 'needs' : ($posCashier?->pos_pin_hash && !$posCashier?->must_change_pin ? 'ready' : 'needs') }}">
                        <i class="bi {{ $posBranchMismatch ? 'bi-exclamation-octagon-fill' : ($posCashier?->pos_pin_hash && !$posCashier?->must_change_pin ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill') }}"></i>
                        {{ $posBranchMismatch ? 'สาขาไม่ตรง ต้องแก้ก่อน' : ($posCashier?->pos_pin_hash && !$posCashier?->must_change_pin ? 'พร้อมเข้า POS' : 'ต้องออก/เปลี่ยน PIN') }}
                    </div>
                    <button type="button" class="btn btn-sm {{ $posCashier && !$posBranchMismatch ? 'btn-outline-primary' : 'btn-light border' }} w-100 mt-2" @click="{{ $posCashier && !$posBranchMismatch ? "openPosPinReset({$user->id}, '".addslashes($user->username)."', '".addslashes($user->name)."')" : "editUser(".json_encode(['id' => $user->id, 'username' => $user->username, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'position' => $user->position, 'branch_id' => $user->branch_id, 'salesman_id' => $user->salesman_id, 'sales_area_id' => $user->sales_area_id, 'role_ids' => $user->roles->pluck('id'), 'is_active' => $user->is_active]).")" }}>
                        <i class="bi {{ $posCashier && !$posBranchMismatch ? 'bi-key' : 'bi-pencil-square' }} me-1"></i>{{ $posCashier && !$posBranchMismatch ? 'ออก PIN POS' : ($posBranchMismatch ? 'แก้สาขาให้ตรง' : 'ผูกแคชเชียร์') }}
                    </button>
                </article>
            @empty
                <div class="text-muted small py-3">ยังไม่มีผู้ใช้ที่มีสิทธิ์ขาย POS</div>
            @endforelse
        </div>
    </div>

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
                    <label class="form-label small text-muted">สายการขาย / สายส่งประจำ <span class="text-danger">(ใบจองดึงอัตโนมัติ)</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-signpost-split"></i></span>
                        <select name="sales_area_id" x-model="form.sales_area_id" class="form-select">
                            <option value="">-- ไม่ระบุสาย --</option>
                            @foreach($salesAreas as $area)
                                <option value="{{ $area->id }}">{{ $area->code }} - {{ $area->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label small text-muted">โปรไฟล์แคชเชียร์ POS <span class="text-danger">(สร้างอัตโนมัติได้)</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-vcard"></i></span>
                        <select name="salesman_id" x-model="form.salesman_id" class="form-select">
                            <option value="">-- ระบบสร้างให้เมื่อมีสิทธิ์ขาย POS --</option>
                            @foreach($salesmen as $s)
                                <option value="{{ $s->id }}">{{ $s->code }} - {{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-text">ผู้ดูแลสร้างคนจากหน้านี้หน้าเดียวได้ เลือกโปรไฟล์เดิมเฉพาะกรณีต้องผูกรหัส POS/BPlus เก่า</div>
                </div>
                <div class="col-12">
                    <label class="form-label small text-muted d-flex align-items-center flex-wrap gap-1">
                        <i class="bi bi-shield-check" style="color:var(--erp-primary)"></i>
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

            <hr class="my-4" style="border-color:var(--erp-border-soft,var(--erp-border))">
            <div class="d-flex gap-2">
                <button class="btn btn-primary px-5"><i class="bi bi-check-lg me-1"></i><span x-text="editId ? 'บันทึกการแก้ไข' : 'เพิ่มผู้ใช้'"></span></button>
                <button type="button" class="btn btn-light border" x-show="editId" @click="resetForm()">ยกเลิกแก้ไข</button>
            </div>
        </form>
    </div>

    <div class="content-card p-4 mb-3">
        <h2 class="h5 fw-bold mb-3"><i class="bi bi-people-fill me-2" style="color:var(--erp-primary)"></i>รายชื่อผู้ใช้</h2>
        <div class="table-responsive">
            <table class="table align-middle user-list-table">
                <thead><tr><th>ผู้ใช้</th><th>ชื่อ-นามสกุล</th><th>ตำแหน่ง</th><th>สาขา</th><th>สายการขาย</th><th>บทบาท</th><th>เข้าใช้ล่าสุด</th><th>สถานะ</th><th></th></tr></thead>
                <tbody>
                @forelse($users as $user)
                    <tr>
                        <td class="fw-semibold">{{ $user->username }}</td>
                        <td>{{ $user->name }}<div class="text-muted small">{{ $user->phone }}</div></td>
                        <td class="small">{{ $user->position ?? '-' }}</td>
                        <td class="small">{{ $user->branch?->name_th ?? 'ส่วนกลาง' }}</td>
                        <td class="small">{{ $user->salesArea ? $user->salesArea->code.' - '.$user->salesArea->name : '-' }}</td>
                        <td>
                            @foreach($user->roles as $role)
                                <span class="badge text-bg-primary">{{ $role->name }}</span>
                            @endforeach
                            <div class="mt-1">
                                @if($user->hasPermission('pos.use'))
                                    <span class="badge text-bg-light border">เปิด POS</span>
                                @endif
                                @if($user->hasPermission('pos.sell'))
                                    <span class="badge text-bg-success">ขาย POS</span>
                                    @if(!$user->branch_id || !$user->salesman_id || !$user->salesman?->pos_pin_hash)
                                        <span class="badge text-bg-warning">POS ยังตั้งค่าไม่ครบ</span>
                                    @endif
                                @endif
                            </div>
                        </td>
                        <td class="small text-muted">{{ $user->last_login_at?->thaiDate(true) ?? 'ยังไม่เคย' }}</td>
                        <td>
                            <span class="badge {{ $user->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $user->is_active ? 'ใช้งาน' : 'ปิด' }}</span>
                            @if($user->must_change_password)
                                <span class="badge text-bg-warning mt-1">ต้องเปลี่ยนรหัส</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-light border"
                                @click="editUser({{ json_encode(['id' => $user->id, 'username' => $user->username, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'position' => $user->position, 'branch_id' => $user->branch_id, 'salesman_id' => $user->salesman_id, 'sales_area_id' => $user->sales_area_id, 'role_ids' => $user->roles->pluck('id'), 'is_active' => $user->is_active]) }})">
                                <i class="bi bi-pencil me-1"></i>แก้ไข
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-warning"
                                @click="openReset({{ $user->id }}, @js($user->username), @js($user->name))">
                                <i class="bi bi-key me-1"></i>รหัส ERP
                            </button>
                            @if($user->salesman_id && $user->hasPermission('pos.sell'))
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                    @click="openPosPinReset({{ $user->id }}, @js($user->username), @js($user->name))">
                                    <i class="bi bi-calculator me-1"></i>PIN POS
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-5">ยังไม่มีผู้ใช้ — เพิ่มคนแรกด้านบน</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $users->links() }}
    </div>

    <div class="user-reset-backdrop" x-show="resetOpen" x-transition.opacity @keydown.escape.window="resetOpen = false">
        <div class="user-reset-modal" @click.outside="resetOpen = false" x-transition>
            <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                <div>
                    <h3 class="h5 fw-bold mb-1" x-text="resetType === 'pos_pin' ? 'ยืนยันออก PIN POS ใหม่' : 'ยืนยันรีเซ็ตรหัสผ่าน ERP'"></h3>
                    <div class="text-muted small"><strong x-text="resetUsername"></strong> · <span x-text="resetName"></span></div>
                </div>
                <button type="button" class="btn btn-light rounded-circle" @click="resetOpen = false" aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="alert alert-warning" x-show="resetType === 'password'">
                ระบบจะสร้าง<strong>รหัสชั่วคราวแบบสุ่ม</strong>ให้ใหม่ และแสดงรหัสหลังยืนยัน
                ผู้ใช้ต้องตั้งรหัสใหม่ทันทีเมื่อเข้าสู่ระบบ
            </div>
            <div class="alert alert-warning" x-show="resetType === 'pos_pin'">
                ระบบจะสร้าง <strong>PIN POS ชั่วคราว 6 หลัก</strong> แยกจากรหัสผ่าน ERP
                และถอนการยืนยัน PIN เดิมจากทุกเครื่อง ผู้ใช้ต้องเปลี่ยน PIN หลังเข้า POS
            </div>
            <form method="post" :action="resetAction" class="d-flex justify-content-end gap-2">
                @csrf
                <button type="button" class="btn btn-light border" @click="resetOpen = false">ยกเลิก</button>
                <button class="btn btn-warning"><i class="bi bi-key-fill me-1"></i>ยืนยัน</button>
            </form>
        </div>
    </div>

    <div class="user-reset-backdrop" x-show="resultOpen" x-transition.opacity @keydown.escape.window="resultOpen = false" style="display:none">
        <div class="user-reset-modal" @click.outside="resultOpen = false" x-transition>
            <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                <div>
                    <h3 class="h5 fw-bold mb-1" x-text="resultType === 'pos_pin' ? 'ออก PIN POS แล้ว' : 'รีเซ็ตรหัสผ่าน ERP แล้ว'"></h3>
                    <div class="text-muted small">ผู้ใช้ <strong x-text="resultUsername"></strong></div>
                </div>
                <button type="button" class="btn btn-light rounded-circle" @click="resultOpen = false" aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="alert alert-warning small mb-2"><i class="bi bi-exclamation-triangle me-1"></i><span x-text="resultType === 'pos_pin' ? 'PIN นี้แสดงครั้งเดียว ผู้ใช้ต้องเปลี่ยน PIN หลังเข้า POS' : 'รหัสนี้แสดงครั้งเดียว ผู้ใช้ต้องตั้งรหัสผ่านใหม่เมื่อเข้า ERP'"></span></div>
            <label class="form-label small text-muted mb-1" x-text="resultType === 'pos_pin' ? 'PIN POS ชั่วคราว' : 'รหัสผ่าน ERP ชั่วคราว'"></label>
            <div class="input-group mb-3">
                <input type="text" class="form-control font-monospace fw-bold" :value="resultPassword" readonly @focus="$el.select()">
                <button type="button" class="btn btn-outline-secondary" @click="copyResult()">
                    <i class="bi" :class="copied ? 'bi-check-lg' : 'bi-clipboard'"></i>
                    <span x-text="copied ? 'คัดลอกแล้ว' : 'คัดลอก'"></span>
                </button>
            </div>
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-primary" @click="resultOpen = false">เสร็จสิ้น</button>
            </div>
        </div>
    </div>

    <div class="content-card p-4">
        <h2 class="h5 fw-bold mb-3"><i class="bi bi-diagram-3-fill me-2" style="color:var(--erp-primary)"></i>บทบาทและสิทธิ์ (ตามแบบ BPlus)</h2>
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
        <p class="text-muted small mb-0">ระบบบังคับสิทธิ์ทุก route แล้ว: <b>เปิด POS</b> ใช้ <code>pos.use</code>, <b>เปิดกะ/ขาย</b> ใช้ <code>pos.sell</code>, และการยกเลิกบิลใช้ <code>pos.void</code> แยกกัน</p>
    </div>
</div>
@endsection

@push('head')<style>
    [x-cloak]{display:none!important}
    .uf-head-icon{
        width:44px;height:44px;flex:0 0 44px;border-radius:13px;display:grid;place-items:center;
        background:var(--erp-primary-soft);color:var(--erp-primary-ink);font-size:20px;
    }
    .form-section-title{
        display:flex;align-items:center;gap:.5rem;
        font-weight:700;font-size:.88rem;color:var(--erp-primary-dark);
        margin:.35rem 0 .9rem;padding-bottom:.45rem;border-bottom:1px dashed var(--erp-border);
    }
    .form-section-title i{color:var(--erp-primary);font-size:1rem}
    .user-form .form-label{margin-bottom:.25rem;font-weight:600}
    .user-form .input-group-text{background:var(--erp-surface-2,var(--erp-surface-2));border-color:var(--erp-border);color:var(--erp-muted)}
    .user-form .input-group:focus-within .input-group-text{border-color:var(--erp-primary);color:var(--erp-primary)}
    .user-form .role-grid{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.15rem}
    .user-form .role-chip{margin:0;cursor:pointer}
    .user-form .role-chip input{position:absolute;opacity:0;width:0;height:0}
    .user-form .role-chip span{
        display:inline-block;padding:.4rem .85rem;border-radius:999px;
        border:1.5px solid var(--erp-border);background:var(--erp-surface-2,var(--erp-surface-2));color:var(--erp-text);
        font-size:.85rem;font-weight:600;transition:all .12s;user-select:none;
    }
    .user-form .role-chip:hover span{border-color:var(--erp-primary);color:var(--erp-primary-dark)}
    .user-form .role-chip input:checked + span{
        border-color:var(--erp-primary);background:var(--erp-primary-soft);color:var(--erp-primary-dark);
    }
    .user-form .role-chip input:checked + span::before{content:"\2713 ";font-weight:800}
    .user-form .role-chip input:focus-visible + span{box-shadow:0 0 0 3px rgba(21,133,192,.28)}
    .user-list-table{font-family:var(--erp-font-family);font-size:13px}
    .user-list-table td{font-weight:400;letter-spacing:0;line-height:1.35}
    .user-list-table td.fw-semibold{font-weight:700!important}
    .user-list-table .badge{font-family:inherit;font-size:11px;font-weight:700}
    .pos-users-panel{background:linear-gradient(110deg,#f8fbff,#fff)}
    .pos-user-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(225px,1fr));gap:10px}
    .pos-user-card{border:1px solid var(--erp-border);border-radius:8px;background:#fff;padding:12px;min-width:0}
    .pos-user-avatar{width:34px;height:34px;border-radius:8px;display:grid;place-items:center;background:var(--erp-primary-soft);color:var(--erp-primary);flex:0 0 34px}
    .pos-user-meta{display:flex;justify-content:space-between;gap:8px;margin-top:12px;color:var(--erp-muted);font-size:11px}
    .pos-user-status{font-size:12px;font-weight:700;margin-top:9px}.pos-user-status.ready{color:#158662}.pos-user-status.needs{color:#b7791f}
    .user-reset-backdrop{position:fixed;inset:0;z-index:2100;background:rgba(15,23,42,.46);display:flex;align-items:center;justify-content:center;padding:20px}
    .user-reset-modal{width:min(520px,100%);background:var(--erp-surface,#fff);border-radius:8px;padding:24px;box-shadow:0 24px 80px rgba(15,23,42,.25)}
</style>@endpush

@push('scripts')
<script>
function userPage() {
    return {
        editId: null, editUsername: '', roleError: false,
        resetOpen: false, resetId: null, resetUsername: '', resetName: '', resetType: 'password',
        resultOpen: {{ $resetResult ? 'true' : 'false' }},
        resultType: @js(data_get($resetResult, 'type', 'password')),
        resultUsername: @js(data_get($resetResult, 'username', '')),
        resultPassword: @js(data_get($resetResult, 'password', '')),
        copied: false,
        form: { username: '', name: '', email: '', phone: '', position: '', branch_id: '', salesman_id: '', sales_area_id: '', role_ids: [], is_active: true },

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
                sales_area_id: user.sales_area_id || '',
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
            this.form = { username: '', name: '', email: '', phone: '', position: '', branch_id: '', salesman_id: '', sales_area_id: '', role_ids: [], is_active: true };
        },
        openReset(id, username, name) {
            this.resetId = id;
            this.resetUsername = username;
            this.resetName = name || '';
            this.resetType = 'password';
            this.resetOpen = true;
        },
        openPosPinReset(id, username, name) {
            this.resetId = id;
            this.resetUsername = username;
            this.resetName = name || '';
            this.resetType = 'pos_pin';
            this.resetOpen = true;
        },
        copyResult() {
            navigator.clipboard?.writeText(this.resultPassword)
                .then(() => { this.copied = true; setTimeout(() => this.copied = false, 1500); })
                .catch(() => {});
        },
        get resetAction() {
            const action = this.resetType === 'pos_pin' ? 'reset-pos-pin' : 'reset-password';
            return `{{ url('users') }}/${this.resetId}/${action}`;
        },
    };
}
</script>
@endpush
