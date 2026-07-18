@extends('layout')
@section('title', 'การตั้งค่า - POPSTAR ERP')
@section('page-title', 'การตั้งค่า')
@section('page-subtitle', 'ตั้งค่าเอกสาร ข้อมูลกิจการ ภาษี และการรับชำระ')
@section('content')

<form method="post" action="{{ route('settings.update') }}" enctype="multipart/form-data"
      x-data="{ tab: @js(session('pos_token') ? 'pos-download' : 'func'), choice: @js($currentLogo ?? '__none__'), theme: @js($erpTheme), copied: false, menuOrder: @js($menuOrder), moveMenu(i, d) { const n=i+d; if(n<0 || n>=this.menuOrder.length) return; const a=[...this.menuOrder]; [a[i],a[n]]=[a[n],a[i]]; this.menuOrder=a; } }">
    @csrf
    <input type="hidden" name="menu_order" :value="JSON.stringify(menuOrder)">

    <div class="set-shell">
        {{-- เมนูย่อยซ้ายแบบ FlowAccount --}}
        <div class="set-nav">
            <div class="set-nav-group"><i class="bi bi-file-earmark-text"></i> ตั้งค่าเอกสาร</div>
            <button type="button" class="set-nav-link" :class="tab === 'func' && 'active'" @click="tab = 'func'">ฟังก์ชั่นเอกสาร</button>
            <button type="button" class="set-nav-link" :class="tab === 'numbering' && 'active'" @click="tab = 'numbering'">เลขรันเอกสาร</button>
            <button type="button" class="set-nav-link" :class="tab === 'logo' && 'active'" @click="tab = 'logo'">โลโก้และตราประทับ</button>
            <button type="button" class="set-nav-link" :class="tab === 'note' && 'active'" @click="tab = 'note'">หมายเหตุเอกสาร</button>

            <div class="set-nav-group mt-3"><i class="bi bi-building"></i> ตั้งค่ากิจการ</div>
            <button type="button" class="set-nav-link" :class="tab === 'company' && 'active'" @click="tab = 'company'">ข้อมูลกิจการ</button>
            <button type="button" class="set-nav-link" :class="tab === 'menu' && 'active'" @click="tab = 'menu'">จัดลำดับเมนู</button>
            <button type="button" class="set-nav-link" :class="tab === 'theme' && 'active'" @click="tab = 'theme'">ปรับธีม</button>

            <div class="set-nav-group mt-3"><i class="bi bi-sliders"></i> ตั้งค่าด้านบัญชี</div>
            <button type="button" class="set-nav-link" :class="tab === 'payment' && 'active'" @click="tab = 'payment'">ข้อมูลการรับชำระ</button>
            <button type="button" class="set-nav-link" :class="tab === 'accounting' && 'active'" @click="tab = 'accounting'">บันทึกบัญชี</button>

            <div class="set-nav-group mt-3"><i class="bi bi-pc-display"></i> โปรแกรมหน้าร้าน</div>
            <button type="button" class="set-nav-link" :class="tab === 'pos-download' && 'active'" @click="tab = 'pos-download'">เปิด Vue POS</button>
        </div>

        {{-- เนื้อหาขวา --}}
        <div class="set-main">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h2 class="h4 fw-bold mb-0" style="color:var(--fa-blue-dark)"
                    x-text="{ func: 'ฟังก์ชั่นเอกสาร', numbering: 'เลขรันเอกสาร', logo: 'โลโก้และตราประทับ', note: 'หมายเหตุเอกสาร', company: 'ข้อมูลกิจการ', menu: 'จัดลำดับเมนูหลัก', theme: 'ปรับธีมระบบ', payment: 'ข้อมูลการรับชำระ', accounting: 'บันทึกบัญชี', 'pos-download': 'ดาวน์โหลดโปรแกรม POS' }[tab]"></h2>
                <button x-show="tab !== 'pos-download'" type="submit" class="btn btn-success px-4"><i class="bi bi-check-lg me-1"></i>บันทึกข้อมูล</button>
            </div>

            {{-- ฟังก์ชั่นเอกสาร --}}
            <div x-show="tab === 'func'">
                <div class="set-card">
                    <div class="set-row">
                        <div>
                            <div class="set-title">ตั้งค่าราคาที่แสดงในเอกสาร</div>
                            <div class="set-desc">แสดงราคาสินค้าหรือบริการในเอกสาร เป็นราคารวมภาษี หรือราคาไม่รวมภาษี</div>
                        </div>
                        <select name="price_includes_vat" class="form-select" style="width:200px">
                            <option value="1" @selected($doc['price_includes_vat'])>ราคารวมภาษี</option>
                            <option value="0" @selected(! $doc['price_includes_vat'])>ราคาไม่รวมภาษี</option>
                        </select>
                    </div>
                </div>
                <div class="set-card">
                    <div class="set-row">
                        <div>
                            <div class="set-title">อัตราภาษีมูลค่าเพิ่ม (VAT)</div>
                            <div class="set-desc">ใช้คำนวณภาษีในการลงบัญชีอัตโนมัติ รายงานภาษีขาย-ซื้อ (ภพ.30) และใบกำกับภาษี — เปลี่ยนแล้วมีผลกับเอกสารใหม่ตั้งแต่วันนี้</div>
                        </div>
                        <div class="input-group" style="width:150px">
                            <input type="number" step="0.01" min="0" max="30" name="vat_rate" value="{{ $doc['vat_rate'] }}" required class="form-control text-end">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="set-row">
                        <div>
                            <div class="set-title">เครดิตเริ่มต้น (ขายเชื่อ / ใบเพิ่มหนี้)</div>
                            <div class="set-desc">จำนวนวันครบกำหนดชำระของลูกหนี้ที่ระบบตั้งให้อัตโนมัติเมื่อเปิดใบขายเชื่อ</div>
                        </div>
                        <div class="input-group" style="width:150px">
                            <input type="number" min="0" max="365" name="credit_days" value="{{ $doc['credit_days'] }}" required class="form-control text-end">
                            <span class="input-group-text">วัน</span>
                        </div>
                    </div>
                </div>
            </div>

            <div x-show="tab === 'menu'" x-cloak>
                <div class="set-card menu-order-card">
                    <div class="set-title">ลำดับเมนูหลักบนแถบซ้าย</div>
                    <div class="set-desc mb-3">กดลูกศรเพื่อเลื่อนเมนูขึ้นหรือลง แล้วกดบันทึกข้อมูล สิทธิ์การมองเห็นเมนูของผู้ใช้ยังทำงานตามเดิม</div>
                    <template x-for="(item, index) in menuOrder" :key="item">
                        <div class="menu-order-row">
                            <span class="menu-order-index" x-text="index + 1"></span>
                            <strong x-text="item"></strong>
                            <div class="ms-auto d-flex gap-1">
                                <button type="button" @click="moveMenu(index,-1)" :disabled="index===0"><i class="bi bi-chevron-up"></i></button>
                                <button type="button" @click="moveMenu(index,1)" :disabled="index===menuOrder.length-1"><i class="bi bi-chevron-down"></i></button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div x-show="tab === 'theme'" x-cloak>
                <div class="set-card theme-settings-card">
                    <div class="set-title">ชุดสีของระบบ</div>
                    <div class="set-desc mb-3">เปลี่ยนสีเมนู ปุ่มหลัก และพื้นหลัง โดยขนาดหน้าจอและตัวอักษรยังคงเดิม</div>
                    <div class="theme-choice-grid">
                        @foreach([
                            'ocean' => ['ฟ้า JET', '#1a9bdc', '#1585c0', '#eef4f9'],
                            'navy' => ['กรมท่า', '#315b86', '#244768', '#eef2f7'],
                            'emerald' => ['เขียวมรกต', '#23966c', '#187653', '#eef7f3'],
                            'slate' => ['เทาสเลต', '#64748b', '#475569', '#f1f3f5'],
                        ] as $key => [$label, $primary, $deep, $bg])
                            <label class="theme-choice" :class="theme === '{{ $key }}' && 'active'">
                                <input type="radio" name="erp_theme" value="{{ $key }}" x-model="theme">
                                <span class="theme-preview" style="--preview-primary:{{ $primary }};--preview-deep:{{ $deep }};--preview-bg:{{ $bg }}"><i></i><b></b></span>
                                <strong>{{ $label }}</strong>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- เลขรันเอกสาร --}}
            <div x-show="tab === 'numbering'" x-cloak>
                <div class="set-card">
                    <div class="set-row">
                        <div>
                            <div class="set-title">เลขที่เอกสารอัตโนมัติ</div>
                            <div class="set-desc">ทุกเอกสารรันเลขให้อัตโนมัติ: <code>คำนำหน้า + รหัสสาขา + วันที่ + ลำดับ</code> เช่น <code>DS0001{{ now()->format('Ymd') }}001</code> — ผู้ใช้พิมพ์เลขเองไม่ได้ เพื่อกันเลขซ้ำ/ข้าม</div>
                        </div>
                    </div>
                    <div class="set-row">
                        <div>
                            <div class="set-title">สมุดเอกสาร (แยกเล่ม)</div>
                            <div class="set-desc">เอกสารประเภทเดียวแยกได้หลายเล่ม แต่ละเล่มมีคำนำหน้าและเลขรันของตัวเอง (เช่น DS / DSN) — ตอนนี้ใช้งานอยู่ {{ $bookCount }} เล่ม</div>
                        </div>
                        <a href="{{ route('document-books.index') }}" class="btn btn-light border text-nowrap">จัดการสมุดเอกสาร <i class="bi bi-arrow-right ms-1"></i></a>
                    </div>
                </div>
            </div>

            {{-- โลโก้ --}}
            <div x-show="tab === 'logo'" x-cloak>
                <div class="set-card">
                    <div class="set-title mb-1">โลโก้ระบบ</div>
                    <div class="set-desc mb-3">ใช้แสดงบนเมนูซ้าย หน้า POS และหัวเอกสาร/รายงานตอนพิมพ์ — เลือกจากชุดที่มี หรืออัปโหลดไฟล์ใหม่</div>

                    <div class="logo-grid mb-3">
                        <label class="logo-option" :class="{ active: choice === '__none__' }">
                            <input type="radio" name="logo_choice" value="__none__" x-model="choice" class="d-none">
                            <div class="logo-thumb logo-thumb-text">pop<span>star</span></div>
                            <div class="logo-caption">ไม่ใช้รูป (ตัวอักษรเดิม)</div>
                        </label>
                        @foreach($presets as $preset)
                            <label class="logo-option" :class="{ active: choice === '{{ $preset }}' }">
                                <input type="radio" name="logo_choice" value="{{ $preset }}" x-model="choice" class="d-none">
                                <div class="logo-thumb"><img src="{{ asset($preset) }}" alt=""></div>
                                <div class="logo-caption">{{ basename($preset) }}</div>
                            </label>
                        @endforeach
                    </div>

                    <label class="form-label small text-muted">หรืออัปโหลดโลโก้ใหม่ (png / jpg / webp / svg ไม่เกิน 4MB)</label>
                    <input type="file" name="logo_file" accept=".png,.jpg,.jpeg,.webp,.svg" class="form-control" style="max-width:420px">
                </div>
            </div>

            {{-- หมายเหตุเอกสาร --}}
            <div x-show="tab === 'note'" x-cloak>
                <div class="set-card">
                    <div class="set-title mb-1">หมายเหตุท้ายเอกสาร</div>
                    <div class="set-desc mb-3">ข้อความที่พิมพ์ท้ายใบเสนอราคาทุกใบอัตโนมัติ เช่น เงื่อนไขการชำระเงิน กำหนดส่งของ หรือข้อความขอบคุณ</div>
                    <textarea name="footer_note" rows="3" class="form-control" placeholder="เช่น สินค้าแช่แข็งกรุณาตรวจรับทันทีเมื่อส่งมอบ / ราคานี้ยืน 15 วันนับจากวันที่เสนอ">{{ $doc['footer_note'] }}</textarea>
                </div>
            </div>

            {{-- ข้อมูลกิจการ --}}
            <div x-show="tab === 'company'" x-cloak>
                <div class="set-card">
                    <div class="set-title mb-1">ข้อมูลกิจการ</div>
                    <div class="set-desc mb-3">แสดงบนหัวเอกสารพิมพ์ทุกใบ: ใบกำกับภาษี ใบเสนอราคา ใบวางบิล รายงาน ฯลฯ</div>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label small text-muted">ชื่อบริษัท (ไทย)</label><input name="company_name_th" required class="form-control" value="{{ $company['name_th'] }}"></div>
                        <div class="col-md-6"><label class="form-label small text-muted">ชื่อบริษัท (อังกฤษ)</label><input name="company_name_en" class="form-control" value="{{ $company['name_en'] }}"></div>
                        <div class="col-md-4"><label class="form-label small text-muted">เลขทะเบียนนิติบุคคล / เลขผู้เสียภาษี</label><input name="company_tax_id" class="form-control" value="{{ $company['tax_id'] }}"></div>
                        <div class="col-md-4"><label class="form-label small text-muted">โทรศัพท์</label><input name="company_phone" class="form-control" value="{{ $company['phone'] }}"></div>
                        <div class="col-12"><label class="form-label small text-muted">ที่อยู่</label><input name="company_address" class="form-control" value="{{ $company['address'] }}"></div>
                    </div>
                </div>
            </div>

            {{-- การรับชำระ --}}
            <div x-show="tab === 'payment'" x-cloak>
                <div class="set-card">
                    <div class="set-row">
                        <div>
                            <div class="set-title">บัญชีธนาคาร</div>
                            <div class="set-desc">บัญชีสำหรับรับชำระ/จ่ายชำระ และผูกกับทะเบียนเช็ค — ตอนนี้มี {{ $bankCount }} บัญชี</div>
                        </div>
                        <a href="{{ route('bank-accounts.index') }}" class="btn btn-light border text-nowrap">จัดการบัญชี <i class="bi bi-arrow-right ms-1"></i></a>
                    </div>
                    <div class="set-row">
                        <div>
                            <div class="set-title">QR รับชำระ (PromptPay)</div>
                            <div class="set-desc">ตั้งค่า QR ที่แสดงบนหน้า POS และเอกสาร ให้ลูกค้าสแกนจ่าย</div>
                        </div>
                        <a href="{{ route('bplus.qr-payments') }}" class="btn btn-light border text-nowrap">ตั้งค่า QR <i class="bi bi-arrow-right ms-1"></i></a>
                    </div>
                </div>
            </div>

            {{-- บันทึกบัญชี --}}
            <div x-show="tab === 'accounting'" x-cloak>
                <div class="set-card">
                    <div class="set-row">
                        <div>
                            <div class="set-title">ผังบัญชี</div>
                            <div class="set-desc">บัญชีที่ระบบใช้ลงรายการอัตโนมัติ (ขาย ซื้อ ลูกหนี้ เจ้าหนี้ ภาษี ต้นทุนขาย ฯลฯ)</div>
                        </div>
                        <a href="{{ route('chart-of-accounts.index') }}" class="btn btn-light border text-nowrap">เปิดผังบัญชี <i class="bi bi-arrow-right ms-1"></i></a>
                    </div>
                    <div class="set-row">
                        <div>
                            <div class="set-title">ทะเบียนทรัพย์สิน / ค่าเสื่อม</div>
                            <div class="set-desc">ทรัพย์สินถาวรและการคิดค่าเสื่อมราคาแบบเส้นตรงรายเดือน</div>
                        </div>
                        <a href="{{ route('fixed-assets.index') }}" class="btn btn-light border text-nowrap">เปิดทะเบียน <i class="bi bi-arrow-right ms-1"></i></a>
                    </div>
                    <div class="set-row">
                        <div>
                            <div class="set-title">งบการเงิน</div>
                            <div class="set-desc">งบทดลอง งบกำไรขาดทุน งบแสดงฐานะการเงิน จากรายการบัญชีอัตโนมัติ</div>
                        </div>
                        <a href="{{ route('financial-statements.index') }}" class="btn btn-light border text-nowrap">เปิดงบการเงิน <i class="bi bi-arrow-right ms-1"></i></a>
                    </div>
                </div>
            </div>

            <div x-show="tab === 'pos-download'" x-cloak>
                <div class="set-card pos-download-card">
                    <div class="pos-download-mark"><i class="bi bi-windows"></i></div>
                    <div class="pos-download-copy">
                        <div class="set-title">JET POS สำหรับเครื่องแคชเชียร์</div>
                        <div class="set-desc">โปรแกรมติดตั้ง Windows รุ่น 0.2.1 · Vue + Tauri · เก็บข้อมูล Local SQLite และ Sync ขึ้น Host</div>
                    </div>
                    <a href="{{ route('pos.download') }}" class="btn btn-primary btn-lg pos-download-btn">
                        <i class="bi bi-download me-1"></i> ดาวน์โหลด/อัปเดต POS
                    </a>
                </div>

                @if(session('pos_token'))
                    <div class="set-card pos-token-result">
                        <div>
                            <div class="set-title">Token ใหม่ — {{ session('pos_device_name') }}</div>
                            <div class="set-desc">Token แสดงครั้งเดียว คัดลอกไปวางในหน้าตั้งค่าของ Vue POS ได้เลย</div>
                        </div>
                        <div class="pos-token-copy-row">
                            <input id="new-pos-token" class="form-control font-monospace" readonly value="{{ session('pos_token') }}">
                            <button type="button" class="btn btn-success text-nowrap" @click="copyPosToken(document.getElementById('new-pos-token').value).then(ok => copied = ok)">
                                <i class="bi bi-clipboard-check me-1"></i><span x-text="copied ? 'คัดลอกแล้ว' : 'คัดลอก Token'"></span>
                            </button>
                        </div>
                    </div>
                @endif

                <div class="set-card">
                    <div class="set-title pt-2">สร้าง Token สำหรับเครื่องแคชเชียร์</div>
                    <div class="set-desc mb-3">เลือกผู้ใช้งานประจำเครื่องแล้วกดสร้าง ระบบจะแสดง Token พร้อมปุ่มคัดลอกทันที</div>
                    <div class="row g-3 align-items-end pb-3">
                        <div class="col-md-4">
                            <label class="form-label">ผู้ใช้งาน/แคชเชียร์</label>
                            <select name="pos_user_id" class="form-select">
                                <option value="">เลือกผู้ใช้งาน</option>
                                @foreach($posUsers as $posUser)
                                    <option value="{{ $posUser->id }}">{{ $posUser->name }} ({{ $posUser->username }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">ชื่อเครื่อง</label>
                            <input name="pos_device_name" class="form-control" placeholder="เช่น สาขา 0001 เครื่อง 1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">รหัสเครื่อง POS</label>
                            <input name="pos_terminal_code" class="form-control" placeholder="เช่น POS-0001-01">
                        </div>
                        <div class="col-md-2 d-grid">
                            <button type="submit" formaction="{{ route('settings.pos-token.issue') }}" formnovalidate class="btn btn-primary">
                                <i class="bi bi-key me-1"></i> สร้าง Token
                            </button>
                        </div>
                    </div>
                </div>

                <div class="set-card">
                    <div class="set-title pt-2 mb-2">เครื่องที่ออก Token แล้ว</div>
                    <div class="table-responsive pb-2">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>ชื่อเครื่อง</th><th>ผู้ใช้</th><th>สาขา</th><th>รหัสเครื่อง</th><th>ใช้งานล่าสุด</th><th>สถานะ</th><th class="text-end">Token</th></tr></thead>
                            <tbody>
                            @forelse($posDevices as $device)
                                <tr>
                                    <td class="fw-semibold">{{ $device->name }}</td>
                                    <td>{{ $device->user?->name ?? $device->user?->username ?? '-' }}</td>
                                    <td>{{ $device->branch?->name_th ?? '-' }}</td>
                                    <td><code>{{ $device->terminal_code ?: '-' }}</code></td>
                                    <td>{{ $device->last_seen_at?->diffForHumans() ?? 'ยังไม่เคย' }}</td>
                                    <td><span class="badge {{ $device->isActive() ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $device->isActive() ? 'ใช้งาน' : 'เพิกถอน' }}</span></td>
                                    <td class="text-end">
                                        @if($device->token_encrypted)
                                            <button type="button" class="btn btn-sm btn-outline-success" data-token="{{ $device->token_encrypted }}" onclick="copyPosToken(this.dataset.token).then(ok => { if (ok) this.innerHTML='<i class=&quot;bi bi-check-lg&quot;></i> คัดลอกแล้ว'; })">
                                                <i class="bi bi-clipboard"></i> คัดลอก
                                            </button>
                                        @else
                                            <button type="submit" name="pos_device_id" value="{{ $device->id }}" formaction="{{ route('settings.pos-token.rotate') }}" formnovalidate class="btn btn-sm btn-outline-primary" onclick="return confirm('ออก Token ใหม่ให้เครื่องนี้? Token เดิมจะใช้งานไม่ได้')">
                                                <i class="bi bi-arrow-repeat"></i> ออกใหม่ + คัดลอก
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-3">ยังไม่มี Token อุปกรณ์</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
<script>
async function copyPosToken(text) {
    try {
        if (window.isSecureContext && navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
            return true;
        }

        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        area.style.pointerEvents = 'none';
        document.body.appendChild(area);
        area.focus();
        area.select();
        area.setSelectionRange(0, area.value.length);
        const copied = document.execCommand('copy');
        area.remove();
        if (! copied) throw new Error('copy command failed');
        return true;
    } catch (error) {
        window.prompt('คัดลอก Token จากช่องนี้ (Ctrl+C)', text);
        return false;
    }
}
</script>
@endsection

@push('head')
<style>
    [x-cloak] { display: none !important; }
    .set-shell { display: grid; grid-template-columns: 210px minmax(0, 1fr); gap: 14px; align-items: start; }
    .set-nav { background: #fff; border: 1px solid var(--erp-border); border-radius: 9px; padding: 10px 8px; position: sticky; top: 12px; }
    .set-nav-group { display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 900; color: #29465b; padding: 3px 7px 5px; }
    .set-nav-group i { color: var(--fa-blue); }
    .set-nav-link {
        display: block; width: 100%; text-align: left; border: 0; background: none;
        padding: 7px 8px 7px 26px; border-radius: 7px; font-family: inherit;
        color: #52708a; font-size: 12px; line-height: 1.25; font-weight: 700; cursor: pointer;
    }
    .set-nav-link:hover { background: #eef7fd; color: var(--fa-blue-deep); }
    .set-nav-link.active { background: #e3f3fc; color: var(--fa-blue-deep); box-shadow: inset 3px 0 0 var(--fa-blue); }

    .set-card { background: #fff; border: 1px solid var(--erp-border); border-radius: 12px; padding: 8px 22px; margin-bottom: 14px; }
    .set-card > .set-title, .set-card > .set-desc { padding-top: 12px; }
    .set-row { display: flex; justify-content: space-between; align-items: center; gap: 20px; padding: 16px 0; }
    .set-row + .set-row { border-top: 1px solid #f0f6fb; }
    .set-title { font-weight: 800; color: #29465b; font-size: 14.5px; }
    .set-desc { color: #7d97ac; font-size: 12.5px; margin-top: 3px; line-height: 1.55; max-width: 560px; }

    .logo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px; }
    .logo-option { border: 2px solid #e3ecf3; border-radius: 12px; padding: 10px; cursor: pointer; background: #fff; text-align: center; transition: all .12s; margin: 0; }
    .logo-option:hover { border-color: #94b8d0; }
    .logo-option.active { border-color: var(--fa-blue); background: #f0f9ff; box-shadow: 0 6px 16px rgba(26,155,220,.15); }
    .logo-thumb { height: 84px; display: flex; align-items: center; justify-content: center; overflow: hidden; border-radius: 8px; background: #f6fafd; }
    .logo-thumb img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .logo-thumb-text { font-size: 24px; font-weight: 900; color: #29465b; }
    .logo-thumb-text span { color: var(--fa-green); }
    .logo-caption { font-size: 11px; color: #7d97ac; margin-top: 6px; word-break: break-all; }
    .pos-download-card { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: 18px; padding: 24px; border-color: #bae6fd; background: #f0f9ff; }
    .pos-download-mark { width: 64px; height: 64px; display: grid; place-items: center; border-radius: 16px; background: #0284c7; color: #fff; font-size: 32px; }
    .pos-download-copy .set-title { color: #0c4a6e; font-size: 18px; }
    .pos-download-copy .set-desc { max-width: none; }
    .pos-download-btn { min-width: 230px; font-weight: 800; }
    .pos-token-result { border-color: #86efac; background: #f0fdf4; padding-top: 18px; padding-bottom: 18px; }
    .pos-token-copy-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 10px; margin-top: 12px; }
    .menu-order-card { max-width:620px; padding:16px; }
    .menu-order-row { display:flex; align-items:center; gap:10px; min-height:40px; padding:5px 8px; border:1px solid #dce7ef; border-radius:7px; margin-top:6px; background:#f8fbfd; font-size:12px; }
    .menu-order-index { width:24px; height:24px; display:grid; place-items:center; border-radius:5px; background:#e0f2fe; color:#0369a1; font-weight:800; }
    .menu-order-row button { width:28px; height:28px; border:1px solid #cbd5e1; border-radius:5px; background:#fff; color:#334155; }
    .menu-order-row button:disabled { opacity:.3; }
    .theme-settings-card { max-width:720px; padding:16px; }
    .theme-choice-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
    .theme-choice { display:grid; grid-template-columns:auto 1fr; align-items:center; gap:9px; padding:9px; border:2px solid #dce7ef; border-radius:8px; cursor:pointer; background:#fff; }
    .theme-choice.active { border-color:var(--fa-blue); background:#f8fcff; }
    .theme-choice input { position:absolute; opacity:0; }
    .theme-choice strong { font-size:12px; }
    .theme-preview { grid-row:span 2; width:70px; height:42px; display:flex; overflow:hidden; border:1px solid #d1d5db; border-radius:5px; background:var(--preview-bg); }
    .theme-preview i { width:17px; background:linear-gradient(var(--preview-primary),var(--preview-deep)); }
    .theme-preview b { align-self:flex-start; width:40px; height:7px; margin:8px 6px; border-radius:3px; background:var(--preview-primary); }

    @media (max-width: 991.98px) {
        .set-shell { grid-template-columns: 1fr; }
        .set-nav { position: static; }
        .set-row { flex-direction: column; align-items: flex-start; }
        .pos-download-card { grid-template-columns: auto minmax(0, 1fr); }
        .pos-download-btn { grid-column: 1 / -1; width: 100%; }
        .pos-token-copy-row { grid-template-columns: 1fr; }
    }
</style>
@endpush
