@extends('layout')
@section('title', 'สุขภาพระบบ (APM) - MPM Food Group')
@section('page-title', 'ศูนย์ติดตามสุขภาพระบบ (APM & Monitoring)')
@section('page-subtitle', 'ตรวจสอบ 5xx Error ล่าสุด, เวลาตอบสนองเฉลี่ย, บิล POS ที่ติดปัญหา, พื้นที่ดิสก์ และขนาด Log')

@push('head')
<style>
.health-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.health-stat { padding: 14px 16px; background: #fff; border: 1px solid var(--erp-border); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); }
.health-stat span { display: block; color: var(--erp-muted); font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
.health-stat strong { font-size: 22px; color: var(--erp-primary-dark); display: block; margin-top: 4px; }
.health-stat small { font-size: 11px; color: var(--erp-muted); }
.health-section { background: #fff; border: 1px solid var(--erp-border); border-radius: 8px; padding: 16px; margin-bottom: 16px; }
.health-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 12px; }
.health-head h2 { font-size: 16px; font-weight: 800; margin: 0; color: #1e293b; }
.badge-5xx { background-color: #fee2e2; color: #991b1b; font-weight: 700; border: 1px solid #fecaca; }
.badge-ok { background-color: #dcfce7; color: #166534; font-weight: 700; border: 1px solid #bbf7d0; }
.badge-warn { background-color: #fef3c7; color: #92400e; font-weight: 700; border: 1px solid #fde68a; }
.code-inline { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 11px; color: #334155; word-break: break-all; }
@media (max-width: 900px) { .health-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 500px) { .health-grid { grid-template-columns: 1fr; } }
</style>
@endpush

@section('content')
@if(session('success'))
    <div class="alert alert-success d-flex align-items-center mb-3">
        <i class="bi bi-check-circle-fill me-2"></i>
        <span>{{ session('success') }}</span>
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger d-flex align-items-center mb-3">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <span>{{ session('error') }}</span>
    </div>
@endif

{{-- Top 4 KPI Metrics --}}
<div class="health-grid">
    <div class="health-stat">
        <span>5xx Errors (24 ชม. / 7 วัน)</span>
        <strong>
            <span class="{{ $errors24h > 0 ? 'text-danger' : 'text-success' }}">{{ number_format($errors24h) }}</span>
            <span class="text-muted" style="font-size: 15px; font-weight: 400;">/ {{ number_format($errors7d) }} ครั้ง</span>
        </strong>
        <small>{{ $unique24h }} รูปแบบ error ใน 24 ชม. ล่าสุด</small>
    </div>

    <div class="health-stat">
        <span>เวลาตอบสนองเฉลี่ย (APM)</span>
        <strong>
            @if($avgResponseMs !== null)
                <span class="{{ $avgResponseMs > 1000 ? 'text-danger' : ($avgResponseMs > 400 ? 'text-warning' : 'text-success') }}">
                    {{ number_format($avgResponseMs, 1) }} ms
                </span>
            @else
                <span class="text-muted">-</span>
            @endif
        </strong>
        <small>Sample 1 ใน 20 request (p95: {{ $p95ResponseMs !== null ? number_format($p95ResponseMs, 1).' ms' : '-' }})</small>
    </div>

    <div class="health-stat">
        <span>บิล POS ไม่ผ่านวันนี้</span>
        <strong>
            <span class="{{ $posRejectedToday > 0 ? 'text-danger' : 'text-success' }}">
                {{ number_format($posRejectedToday) }} ใบ
            </span>
        </strong>
        <small>{{ $posRejectedToday > 0 ? 'ต้องตรวจสอบ (needs_review)' : 'ซิงค์เข้าสู่ระบบสมบูรณ์' }}</small>
    </div>

    <div class="health-stat">
        <span>พื้นที่ดิสก์ & ขนาด Log</span>
        <strong>
            <span class="{{ $diskUsedPercent > 85 ? 'text-danger' : 'text-primary' }}">
                เหลือ {{ $diskFreeGb }} GB
            </span>
        </strong>
        <small>ใช้ไป {{ $diskUsedPercent }}% · Log: {{ $logSizeMb }} MB</small>
    </div>
</div>

{{-- Section 1: 5xx Errors Table --}}
<section class="health-section">
    <div class="health-head">
        <div>
            <h2><i class="bi bi-exclamation-diamond-fill text-danger me-2"></i>รายงาน 5xx Server Errors (เรียงตามความถี่)</h2>
            <div class="small text-muted">รวมซ้ำด้วย fingerprint อัตโนมัติ เพื่อให้ผู้ดูแลระบบเห็นจุดที่พังบ่อยที่สุดก่อน</div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="btn-group btn-group-sm" role="group">
                <a href="{{ route('operations.health', ['period' => '24h']) }}" class="btn {{ $period === '24h' ? 'btn-primary' : 'btn-outline-secondary' }}">
                    24 ชั่วโมงล่าสุด
                </a>
                <a href="{{ route('operations.health', ['period' => '7d']) }}" class="btn {{ $period === '7d' ? 'btn-primary' : 'btn-outline-secondary' }}">
                    7 วันล่าสุด
                </a>
            </div>
        </div>
    </div>

    @if($systemErrors->isEmpty())
        <div class="alert alert-success d-flex align-items-center my-2">
            <i class="bi bi-shield-check fs-4 me-2"></i>
            <div>
                <strong>ยอดเยี่ยม! ไม่พบ 5xx Error ในช่วง {{ $period === '7d' ? '7 วันที่ผ่านมา' : '24 ชั่วโมงที่ผ่านมา' }}</strong>
                <div class="small">ระบบทำงานราบรื่น ไม่มีข้อผิดพลาดเซิร์ฟเวอร์ค้างอยู่</div>
            </div>
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 80px;">สถานะ</th>
                        <th style="width: 100px;" class="text-center">จำนวนครั้ง</th>
                        <th style="width: 150px;">เวลาล่าสุด</th>
                        <th>Exception / รายละเอียดข้อผิดพลาด</th>
                        <th>Route / URL</th>
                        <th style="width: 110px;" class="text-center">ตรวจสอบ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($systemErrors as $err)
                        <tr>
                            <td>
                                <span class="badge badge-5xx fs-7">{{ $err->status }}</span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-danger rounded-pill px-2 py-1 fs-7">
                                    {{ number_format($err->count) }} ครั้ง
                                </span>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ $err->occurred_at ? $err->occurred_at->format('d/m/Y H:i:s') : '-' }}</div>
                                <div class="text-muted small">{{ $err->occurred_at ? $err->occurred_at->diffForHumans() : '' }}</div>
                            </td>
                            <td>
                                <div class="fw-bold text-danger">{{ class_basename($err->exception_class) }}</div>
                                <div class="small text-muted">{{ $err->message }}</div>
                                <div class="mt-1"><span class="code-inline">{{ $err->file_line }}</span></div>
                            </td>
                            <td>
                                @if($err->route_name)
                                    <div><span class="badge text-bg-light border">{{ $err->route_name }}</span></div>
                                @endif
                                <div class="small text-truncate mt-1 text-muted" style="max-width: 320px;" title="{{ $err->url }}">
                                    {{ $err->url }}
                                </div>
                            </td>
                            <td class="text-center">
                                @if($err->resolved_at)
                                    <span class="badge badge-ok" title="ตรวจสอบเมื่อ {{ $err->resolved_at->format('d/m/Y H:i') }}">
                                        <i class="bi bi-check2"></i> เรียบร้อย
                                    </span>
                                @else
                                    <form method="POST" action="{{ route('operations.health.resolve', $err) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="ทำเครื่องหมายว่าตรวจสอบแล้ว">
                                            <i class="bi bi-check2"></i> ตรวจแล้ว
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if(method_exists($systemErrors, 'hasPages') && $systemErrors->hasPages())
            <div class="mt-3">
                {{ $systemErrors->links() }}
            </div>
        @endif
    @endif
</section>

{{-- Section 2: POS Rejected / Needs Review Sales Today --}}
<section class="health-section">
    <div class="health-head">
        <div>
            <h2><i class="bi bi-cart-x-fill text-warning me-2"></i>บิล POS ที่ ERP ปฏิเสธวันนี้ ({{ number_format($posRejectedToday) }} รายการ)</h2>
            <div class="small text-muted">บิลที่เครื่อง POS ส่งขึ้นมาแต่ติดเงื่อนไข เช่น กะปิดแล้ว, ราคาไม่ตรง, หรือรอผู้จัดการตรวจสอบ (needs_review)</div>
        </div>
    </div>

    @if($posRejectedToday === 0)
        <div class="alert alert-success d-flex align-items-center mb-0">
            <i class="bi bi-check-circle-fill fs-4 me-2"></i>
            <div>
                <strong>ไม่มีบิล POS ที่ถูกปฏิเสธในวันนี้</strong>
                <div class="small">บิลทุกใบจากเครื่องขายหน้าร้านได้รับการบันทึกและประมวลผลเรียบร้อย</div>
            </div>
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>UUID / เลขที่</th>
                        <th>เวลา</th>
                        <th>สาขา</th>
                        <th>จุดขาย / อุปกรณ์</th>
                        <th class="text-end">ยอดเงิน</th>
                        <th>เหตุผลที่ปฏิเสธ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($posRejectedList as $sale)
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $sale->receipt_no ?: Str::limit($sale->offline_uuid, 12) }}</span>
                            </td>
                            <td>{{ $sale->sold_at ? $sale->sold_at->format('d/m/Y H:i') : $sale->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $sale->branch?->name ?: '-' }}</td>
                            <td>{{ $sale->device?->device_name ?: ($sale->source === 'desktop' ? 'POS Desktop' : 'Web POS') }}</td>
                            <td class="text-end fw-bold">{{ number_format((float) $sale->offline_total, 2) }} ฿</td>
                            <td><span class="badge badge-warn">{{ $sale->review_reason ?: 'รอการตรวจสอบ' }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

{{-- Section 3: Performance APM & Storage Info --}}
<div class="row g-3">
    <div class="col-md-6">
        <section class="health-section h-100">
            <div class="health-head">
                <h2><i class="bi bi-speedometer2 text-info me-2"></i>ประสิทธิภาพและการตอบสนอง (APM)</h2>
            </div>
            <div class="p-3 bg-light rounded border mb-3">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">อัตราการสุ่มตัวอย่าง (Sampling Rate):</span>
                    <strong class="text-dark">1 ใน 20 Request (5%)</strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">จำนวนตัวอย่างที่บันทึก:</span>
                    <strong>{{ number_format($sampleCount) }} ตัวอย่าง</strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">เวลาตอบสนองเฉลี่ย (Mean Latency):</span>
                    <strong class="{{ ($avgResponseMs ?? 0) > 800 ? 'text-danger' : 'text-success' }}">
                        {{ $avgResponseMs !== null ? number_format($avgResponseMs, 1).' ms' : 'กำลังรวบรวม' }}
                    </strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted">เวลาตอบสนอง 95th Percentile:</span>
                    <strong class="{{ ($p95ResponseMs ?? 0) > 1500 ? 'text-danger' : 'text-primary' }}">
                        {{ $p95ResponseMs !== null ? number_format($p95ResponseMs, 1).' ms' : 'กำลังรวบรวม' }}
                    </strong>
                </div>
            </div>

            @if($recentSamples->isNotEmpty())
                <div class="small fw-bold text-muted mb-2">ตัวอย่าง Request ล่าสุด:</div>
                <div class="table-responsive">
                    <table class="table table-sm text-nowrap" style="font-size: 12px;">
                        <thead><tr><th>Path</th><th>Status</th><th class="text-end">Duration</th></tr></thead>
                        <tbody>
                            @foreach($recentSamples->take(5) as $sample)
                                <tr>
                                    <td><code>{{ $sample['path'] ?? '/' }}</code></td>
                                    <td><span class="badge text-bg-light border">{{ $sample['status'] ?? 200 }}</span></td>
                                    <td class="text-end fw-semibold {{ ($sample['duration'] ?? 0) > 500 ? 'text-warning' : 'text-success' }}">
                                        {{ number_format($sample['duration'] ?? 0, 1) }} ms
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <div class="col-md-6">
        <section class="health-section h-100">
            <div class="health-head">
                <h2><i class="bi bi-hdd-network text-primary me-2"></i>การจัดเก็บข้อมูล & การแจ้งเตือน</h2>
            </div>
            
            <div class="mb-3">
                <div class="d-flex justify-content-between mb-1">
                    <span class="small fw-semibold">พื้นที่ดิสก์คงเหลือ:</span>
                    <span class="small fw-bold">{{ $diskFreeGb }} GB / {{ $diskTotalGb }} GB (ใช้ไป {{ $diskUsedPercent }}%)</span>
                </div>
                <div class="progress" style="height: 10px;">
                    <div class="progress-bar {{ $diskUsedPercent > 85 ? 'bg-danger' : ($diskUsedPercent > 70 ? 'bg-warning' : 'bg-primary') }}" 
                         role="progressbar" 
                         style="width: {{ min(100, $diskUsedPercent) }}%"></div>
                </div>
            </div>

            <div class="p-3 bg-light rounded border mb-3">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">ขนาดไฟล์ Log (laravel.log):</span>
                    <strong>{{ $logSizeMb }} MB</strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted">ปลายทางไฟล์:</span>
                    <small class="text-truncate text-muted" style="max-width: 240px;">storage/logs/laravel.log</small>
                </div>
            </div>

            <div class="border rounded p-3">
                <div class="fw-bold mb-1"><i class="bi bi-bell-fill text-warning me-1"></i>การแจ้งเตือนทางอีเมล (ERP_ALERT_EMAIL)</div>
                <div class="small text-muted mb-2">
                    ระบบจะส่งอีเมลแจ้งเตือนอัตโนมัติเมื่อพบ Error 5xx รูปแบบใหม่ หรือมี 5xx เกิน 10 ครั้งในรอบ 10 นาที
                </div>
                @if($isAlertEmailConfigured)
                    <div class="badge badge-ok"><i class="bi bi-check-circle me-1"></i>เปิดใช้งานแล้ว: {{ $alertEmail }}</div>
                @else
                    <div class="badge badge-warn"><i class="bi bi-dash-circle me-1"></i>ยังไม่ได้ตั้งค่า ERP_ALERT_EMAIL (ไม่ส่งอีเมล, ระบบไม่ขัดข้อง)</div>
                @endif
            </div>
        </section>
    </div>
</div>
@endsection
