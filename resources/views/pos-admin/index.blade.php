@extends('layout')
@section('title', 'จัดการเครื่อง POS - PopCentral')
@section('page-title', 'จัดการเครื่อง POS')
@section('page-subtitle', 'เพิ่มเครื่อง ดูสถานะ sync ออก PIN คนขาย และตั้งค่าฮาร์ดแวร์ ในหน้าเดียว')

@push('head')
<style>
    .pa-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
    .pa-tab { border: 1px solid var(--erp-border); background: var(--erp-surface, #fff); border-radius: 999px; padding: 7px 14px; font-weight: 700; font-size: 13px; color: var(--erp-text-muted, #5b6b78); }
    .pa-tab.active { background: var(--erp-primary); border-color: var(--erp-primary); color: #fff; }
    .pa-tab .badge { margin-left: 4px; }
    .pa-card { background: var(--erp-surface, #fff); border: 1px solid var(--erp-border); border-radius: 12px; padding: 16px; margin-bottom: 14px; }
    .pa-card h2 { font-size: 15px; font-weight: 800; margin: 0 0 10px; }
    .pa-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
    .pa-dot.on { background: #16a34a; } .pa-dot.off { background: #9ca3af; } .pa-dot.warn { background: #f59e0b; }
    .pa-token { border: 2px solid #16a34a; background: #f0fdf4; }
    .pa-token input { font-size: 15px; }
    .pa-step { display: flex; gap: 12px; padding: 10px 0; border-top: 1px solid var(--erp-border); }
    .pa-step:first-child { border-top: 0; }
    .pa-step-no { flex: 0 0 28px; height: 28px; display: grid; place-items: center; border-radius: 50%; background: var(--erp-primary); color: #fff; font-weight: 800; }
    .pa-big-pin { font-size: 34px; letter-spacing: 6px; }
    @media (max-width: 640px) { .pa-card { padding: 12px; } .pa-step { flex-wrap: wrap; } }
</style>
@endpush

@section('content')
@php
    $tab = request('tab', session('pos_token') ? 'devices' : 'overview');
    $issueCount = $issues->count();
    $staleDevices = $devices->filter(fn ($d) => $d['active'] && ! $d['online'])->count();
@endphp
<div x-data="{ tab: @js($tab), copied: false }">

    {{-- ผลลัพธ์ที่แสดงครั้งเดียว: Token / PIN ชั่วคราว อยู่บนสุดเสมอ --}}
    @if(session('pos_token'))
        <div class="pa-card pa-token" data-testid="pos-token-result">
            <h2><i class="bi bi-key-fill me-1 text-success"></i>Token ของ {{ session('pos_device_name') }}</h2>
            <div class="small text-muted mb-2">ไปที่เครื่องร้าน เปิด PopCentral POS → ใส่ ERP <code>{{ request()->getSchemeAndHttpHost() }}</code> → วาง Token นี้ · แสดงครั้งเดียว ห้ามส่งในแชท</div>
            <div class="d-flex gap-2 flex-wrap">
                <input id="new-pos-token" class="form-control font-monospace flex-grow-1" readonly value="{{ session('pos_token') }}" style="min-width:240px">
                <button type="button" class="btn btn-success" @click="copyPosToken(document.getElementById('new-pos-token').value).then(ok => copied = ok)">
                    <i class="bi bi-clipboard-check me-1"></i><span x-text="copied ? 'คัดลอกแล้ว' : 'คัดลอก Token'"></span>
                </button>
            </div>
        </div>
    @endif
    @if(session('cashier_pin_result'))
        <div class="pa-card pa-token" data-testid="cashier-pin-result">
            <h2>PIN ชั่วคราวของ {{ session('cashier_pin_result.code') }} — {{ session('cashier_pin_result.name') }}</h2>
            <div class="pa-big-pin font-monospace fw-bold">{{ session('cashier_pin_result.pin') }}</div>
            <div class="small text-muted">แสดงครั้งเดียว บอกพนักงานโดยตรง · ล็อกอินครั้งแรกเครื่องบังคับเปลี่ยน PIN</div>
        </div>
    @endif

    <div class="pa-tabs">
        <button type="button" class="pa-tab" :class="tab === 'overview' && 'active'" @click="tab = 'overview'">ภาพรวมสาขา</button>
        <button type="button" class="pa-tab" :class="tab === 'devices' && 'active'" @click="tab = 'devices'">เครื่อง & sync
            @if($staleDevices)<span class="badge text-bg-secondary">{{ $staleDevices }} ออฟไลน์</span>@endif
            @if($issueCount)<span class="badge text-bg-danger">{{ $issueCount }}</span>@endif
        </button>
        <button type="button" class="pa-tab" :class="tab === 'cashiers' && 'active'" @click="tab = 'cashiers'">คนขาย & PIN</button>
        <button type="button" class="pa-tab" :class="tab === 'hardware' && 'active'" @click="tab = 'hardware'">ฮาร์ดแวร์</button>
        <button type="button" class="pa-tab" :class="tab === 'install' && 'active'" @click="tab = 'install'">วิธีติดตั้ง</button>
    </div>

    {{-- ภาพรวม: สาขาไหนพร้อม ขาดอะไร กดเพิ่มเครื่องได้เลย --}}
    <div x-show="tab === 'overview'" x-cloak>
        <div class="pa-card" data-testid="pos-readiness">
            <h2>ความพร้อมขายแต่ละสาขา</h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-2">
                    <thead><tr><th>สาขา</th><th>คลัง</th><th>เครื่อง</th><th>คนขายที่มี PIN</th><th>สถานะ</th><th class="text-end"></th></tr></thead>
                    <tbody>
                    @foreach($branches as $row)
                        @php $ready = $row['has_warehouse'] && $row['online_devices'] > 0 && $row['cashiers_with_pin'] > 0; @endphp
                        <tr>
                            <td class="fw-semibold">{{ $row['branch']->code }} {{ $row['branch']->name_th }}</td>
                            <td>{!! $row['has_warehouse'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<span class="text-danger">ยังไม่มี</span>' !!}</td>
                            <td>{{ $row['devices'] }} @if($row['devices'] > $row['online_devices'])<span class="small text-muted">(ยังไม่เคยต่อ {{ $row['devices'] - $row['online_devices'] }})</span>@endif</td>
                            <td>{{ $row['cashiers_with_pin'] }} / {{ $row['cashiers'] }}</td>
                            <td>
                                @if($ready)
                                    <span class="badge text-bg-success">พร้อมขาย</span>
                                @else
                                    <span class="badge text-bg-warning">ยังไม่พร้อม</span>
                                    <div class="small text-muted">{{ collect([
                                        $row['has_warehouse'] ? null : 'ตั้งคลัง',
                                        $row['devices'] === 0 ? 'กดเพิ่มเครื่อง' : ($row['online_devices'] === 0 ? 'วาง Token ที่เครื่องร้าน' : null),
                                        $row['cashiers_with_pin'] === 0 ? 'ออก PIN คนขาย' : null,
                                    ])->filter()->implode(' · ') }}</div>
                                @endif
                            </td>
                            <td class="text-end">
                                @if($row['device_user'] && $row['has_warehouse'])
                                    <form method="post" action="{{ route('settings.pos-token.issue') }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="pos_branch_id" value="{{ $row['branch']->id }}">
                                        <input type="hidden" name="pos_user_id" value="{{ $row['device_user']->id }}">
                                        <button class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-plus-lg me-1"></i>เพิ่มเครื่อง</button>
                                        <div class="small text-muted">ผูกกับ {{ $row['device_user']->posCashierProfile?->code ?? $row['device_user']->username }}</div>
                                    </form>
                                @else
                                    <span class="small text-muted">{{ $row['has_warehouse'] ? 'แคชเชียร์ทุกรหัสมีเครื่องแล้ว — เพิ่มรหัสด้วย erp:setup-branch-pos-staff' : 'ตั้งคลังก่อน' }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="small {{ $pinMode === 'pin' ? 'text-success' : 'text-danger' }}">
                <i class="bi {{ $pinMode === 'pin' ? 'bi-shield-lock-fill' : 'bi-exclamation-triangle-fill' }} me-1"></i>
                {{ $pinMode === 'pin' ? 'เปิดกะต้องใส่ PIN ของคนขาย' : 'โหมดเลือกชื่อไม่ใช้ PIN เปิดอยู่ — ใครก็เปิดกะได้ (ปิดด้วย php artisan pos:passwordless disable)' }}
            </div>
        </div>
    </div>

    {{-- เครื่อง & sync: ฝั่ง ERP เห็นอะไรจากแต่ละเครื่อง --}}
    <div x-show="tab === 'devices'" x-cloak>
        <div class="pa-card" data-testid="pos-devices">
            <h2>เครื่อง POS</h2>
            <div class="small text-muted mb-2">ออนไลน์ = ติดต่อ ERP ภายใน {{ $onlineMinutes }} นาที · ตัวเลขผิดพลาดนับย้อนหลัง 24 ชม.</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>เครื่อง</th><th>สาขา</th><th>ติดต่อล่าสุด</th><th>กะ</th><th class="text-end">บิลวันนี้</th><th>ปัญหา</th><th class="text-end"></th></tr></thead>
                    <tbody>
                    @forelse($devices as $row)
                        @php $d = $row['device']; $problems = $row['failed_requests'] + $row['login_failures'] + $row['offline_review']; @endphp
                        <tr class="{{ $row['active'] ? '' : 'text-muted' }}">
                            <td>
                                <span class="pa-dot {{ ! $row['active'] ? 'off' : ($row['online'] ? 'on' : 'warn') }}"></span>
                                <span class="fw-semibold">{{ $d->name }}</span>
                                <div class="small text-muted"><code>{{ $d->terminal_code }}</code> · {{ $d->user?->username }}</div>
                            </td>
                            <td>{{ $d->branch?->code }}</td>
                            <td>{{ ! $row['active'] ? 'เพิกถอนแล้ว' : ($d->last_seen_at?->diffForHumans() ?? 'ยังไม่เคยต่อ — วาง Token ที่เครื่อง') }}</td>
                            <td>
                                @if($row['shift'])
                                    <span class="badge text-bg-success">เปิด</span> <span class="small">{{ $row['shift']->cashier_code }}</span>
                                    <div class="small text-muted">{{ $row['shift']->shift_no }}</div>
                                @else
                                    <span class="small text-muted">ไม่มีกะเปิด</span>
                                @endif
                            </td>
                            <td class="text-end">{{ $row['receipts_today'] }} บิล<div class="small text-muted">{{ number_format($row['sales_today'], 2) }}</div></td>
                            <td>
                                @if($problems === 0)
                                    <span class="small text-success">ปกติ</span>
                                @else
                                    <div class="small text-danger">
                                        @if($row['failed_requests'])ส่งไม่สำเร็จ {{ $row['failed_requests'] }}<br>@endif
                                        @if($row['login_failures'])ล็อกอินผิด {{ $row['login_failures'] }}<br>@endif
                                        @if($row['offline_review'])บิลออฟไลน์รอตรวจ {{ $row['offline_review'] }}@endif
                                    </div>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @if($row['active'])
                                    <form method="post" action="{{ route('pos-admin.devices.assign', $d) }}" class="d-inline-flex gap-1 mb-1">
                                        @csrf
                                        <select name="user_id" class="form-select form-select-sm" style="width:auto" aria-label="คนประจำเครื่อง">
                                            @foreach(collect($branches)->firstWhere('branch.id', $d->branch_id)['cashier_list'] ?? [] as $person)
                                                @if($person->user_id)<option value="{{ $person->user_id }}" @selected($person->user_id === $d->user_id)>{{ $person->code }} {{ $person->name }}</option>@endif
                                            @endforeach
                                        </select>
                                        <button class="btn btn-sm btn-outline-success">เปลี่ยนคน</button>
                                    </form>
                                    <form method="post" action="{{ route('settings.pos-token.rotate') }}" class="d-inline" onsubmit="return confirm('ออก Token ใหม่ให้ {{ addslashes($d->name) }}? Token เดิมใช้ไม่ได้ทันที ต้องวาง Token ใหม่ที่เครื่อง')">
                                        @csrf
                                        <input type="hidden" name="pos_device_id" value="{{ $d->id }}">
                                        <button class="btn btn-sm btn-outline-primary" title="ออก Token ใหม่"><i class="bi bi-arrow-repeat"></i> Token ใหม่</button>
                                    </form>
                                @endif
                                <form method="post" action="{{ route('settings.pos-token.delete') }}" class="d-inline" onsubmit="return confirm('ลบเครื่อง {{ addslashes($d->name) }}? เครื่องนี้จะเชื่อม ERP ไม่ได้อีก')">
                                    @csrf @method('DELETE')
                                    <input type="hidden" name="pos_device_id" value="{{ $d->id }}">
                                    <button class="btn btn-sm btn-outline-danger" title="ลบเครื่อง"><i class="bi bi-trash3"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีเครื่อง — ไปที่แท็บ ภาพรวมสาขา แล้วกด เพิ่มเครื่อง</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="pa-card" data-testid="pos-issues">
            <h2>ปัญหาล่าสุด (24 ชม.)</h2>
            @if($issues->isEmpty())
                <div class="small text-success"><i class="bi bi-check-circle me-1"></i>ไม่มีปัญหา</div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>เวลา</th><th>เครื่อง</th><th>เรื่อง</th><th>รายละเอียด</th></tr></thead>
                        <tbody>
                        @foreach($issues as $issue)
                            <tr>
                                <td class="text-nowrap small">{{ \Illuminate\Support\Carbon::parse($issue['at'])->format('d/m H:i') }}</td>
                                <td class="small">{{ $issue['device'] }}</td>
                                <td class="small fw-semibold">{{ $issue['kind'] }}</td>
                                <td class="small text-muted">{{ $issue['detail'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="small mt-2"><a href="{{ route('pos.offline-sales.index') }}">ตรวจบิลออฟไลน์</a></div>
            @endif
        </div>
    </div>

    {{-- คนขาย & PIN --}}
    <div x-show="tab === 'cashiers'" x-cloak>
        <div class="pa-card" data-testid="pos-cashiers">
            <h2>คนขายและ PIN</h2>
            <div class="small text-muted mb-2">แคชเชียร์ 1 รหัสต่อเครื่อง · หัวหน้าใช้อนุมัติลดราคา/ยกเลิกบิล · กด ออก PIN ชั่วคราว แล้วบอกพนักงาน เครื่องจะให้เปลี่ยนเองตอนล็อกอินครั้งแรก</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>สาขา</th><th>รหัส</th><th>ชื่อ</th><th>PIN</th><th class="text-end"></th></tr></thead>
                    <tbody>
                    @foreach($branches as $row)
                        @foreach($row['cashier_list'] as $cashier)
                            @php $hasPin = $overview->hasPin($cashier); @endphp
                            <tr>
                                <td>{{ $row['branch']->code }}</td>
                                <td class="fw-semibold">{{ $cashier->code }}</td>
                                <td>{{ $cashier->name }}</td>
                                <td>
                                    @if(! $hasPin)<span class="badge text-bg-warning">ยังไม่ตั้ง</span>
                                    @elseif($cashier->must_change_pin)<span class="badge text-bg-info">รอเปลี่ยนครั้งแรก</span>
                                    @else<span class="badge text-bg-success">ตั้งแล้ว</span>@endif
                                </td>
                                <td class="text-end text-nowrap">
                                    <form method="post" action="{{ route('pos-admin.cashiers.move', $cashier) }}" class="d-inline-flex gap-1" onsubmit="return confirm('ย้าย {{ $cashier->code }} ไปสาขาที่เลือก?')">
                                        @csrf
                                        <select name="branch_id" class="form-select form-select-sm" style="width:auto" aria-label="ย้ายไปสาขา">
                                            @foreach($allBranches as $target)<option value="{{ $target->id }}" @selected($target->id === $cashier->branch_id)>{{ $target->code }}</option>@endforeach
                                        </select>
                                        <button class="btn btn-sm btn-outline-success">ย้ายสาขา</button>
                                    </form>
                                    <form method="post" action="{{ route('settings.pos-cashiers.reset-pin', $cashier) }}" class="d-inline" onsubmit="return confirm('ออก PIN ชั่วคราวให้ {{ $cashier->code }}? PIN เดิมใช้ไม่ได้ทันที')">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-key me-1"></i>ออก PIN ชั่วคราว</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ฮาร์ดแวร์ต่อเครื่อง --}}
    <div x-show="tab === 'hardware'" x-cloak>
        <div class="pa-card">
            <h2>ฮาร์ดแวร์แต่ละจุดขาย</h2>
            <div class="small text-muted mb-3">เครื่องรับค่าชุดนี้ตอนต่อออนไลน์ · จุดขายถูกสร้างเมื่อเครื่องเปิดกะครั้งแรก</div>
            @forelse($terminals as $terminal)
                @php
                    $hw = array_merge(['printer_driver' => 'browser', 'printer_name' => '', 'printer_address' => '', 'paper_width' => '80mm',
                        'scanner_mode' => 'keyboard', 'scale_mode' => 'keyboard', 'customer_display' => 'none',
                        'cash_drawer_enabled' => false, 'auto_print' => false, 'print_copies' => 1], $terminal->hardware_profile ?? []);
                @endphp
                <form method="post" action="{{ route('settings.pos-terminal.hardware') }}" class="border rounded-2 p-3 mb-3">
                    @csrf
                    <input type="hidden" name="pos_terminal_id" value="{{ $terminal->id }}">
                    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                        <div><strong>{{ $terminal->code }}</strong> · {{ $terminal->name }} <span class="small text-muted">{{ $terminal->branch?->code }}</span></div>
                        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>บันทึก</button>
                    </div>
                    <div class="row g-2">
                        <div class="col-6 col-md-3"><label class="form-label small">เครื่องพิมพ์</label><select name="hardware[{{ $terminal->id }}][printer_driver]" class="form-select form-select-sm">
                            @foreach(['browser' => 'พิมพ์ผ่านระบบ', 'windows' => 'Windows Printer', 'escpos_usb' => 'ESC/POS USB', 'escpos_network' => 'ESC/POS Network'] as $v => $l)<option value="{{ $v }}" @selected($hw['printer_driver'] === $v)>{{ $l }}</option>@endforeach
                        </select></div>
                        <div class="col-6 col-md-3"><label class="form-label small">ชื่อเครื่องพิมพ์</label><input name="hardware[{{ $terminal->id }}][printer_name]" value="{{ $hw['printer_name'] }}" class="form-control form-control-sm" placeholder="POS-80"></div>
                        <div class="col-6 col-md-3"><label class="form-label small">IP / USB</label><input name="hardware[{{ $terminal->id }}][printer_address]" value="{{ $hw['printer_address'] }}" class="form-control form-control-sm" placeholder="192.168.1.20:9100"></div>
                        <div class="col-6 col-md-3"><label class="form-label small">กระดาษ</label><select name="hardware[{{ $terminal->id }}][paper_width]" class="form-select form-select-sm">
                            @foreach(['80mm' => '80 มม.', '58mm' => '58 มม.'] as $v => $l)<option value="{{ $v }}" @selected($hw['paper_width'] === $v)>{{ $l }}</option>@endforeach
                        </select></div>
                        <div class="col-6 col-md-3"><label class="form-label small">เครื่องสแกน</label><select name="hardware[{{ $terminal->id }}][scanner_mode]" class="form-select form-select-sm">
                            @foreach(['keyboard' => 'Keyboard', 'serial' => 'Serial'] as $v => $l)<option value="{{ $v }}" @selected($hw['scanner_mode'] === $v)>{{ $l }}</option>@endforeach
                        </select></div>
                        <div class="col-6 col-md-3"><label class="form-label small">เครื่องชั่ง</label><select name="hardware[{{ $terminal->id }}][scale_mode]" class="form-select form-select-sm">
                            @foreach(['none' => 'ไม่ใช้', 'keyboard' => 'Barcode', 'serial' => 'Serial'] as $v => $l)<option value="{{ $v }}" @selected($hw['scale_mode'] === $v)>{{ $l }}</option>@endforeach
                        </select></div>
                        <div class="col-6 col-md-3"><label class="form-label small">จอลูกค้า</label><select name="hardware[{{ $terminal->id }}][customer_display]" class="form-select form-select-sm">
                            @foreach(['none' => 'ไม่ใช้', 'browser' => 'หน้าจอ 2', 'serial' => 'Serial', 'network' => 'Network'] as $v => $l)<option value="{{ $v }}" @selected($hw['customer_display'] === $v)>{{ $l }}</option>@endforeach
                        </select></div>
                        <div class="col-6 col-md-3"><label class="form-label small">สำเนา</label><input type="number" min="1" max="3" name="hardware[{{ $terminal->id }}][print_copies]" value="{{ $hw['print_copies'] }}" class="form-control form-control-sm"></div>
                        <div class="col-12 d-flex gap-3">
                            <label class="form-check small"><input type="checkbox" name="hardware[{{ $terminal->id }}][cash_drawer_enabled]" value="1" @checked($hw['cash_drawer_enabled']) class="form-check-input"> ลิ้นชักเงิน</label>
                            <label class="form-check small"><input type="checkbox" name="hardware[{{ $terminal->id }}][auto_print]" value="1" @checked($hw['auto_print']) class="form-check-input"> พิมพ์อัตโนมัติ</label>
                        </div>
                    </div>
                </form>
            @empty
                <div class="small text-muted">ยังไม่มีจุดขาย — จะขึ้นเองเมื่อเครื่องเปิดกะครั้งแรก</div>
            @endforelse
        </div>
    </div>

    {{-- วิธีติดตั้งเครื่องใหม่ --}}
    <div x-show="tab === 'install'" x-cloak>
        <div class="pa-card">
            <h2>ติดตั้งเครื่อง POS ใหม่</h2>
            <div class="pa-step"><span class="pa-step-no">1</span><div class="flex-grow-1">
                <div class="fw-semibold">ดาวน์โหลดโปรแกรมที่เครื่องร้าน</div>
                <div class="small text-muted">Windows อาจขึ้น SmartScreen ให้กด More info → Run anyway</div>
                @if($installer)
                    <a href="{{ route('python-pos.download') }}" class="btn btn-primary btn-sm mt-2"><i class="bi bi-download me-1"></i>ดาวน์โหลดรุ่น {{ $installer['version'] }}</a>
                @else
                    <div class="small text-danger mt-1">ยังไม่มีไฟล์ติดตั้งบนเซิร์ฟเวอร์</div>
                @endif
            </div></div>
            <div class="pa-step"><span class="pa-step-no">2</span><div>
                <div class="fw-semibold">กด เพิ่มเครื่อง ที่แท็บ ภาพรวมสาขา</div>
                <div class="small text-muted">Token ขึ้นบนสุดของหน้านี้ กดคัดลอก</div>
            </div></div>
            <div class="pa-step"><span class="pa-step-no">3</span><div>
                <div class="fw-semibold">เปิดโปรแกรม ใส่ ERP <code>{{ request()->getSchemeAndHttpHost() }}</code> แล้ววาง Token</div>
                <div class="small text-muted">โปรแกรม sync สินค้า ราคา คนขาย · ตั้ง Local IT PIN ของเครื่อง (ใช้เข้าหน้าตั้งค่า ไม่ใช่ PIN ขาย)</div>
            </div></div>
            <div class="pa-step"><span class="pa-step-no">4</span><div>
                <div class="fw-semibold">ออก PIN คนขาย แล้วทดลองขาย 1 บิล</div>
                <div class="small text-muted">แท็บ คนขาย & PIN → ออก PIN ชั่วคราว · ขายเสร็จดูแท็บ เครื่อง & sync ว่าบิลขึ้นมาแล้ว</div>
            </div></div>
        </div>
    </div>
</div>

<script>
async function copyPosToken(text) {
    try {
        if (window.isSecureContext && navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
            return true;
        }
        const area = document.createElement('textarea');
        area.value = text;
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand('copy');
        area.remove();
        return ok;
    } catch (e) {
        return false;
    }
}
</script>
@endsection
