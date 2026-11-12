@extends('layout')
@section('title', 'ศูนย์โปรโมชั่น - MPM Food Group')
@section('page-title', 'ศูนย์โปรโมชั่น')
@section('page-subtitle', 'ลดราคา นาทีทอง ซื้อครบ/แถม โปรทั้งบิล บัตรส่วนลด และแต้มสมาชิก รวมไว้ที่เดียว')
@section('content')
<style>
    .promo-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(250px, 1fr)); gap:14px; margin-bottom:22px; }
    .promo-card { display:flex; flex-direction:column; gap:6px; padding:16px; border:1px solid var(--erp-border, #dce7ef); border-radius:14px;
        background:var(--erp-surface, #fff); color:inherit; text-decoration:none; transition:.15s ease; min-height:150px; }
    .promo-card:hover { border-color:var(--erp-primary, #1a9bdc); box-shadow:0 6px 18px rgba(23,50,77,.08); transform:translateY(-1px); }
    .promo-card .icon { width:40px; height:40px; border-radius:12px; display:grid; place-items:center; font-size:20px; }
    .promo-card h3 { font-size:15px; font-weight:700; margin:4px 0 0; }
    .promo-card p { font-size:12.5px; color:var(--erp-muted, #6b8294); margin:0; flex:1; }
    .promo-card .count { font-size:12px; font-weight:700; color:var(--erp-primary-ink, #1464c4); }
    .tone-amber { background:#fff4dc; color:#a36500; } .tone-red { background:#ffe6e6; color:#c62828; }
    .tone-orange { background:#ffeedd; color:#c2570c; } .tone-blue { background:#e6f1ff; color:#1464c4; }
    .tone-pink { background:#ffe8f3; color:#b8327a; } .tone-teal { background:#dff6f2; color:#0f766e; }
    .promo-running th { font-size:12px; white-space:nowrap; } .promo-running td { font-size:13px; vertical-align:middle; }
    .promo-type { font-size:11.5px; font-weight:700; padding:3px 8px; border-radius:999px; background:#eef4fa; color:#355a7a; white-space:nowrap; }
</style>

<div class="promo-grid">
    <a class="promo-card" href="{{ route('promotions.index') }}">
        <span class="icon tone-amber"><i class="bi bi-percent"></i></span>
        <h3>ลดราคาสินค้ารายตัว</h3>
        <p>ลดเป็นบาทหรือ % ให้สินค้าตัวเดียว ตามช่วงวันที่ ใช้ทั้งหน้าร้านและหลังบ้าน</p>
        <span class="count">ใช้งาน {{ number_format($counts['promotions']) }} รายการ</span>
    </a>
    <a class="promo-card" href="{{ route('flash-sales.index') }}">
        <span class="icon tone-red"><i class="bi bi-lightning-charge-fill"></i></span>
        <h3>นาทีทอง</h3>
        <p>ราคาพิเศษเฉพาะสาขา วันที่ วันในสัปดาห์ และช่วงเวลา เช่น 17:00–19:00</p>
        <span class="count">ใช้งาน {{ number_format($counts['flash']) }} แคมเปญ</span>
    </a>
    <a class="promo-card" href="{{ route('qty-promotions.index') }}">
        <span class="icon tone-orange"><i class="bi bi-boxes"></i></span>
        <h3>ซื้อครบ / ราคาชุด / ซื้อแถม / ซื้อคละ</h3>
        <p>เช่น 3 แพ็ค 100, ซื้อ 2 แถม 1, ซื้อครบ 2 ลด 5 บาท, คละ 3 ชิ้น 100 บาท</p>
        <span class="count">ยังไม่หมดเวลา {{ number_format($counts['qty']) }} รายการ</span>
    </a>
    <a class="promo-card" href="{{ route('qty-promotions.index') }}#create-promo-form">
        <span class="icon tone-blue"><i class="bi bi-receipt"></i></span>
        <h3>โปรทั้งบิล</h3>
        <p>ยอดครบแล้วลด %, แถมสินค้า หรือแลกซื้อในราคาพิเศษ ได้ 1 สิทธิ์ต่อบิล</p>
        <span class="count">ยังไม่หมดเวลา {{ number_format($counts['bill']) }} รายการ</span>
    </a>
    <a class="promo-card" href="{{ route('discount-cards.index') }}">
        <span class="icon tone-pink"><i class="bi bi-credit-card-2-front-fill"></i></span>
        <h3>บัตรส่วนลด</h3>
        <p>บัตรหรือรหัสส่วนลดสำหรับลูกค้า/สมาชิก กำหนดวงเงิน วันหมดอายุ และจำนวนครั้ง</p>
        <span class="count">ใช้งาน {{ number_format($counts['cards']) }} ใบ</span>
    </a>
    <a class="promo-card" href="{{ route('member-points.index') }}">
        <span class="icon tone-teal"><i class="bi bi-stars"></i></span>
        <h3>แต้มสมาชิก</h3>
        <p>กติกาสะสมแต้มและตัวคูณแต้มตามช่วงเวลา แลกแต้มเป็นส่วนลด</p>
        <span class="count">กติกาใช้งาน {{ number_format($counts['points']) }} ข้อ</span>
    </a>
</div>

<div class="content-card p-0 promo-running">
    <div class="d-flex justify-content-between align-items-center px-4 pt-3 pb-2">
        <div>
            <h2 class="h6 fw-bold mb-0">โปรที่กำลังใช้อยู่วันนี้</h2>
            <small class="text-muted">รวมทุกประเภท · กดชื่อเพื่อแก้ไขหรือปิดในหน้าของประเภทนั้น</small>
        </div>
        <span class="badge text-bg-light border">{{ $running->count() }} รายการ</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>ประเภท</th><th>รหัส</th><th>ชื่อ</th><th>รายละเอียด</th><th>สาขา</th><th>ช่วงเวลา</th></tr>
            </thead>
            <tbody>
                @forelse($running as $row)
                    <tr>
                        <td><span class="promo-type">{{ $row['type'] }}</span></td>
                        <td class="font-monospace small">{{ $row['code'] }}</td>
                        <td><a href="{{ $row['url'] }}" class="fw-semibold text-decoration-none">{{ $row['name'] }}</a></td>
                        <td class="small">{{ $row['detail'] }}</td>
                        <td class="small">{{ $row['branch'] }}</td>
                        <td class="small text-nowrap">{{ $row['period'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">วันนี้ยังไม่มีโปรที่เปิดใช้งาน</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
