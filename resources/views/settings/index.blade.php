@extends('layout')
@section('title', 'การตั้งค่า - PopCentral')
@section('page-title', 'การตั้งค่า')
@section('page-subtitle', 'ตั้งค่าเอกสาร ข้อมูลกิจการ ภาษี และการรับชำระ')
@section('content')

<form id="layout-form" method="post" action="{{ route('settings.layout.update') }}" class="d-none">
    @csrf
</form>

@if(session('success'))
    <div class="alert alert-success d-flex align-items-center gap-2" role="alert">
        <i class="bi bi-check-circle-fill"></i><span>{{ session('success') }}</span>
    </div>
@endif
@if($errors->any())
    <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i><span>{{ $errors->first() }}</span>
    </div>
@endif

<form method="post" action="{{ route('settings.update') }}" enctype="multipart/form-data"
      x-data="{ tab: @js(session('pos_token') || $errors->has('pos_version') || $errors->has('pos_installer') ? 'pos-download' : 'func'), choice: @js($currentLogo ?? '__none__'), theme: @js($erpTheme), layout: @js($erpLayout), copied: false, menuOrder: @js($menuOrder), moveMenu(i, d) { const n=i+d; if(n<0 || n>=this.menuOrder.length) return; const a=[...this.menuOrder]; [a[i],a[n]]=[a[n],a[i]]; this.menuOrder=a; } }">
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
                    <a href="{{ route('settings.module-controls') }}" class="set-nav-link text-decoration-none">ศูนย์ควบคุมโมดูล</a>
                    <a href="{{ route('settings.pos-designer') }}" class="set-nav-link text-decoration-none">POS Designer</a>
            <button type="button" class="set-nav-link" :class="tab === 'menu' && 'active'" @click="tab = 'menu'">จัดลำดับเมนู</button>
            <button type="button" class="set-nav-link" :class="tab === 'theme' && 'active'" @click="tab = 'theme'">ปรับธีม</button>

            <div class="set-nav-group mt-3"><i class="bi bi-sliders"></i> ตั้งค่าด้านบัญชี</div>
            <button type="button" class="set-nav-link" :class="tab === 'payment' && 'active'" @click="tab = 'payment'">ข้อมูลการรับชำระ</button>
            <button type="button" class="set-nav-link" :class="tab === 'accounting' && 'active'" @click="tab = 'accounting'">บันทึกบัญชี</button>

            <div class="set-nav-group mt-3"><i class="bi bi-pc-display"></i> โปรแกรมหน้าร้าน</div>
            <a href="{{ route('settings.receipt-template.edit') }}" class="set-nav-link text-decoration-none">ออกแบบใบเสร็จ POS</a>
            <button type="button" class="set-nav-link" :class="tab === 'pos-download' && 'active'" @click="tab = 'pos-download'">PopCentral POS (Python)</button>
        </div>

        {{-- เนื้อหาขวา --}}
        <div class="set-main">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h2 class="h4 fw-bold mb-0" style="color:var(--erp-primary-dark)"
                    x-text="{ func: 'ฟังก์ชั่นเอกสาร', numbering: 'เลขรันเอกสาร', logo: 'โลโก้และตราประทับ', note: 'หมายเหตุเอกสาร', company: 'ข้อมูลกิจการ', menu: 'จัดลำดับเมนูหลัก', theme: 'ปรับธีมระบบ', payment: 'ข้อมูลการรับชำระ', accounting: 'บันทึกบัญชี', 'pos-download': 'ตั้งค่า PopCentral POS' }[tab]"></h2>
                <button x-show="tab !== 'pos-download' && tab !== 'theme'" type="submit" class="btn btn-success px-4"><i class="bi bi-check-lg me-1"></i>บันทึกข้อมูล</button>
                <button x-show="tab === 'theme'" type="submit" form="layout-form" class="btn btn-success px-4"><i class="bi bi-check-lg me-1"></i>บันทึก Layout</button>
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
                            'ocean' => ['ฟ้า JET', '#1a9bdc', 'var(--erp-primary)', 'var(--erp-primary-soft)'],
                            'navy' => ['กรมท่า', '#315b86', '#244768', 'var(--erp-surface-2)'],
                            'emerald' => ['เขียวมรกต', '#23966c', '#187653', '#eef7f3'],
                            'slate' => ['เทาสเลต', 'var(--erp-muted)', 'var(--erp-text)', '#f1f3f5'],
                            'clear' => ['อ่านง่าย', '#2563eb', 'var(--erp-info)', '#f4f6f8'],
                        ] as $key => [$label, $primary, $deep, $bg])
                            <label class="theme-choice" :class="theme === '{{ $key }}' && 'active'">
                                <input type="radio" name="erp_theme" value="{{ $key }}" x-model="theme">
                                <span class="theme-preview" style="--preview-primary:{{ $primary }};--preview-deep:{{ $deep }};--preview-bg:{{ $bg }}"><i></i><b></b></span>
                                <strong>{{ $label }}</strong>
                            </label>
                        @endforeach
                    </div>
                    <div class="set-title mt-4">รูปแบบหน้าหลังบ้าน</div>
                    <div class="set-desc mb-3">เปลี่ยนเฉพาะ Layout และสี ไม่กระทบข้อมูลขาย สต๊อก บัญชี หรือสิทธิ์</div>
                    <div class="theme-choice-grid">
                        <label class="theme-choice" :class="layout === 'classic' && 'active'"><input form="layout-form" type="radio" name="erp_layout" value="classic" x-model="layout"><span class="theme-preview" style="--preview-primary:#1585c0;--preview-deep:#0f4c75;--preview-bg:#eef4f9"><i></i><b></b></span><strong>แบบเดิม PopCentral</strong></label>
                        <label class="theme-choice" :class="layout === 'odoo' && 'active'"><input form="layout-form" type="radio" name="erp_layout" value="odoo" x-model="layout"><span class="theme-preview" style="--preview-primary:#714b67;--preview-deep:#493047;--preview-bg:#f7f7f7"><i></i><b></b></span><strong>แบบ Odoo</strong></label>
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
                        <div class="set-title">PopCentral POS สำหรับเครื่องแคชเชียร์</div>
                        <div class="set-desc">Python + PySide6 · Local SQLite · แอป POS หลักสำหรับเครื่องแคชเชียร์ · ต้องผ่านการทดสอบ Windows ก่อนเปิดขายจริง</div>
                    </div>
                    @if($pythonPosInstaller)
                        <a href="{{ route('python-pos.download') }}" class="btn btn-primary btn-lg pos-download-btn">
                            <i class="bi bi-download me-1"></i> ดาวน์โหลด PopCentral POS
                        </a>
                    @else
                        <button type="button" class="btn btn-secondary btn-lg pos-download-btn" disabled>
                            <i class="bi bi-hourglass-split me-1"></i> กำลังสร้างไฟล์ Windows
                        </button>
                    @endif
                </div>

                @if($pythonPosInstaller)
                    <div class="alert alert-success py-2 mb-3">
                        <i class="bi bi-shield-check me-1"></i>พร้อมทดสอบ: <code>{{ $pythonPosInstaller['filename'] }}</code>
                        · {{ number_format($pythonPosInstaller['size_bytes'] / 1048576, 1) }} MB
                    </div>
                @endif

                {{-- เก็บกลไก Vue/Tauri รุ่นเดิมไว้ใน source เพื่อ rollback ได้ แต่ไม่แสดงใน ERP แล้ว. --}}
                @if(false)
                <div class="set-card">
                    <div class="set-title pt-2">เผยแพร่โปรแกรม POS รุ่นใหม่</div>
                    <div class="set-desc mb-3">เมื่อ GitHub Actions สร้างรุ่นใหม่สำเร็จ ระบบจะเผยแพร่ไฟล์ที่เซ็นแล้วอัตโนมัติ เครื่องแคชเชียร์จะตรวจพบเมื่อเปิดโปรแกรม ฟอร์มนี้เก็บไว้ใช้เฉพาะกรณีฉุกเฉิน</div>
                    @if($posRelease)
                        <div class="alert alert-success py-2">
                            รุ่นปัจจุบัน <strong>{{ $posRelease['version'] }}</strong> · เผยแพร่ {{ \Illuminate\Support\Carbon::parse($posRelease['pub_date'])->diffForHumans() }}
                            · SHA-256 <code>{{ substr($posRelease['sha256'] ?? '', 0, 16) }}...</code>
                        </div>
                    @endif
                    <div class="row g-3 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label">เวอร์ชัน</label>
                            <input name="pos_version" class="form-control" placeholder="1.0.1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ไฟล์อัปเดต Windows</label>
                            <input name="pos_installer" type="file" class="form-control" accept=".exe,.msi,.zip">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ลายเซ็น Tauri</label>
                            <input name="pos_signature" class="form-control font-monospace" placeholder="ข้อความจากไฟล์ .sig">
                        </div>
                        <div class="col-md-2 d-grid">
                            <button type="submit" formaction="{{ route('settings.pos-release.publish') }}" formnovalidate class="btn btn-success">
                                <i class="bi bi-cloud-arrow-up me-1"></i> เผยแพร่
                            </button>
                        </div>
                        <div class="col-md-10">
                            <label class="form-label">รายละเอียดรุ่น</label>
                            <input name="pos_release_notes" class="form-control" placeholder="สรุปสิ่งที่แก้ไขในรุ่นนี้">
                        </div>
                        <div class="col-md-2">
                            <div class="form-check pb-2"><input name="pos_mandatory" value="1" type="checkbox" class="form-check-input" id="pos-mandatory"><label for="pos-mandatory" class="form-check-label">บังคับอัปเดต</label></div>
                        </div>
                    </div>
                </div>
                @endif

                <div class="set-card">
                    <div class="set-title pt-2">วิธีผูกเครื่อง POS หลังติดตั้ง</div>
                    <div class="set-desc mb-3">
                        เครื่อง POS ไม่ได้ใช้รหัสผ่าน ERP จากหน้า “ผู้ใช้และสิทธิ์” โดยตรง ต้องผูกเครื่องด้วย Device token ก่อน แล้วให้แคชเชียร์ยืนยันตัวด้วย PIN POS
                    </div>
                    <ol class="mb-3 ps-3 small text-muted">
                        <li>เลือกสาขาที่ติดตั้ง แล้วกด <strong>สร้างอัตโนมัติ</strong> เพื่อออก Device token ให้เครื่องนั้น</li>
                        <li>คัดลอก Token ที่แสดง แล้วไปที่โปรแกรม PopCentral POS → <strong>ตั้งค่าเครื่องนี้</strong></li>
                        <li>กรอกที่อยู่ ERP เป็น <code>{{ request()->getSchemeAndHttpHost() }}</code> และวาง Device token</li>
                        <li>เปิดโปรแกรม POS ใหม่หนึ่งครั้งเพื่อ sync สินค้า ราคา สาขา เครื่อง และรายชื่อแคชเชียร์</li>
                        <li>แคชเชียร์เข้า POS ด้วย <strong>รหัสพนักงาน + PIN POS</strong>; ถ้าเป็น PIN ชั่วคราว ระบบจะบังคับตั้ง PIN ใหม่ก่อนขาย</li>
                    </ol>
                    <div class="alert alert-warning py-2 mb-0 small">
                        รีเซ็ตรหัสผ่านในหน้า “ผู้ใช้และสิทธิ์” ใช้กับการเข้าเว็บ ERP เท่านั้น ถ้าต้องการเข้า POS ให้ใช้ปุ่ม <strong>PIN POS</strong> ของผู้ใช้ หรือออก Device token ใหม่ให้เครื่อง
                    </div>
                </div>

                @if(session('pos_token'))
                    <div class="set-card pos-token-result">
                        <div>
                            <div class="set-title">Token ใหม่ — {{ session('pos_device_name') }}</div>
                            <div class="set-desc">Token แสดงครั้งเดียว คัดลอกไปวางในหน้าตั้งค่าของ PopCentral POS ได้เลย</div>
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
                    <div class="set-title pt-2">เพิ่มเครื่อง POS และสร้าง Token อัตโนมัติ</div>
                    <div class="set-desc mb-3">เลือกสาขาเพียงอย่างเดียว ระบบหาแคชเชียร์ที่มีสิทธิ์ขายของสาขานั้น จ่ายรหัสเครื่อง และสร้าง Token ให้ทันที แคชเชียร์ยังต้องยืนยันตัวเองก่อนเปิดกะ/ขาย</div>
                    <div class="row g-3 align-items-end pb-3">
                        <div class="col-md-5">
                            <label class="form-label">สาขาที่ติดตั้ง <span class="text-danger">*</span></label>
                            <select name="pos_branch_id" class="form-select" required>
                                <option value="">เลือกสาขา</option>
                                @foreach($posBranches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">ชื่อเครื่อง (ไม่บังคับ)</label>
                            <input name="pos_device_name" class="form-control" placeholder="เช่น แคชเชียร์ 1 — เว้นว่างให้ระบบตั้งชื่อเอง">
                        </div>
                        <div class="col-md-2 d-grid">
                            <button type="submit" formaction="{{ route('settings.pos-token.issue') }}" formnovalidate class="btn btn-primary">
                                <i class="bi bi-magic me-1"></i> สร้างอัตโนมัติ
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

                <div class="set-card">
                    <div class="set-title pt-2">โปรไฟล์อุปกรณ์หน้าร้าน</div>
                    <div class="set-desc mb-3">กำหนดแยกแต่ละเครื่อง แอป POS จะรับค่าชุดนี้จากเซิร์ฟเวอร์เมื่อต่อออนไลน์</div>
                    @forelse($posTerminals as $terminal)
                        @php
                            $hardware = array_merge([
                                'printer_driver' => 'browser',
                                'printer_name' => '',
                                'printer_address' => '',
                                'paper_width' => '80mm',
                                'scanner_mode' => 'keyboard',
                                'scale_mode' => 'keyboard',
                                'customer_display' => 'none',
                                'cash_drawer_enabled' => false,
                                'auto_print' => false,
                                'print_copies' => 1,
                            ], $terminal->hardware_profile ?? []);
                        @endphp
                        <div class="border rounded-2 p-3 mb-3">
                            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                                <div><strong>{{ $terminal->code }} · {{ $terminal->name }}</strong><div class="small text-muted">{{ $terminal->branch?->code }} {{ $terminal->branch?->name_th }}</div></div>
                                <button type="submit" name="pos_terminal_id" value="{{ $terminal->id }}" formaction="{{ route('settings.pos-terminal.hardware') }}" formnovalidate class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>บันทึกเครื่องนี้</button>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-2"><label class="form-label small">ไดรเวอร์เครื่องพิมพ์</label><select name="hardware[{{ $terminal->id }}][printer_driver]" class="form-select form-select-sm"><option value="browser" @selected($hardware['printer_driver']==='browser')>พิมพ์ผ่านระบบ</option><option value="windows" @selected($hardware['printer_driver']==='windows')>Windows Printer</option><option value="escpos_usb" @selected($hardware['printer_driver']==='escpos_usb')>ESC/POS USB</option><option value="escpos_network" @selected($hardware['printer_driver']==='escpos_network')>ESC/POS Network</option></select></div>
                                <div class="col-md-2"><label class="form-label small">ชื่อเครื่องพิมพ์</label><input name="hardware[{{ $terminal->id }}][printer_name]" value="{{ $hardware['printer_name'] }}" class="form-control form-control-sm" placeholder="เช่น POS-80"></div>
                                <div class="col-md-2"><label class="form-label small">IP / Port / USB</label><input name="hardware[{{ $terminal->id }}][printer_address]" value="{{ $hardware['printer_address'] }}" class="form-control form-control-sm" placeholder="192.168.1.20:9100"></div>
                                <div class="col-md-1"><label class="form-label small">กระดาษ</label><select name="hardware[{{ $terminal->id }}][paper_width]" class="form-select form-select-sm"><option value="80mm" @selected($hardware['paper_width']==='80mm')>80 มม.</option><option value="58mm" @selected($hardware['paper_width']==='58mm')>58 มม.</option></select></div>
                                <div class="col-md-1"><label class="form-label small">เครื่องสแกน</label><select name="hardware[{{ $terminal->id }}][scanner_mode]" class="form-select form-select-sm"><option value="keyboard" @selected($hardware['scanner_mode']==='keyboard')>Keyboard</option><option value="serial" @selected($hardware['scanner_mode']==='serial')>Serial</option></select></div>
                                <div class="col-md-1"><label class="form-label small">เครื่องชั่ง</label><select name="hardware[{{ $terminal->id }}][scale_mode]" class="form-select form-select-sm"><option value="none" @selected($hardware['scale_mode']==='none')>ไม่ใช้</option><option value="keyboard" @selected($hardware['scale_mode']==='keyboard')>Barcode</option><option value="serial" @selected($hardware['scale_mode']==='serial')>Serial</option></select></div>
                                <div class="col-md-1"><label class="form-label small">จอลูกค้า</label><select name="hardware[{{ $terminal->id }}][customer_display]" class="form-select form-select-sm"><option value="none" @selected($hardware['customer_display']==='none')>ไม่ใช้</option><option value="browser" @selected($hardware['customer_display']==='browser')>หน้าจอ 2</option><option value="serial" @selected($hardware['customer_display']==='serial')>Serial</option><option value="network" @selected($hardware['customer_display']==='network')>Network</option></select></div>
                                <div class="col-md-1"><label class="form-label small">จำนวนสำเนา</label><input type="number" min="1" max="3" name="hardware[{{ $terminal->id }}][print_copies]" value="{{ $hardware['print_copies'] }}" class="form-control form-control-sm"></div>
                                <div class="col-md-1 d-flex flex-column justify-content-end gap-1">
                                    <label class="form-check small mb-0"><input type="checkbox" name="hardware[{{ $terminal->id }}][cash_drawer_enabled]" value="1" @checked($hardware['cash_drawer_enabled']) class="form-check-input"> ลิ้นชักเงิน</label>
                                    <label class="form-check small mb-0"><input type="checkbox" name="hardware[{{ $terminal->id }}][auto_print]" value="1" @checked($hardware['auto_print']) class="form-check-input"> พิมพ์อัตโนมัติ</label>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-muted small py-3">ยังไม่มีเครื่อง POS ระบบจะสร้างเครื่องเว็บเมื่อเปิดกะครั้งแรก</div>
                    @endforelse
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
    /* เมนูตั้งค่า — ใช้ภาษาเดียวกับเมนูข้างของระบบ (token --erp-* และสถานะฟ้า)
       ไม่ใส่ตราสีประจำหัวข้อ เพราะที่นี่คือ 12 หัวข้อในโมดูลเดียวที่อ่านเรียงลงมา
       สีจะไปแข่งกับเมนูซ้ายโดยไม่ช่วยให้หาอะไรเจอเร็วขึ้น */
    .set-nav { background: var(--erp-surface); border: 1px solid var(--erp-border);
        border-radius: 10px; padding: 6px; position: sticky; top: 12px;
        box-shadow: 0 1px 3px rgba(29, 59, 82, .05); }
    /* หัวกลุ่มเป็นป้ายกำกับ ไม่ใช่หัวข้อ — เดิมหนา 900 สีเข้ม แย่งสายตาจากรายการ */
    .set-nav-group { display: flex; align-items: center; gap: 7px;
        font-size: 10.5px; font-weight: 700; letter-spacing: .7px; text-transform: uppercase;
        color: var(--erp-muted); padding: 13px 10px 6px; }
    .set-nav-group i { font-size: 12px; opacity: .6; }
    .set-nav-link {
        display: block; width: 100%; text-align: left; border: 0; background: none;
        padding: 7px 10px 7px 29px; border-radius: 8px; font-family: inherit;
        color: var(--erp-text); font-size: 13px; line-height: 1.35; font-weight: 400;
        cursor: pointer; position: relative; margin-bottom: 1px;
    }
    .set-nav-link:hover { background: var(--erp-surface-2, var(--erp-surface-2)); }
    .set-nav-link.active { background: var(--erp-primary-soft); color: var(--erp-primary-dark); font-weight: 600; }
    /* แท่ง accent มนสั้น แทนเส้นเต็มความสูง ให้เข้าชุดกับเมนูซ้าย */
    .set-nav-link.active::before { content: ""; position: absolute; left: 8px; top: 50%;
        transform: translateY(-50%); width: 3px; height: 16px; border-radius: 2px;
        background: var(--erp-primary); }
    .set-nav-link:focus-visible { outline: 2px solid var(--erp-primary); outline-offset: -2px; }

    .set-card { background: var(--erp-surface); border: 1px solid var(--erp-border); border-radius: 10px;
        padding: 8px 22px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(29, 59, 82, .05); }
    .set-card > .set-title, .set-card > .set-desc { padding-top: 12px; }
    .set-row { display: flex; justify-content: space-between; align-items: center; gap: 20px; padding: 16px 0; }
    .set-row + .set-row { border-top: 1px solid var(--erp-primary-soft); }
    .set-title { font-weight: 800; color: var(--erp-primary-dark); font-size: 14.5px; }
    .set-desc { color: #7d97ac; font-size: 12.5px; margin-top: 3px; line-height: 1.55; max-width: 560px; }

    .logo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px; }
    .logo-option { border: 2px solid var(--erp-border); border-radius: 12px; padding: 10px; cursor: pointer; background: #fff; text-align: center; transition: all .12s; margin: 0; }
    .logo-option:hover { border-color: #94b8d0; }
    .logo-option.active { border-color: var(--erp-primary); background: var(--erp-primary-soft); box-shadow: 0 6px 16px rgba(21,133,192,.15); }
    .logo-thumb { height: 84px; display: flex; align-items: center; justify-content: center; overflow: hidden; border-radius: 8px; background: var(--erp-surface-2); }
    .logo-thumb img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .logo-thumb-text { font-size: 24px; font-weight: 900; color: var(--erp-primary-dark); }
    .logo-thumb-text span { color: var(--erp-success-ink); }
    .logo-caption { font-size: 11px; color: #7d97ac; margin-top: 6px; word-break: break-all; }
    .pos-download-card { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: 18px; padding: 24px; border-color: var(--erp-primary-soft); background: var(--erp-primary-soft); }
    .pos-download-mark { width: 64px; height: 64px; display: grid; place-items: center; border-radius: 16px; background: var(--erp-primary); color: #fff; font-size: 32px; }
    .pos-download-copy .set-title { color: var(--erp-primary-dark); font-size: 18px; }
    .pos-download-copy .set-desc { max-width: none; }
    .pos-download-btn { min-width: 230px; font-weight: 800; }
    .pos-token-result { border-color: #86efac; background: var(--erp-success-soft); padding-top: 18px; padding-bottom: 18px; }
    .pos-token-copy-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 10px; margin-top: 12px; }
    .menu-order-card { max-width:620px; padding:16px; }
    .menu-order-row { display:flex; align-items:center; gap:10px; min-height:40px; padding:5px 8px; border:1px solid var(--erp-border); border-radius:7px; margin-top:6px; background:var(--erp-surface-2); font-size:12px; }
    .menu-order-index { width:24px; height:24px; display:grid; place-items:center; border-radius:5px; background:var(--erp-primary-soft); color:var(--erp-primary-dark); font-weight:800; }
    .menu-order-row button { width:28px; height:28px; border:1px solid var(--erp-border); border-radius:5px; background:#fff; color:var(--erp-text); }
    .menu-order-row button:disabled { opacity:.3; }
    .theme-settings-card { max-width:720px; padding:16px; }
    .theme-choice-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
    .theme-choice { display:grid; grid-template-columns:auto 1fr; align-items:center; gap:9px; padding:9px; border:2px solid #dce7ef; border-radius:8px; cursor:pointer; background:#fff; }
    .theme-choice.active { border-color:var(--erp-primary); background:var(--erp-primary-soft); }
    .theme-choice input { position:absolute; opacity:0; }
    .theme-choice strong { font-size:12px; }
    .theme-preview { grid-row:span 2; width:70px; height:42px; display:flex; overflow:hidden; border:1px solid #d1d5db; border-radius:5px; background:var(--preview-bg); }
    .theme-preview i { width:17px; background:linear-gradient(var(--preview-primary),var(--preview-deep)); }
    .theme-preview b { align-self:flex-start; width:40px; height:7px; margin:8px 6px; border-radius:3px; background:var(--preview-primary); }

    /* Odoo layout: a quiet settings workspace with a clear secondary navigation. */
    html[data-layout="odoo"] .set-shell { gap: 14px; align-items: start; }
    html[data-layout="odoo"] .set-nav {
        background: var(--erp-surface);
        border: 1px solid var(--erp-border);
        border-radius: 6px;
        padding: 8px;
        box-shadow: 0 1px 2px rgba(29, 59, 82, .06);
    }
    html[data-layout="odoo"] .set-nav-group {
        color: var(--erp-primary-dark);
        background: var(--erp-surface-2, var(--erp-surface-2));
        border: 1px solid var(--erp-border);
        border-radius: 4px;
        font-size: 11px;
        letter-spacing: 0;
        padding: 9px 10px;
        margin: 8px 0 4px;
        font-weight: 700;
    }
    html[data-layout="odoo"] .set-nav-group:first-child { margin-top: 0; }
    html[data-layout="odoo"] .set-nav-group i {
        color: var(--erp-primary);
        opacity: 1;
    }
    html[data-layout="odoo"] .set-nav-link {
        color: var(--erp-text);
        border-radius: 6px;
        padding: 8px 10px 8px 26px;
        font-size: 13px;
    }
    html[data-layout="odoo"] .set-nav-link:hover { background: var(--erp-primary-soft); }
    html[data-layout="odoo"] .set-nav-link.active {
        background: var(--erp-primary-soft);
        color: var(--erp-primary-dark);
        font-weight: 600;
    }
    html[data-layout="odoo"] .set-main { min-width: 0; }
    html[data-layout="odoo"] .set-main > .d-flex {
        min-height: 42px;
        margin-bottom: 12px !important;
    }
    html[data-layout="odoo"] .set-main > .d-flex h2 {
        color: var(--erp-primary-dark) !important;
        font-size: 20px;
        font-weight: 700 !important;
    }
    html[data-layout="odoo"] .set-card {
        border-radius: 6px;
        padding: 10px 20px;
        box-shadow: 0 1px 2px rgba(29, 59, 82, .06);
    }
    html[data-layout="odoo"] .set-title { color: var(--erp-primary-dark); font-weight: 700; }
    html[data-layout="odoo"] .set-desc { color: var(--erp-muted); }
    html[data-layout="odoo"] .set-row + .set-row { border-top-color: var(--erp-border); }
    html[data-layout="odoo"] .form-control,
    html[data-layout="odoo"] .form-select {
        border-color: var(--erp-border);
        border-radius: 4px;
    }
    html[data-layout="odoo"] .form-control:focus,
    html[data-layout="odoo"] .form-select:focus {
        border-color: var(--erp-primary);
        box-shadow: 0 0 0 2px var(--erp-primary-soft);
    }
    html[data-layout="odoo"] .btn-success { background: var(--erp-primary); border-color: var(--erp-primary); }
    html[data-layout="odoo"] .btn-success:hover { background: var(--erp-primary-dark); border-color: var(--erp-primary-dark); }

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
