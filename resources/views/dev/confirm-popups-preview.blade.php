@extends('layout')

@section('title', 'ตัวอย่าง Confirm Popups 4 โทนสี - MPM Food Group')

@section('content')
<div class="container-fluid py-4">
    <div class="content-card p-4 mb-4">
        <h2 class="h4 fw-bold mb-2">ระบบ Popup ยืนยันแบบเดียวกันทั้งระบบ (4 โทนสี)</h2>
        <p class="text-muted mb-0">ตัวอย่างสำหรับทดสอบการแสดงผล SweetAlert2 และคอมโพเนนต์ confirm-action ตามระดับความหนักของงาน</p>
    </div>

    <div class="row g-4 mb-4">
        <!-- 1. Danger Tone -->
        <div class="col-md-6 col-lg-3">
            <div class="content-card p-4 h-100 border-start border-4 border-danger">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge text-bg-danger">Danger (สีแดง)</span>
                </div>
                <h3 class="h6 fw-bold">ลบ / ยกเลิก / Void / คืนเงิน</h3>
                <p class="small text-muted mb-3">การกระทำที่ส่งผลกระทบรุนแรง ย้อนกลับไม่ได้ หรือกระทบสต็อก/บัญชี ย้ายโฟกัสเริ่มต้นไปที่ปุ่ม "ยกเลิก" เพื่อความปลอดภัย</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-danger" onclick="testDanger()">
                        <i class="bi bi-trash3 me-1"></i> ทดสอบ erpConfirm
                    </button>
                    <form method="post" action="#" data-confirm="ยืนยันยกเลิกเอกสารนี้? รายการสต็อกและบัญชีจะถูกกลับรายการ" data-confirm-tone="danger" data-confirm-ok="ยกเลิกเอกสาร">
                        <button class="btn btn-outline-danger w-100">
                            <i class="bi bi-x-octagon me-1"></i> ทดสอบ data-confirm
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 2. Warning Tone -->
        <div class="col-md-6 col-lg-3">
            <div class="content-card p-4 h-100 border-start border-4 border-warning">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge text-bg-warning">Warning (สีส้ม)</span>
                </div>
                <h3 class="h6 fw-bold">อนุมัติ / ปรับสต็อก / โอน</h3>
                <p class="small text-muted mb-3">การเปลี่ยนสถานะเอกสารสำคัญ การโอนย้ายคลังสินค้า หรือการปรับปรุงยอดสต็อก</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-warning text-white" onclick="testWarning()">
                        <i class="bi bi-check2-circle me-1"></i> ทดสอบ erpConfirm
                    </button>
                    <form method="post" action="#" data-confirm="ยืนยันอนุมัติและปรับปรุงสต็อกตามรายการตรวจนับ?" data-confirm-tone="warning" data-confirm-ok="อนุมัติและปรับยอด">
                        <button class="btn btn-outline-warning w-100">
                            <i class="bi bi-arrow-left-right me-1"></i> ทดสอบ data-confirm
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 3. Primary Tone -->
        <div class="col-md-6 col-lg-3">
            <div class="content-card p-4 h-100 border-start border-4 border-primary">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge text-bg-primary">Primary (สีน้ำเงิน)</span>
                </div>
                <h3 class="h6 fw-bold">บันทึกทั่วไป / ออกจากระบบ</h3>
                <p class="small text-muted mb-3">การยืนยันการทำงานทั่วไป การแปลงเอกสาร หรือ fallback อัตโนมัติเมื่อไม่ได้ระบุโทน</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-primary" onclick="testPrimary()">
                        <i class="bi bi-question-circle me-1"></i> ทดสอบ erpConfirm
                    </button>
                    <form method="post" action="#" data-confirm="แปลงใบเสนอราคานี้ไปยังใบสั่งขายใหม่?" data-confirm-tone="primary" data-confirm-ok="แปลงเอกสาร">
                        <button class="btn btn-outline-primary w-100">
                            <i class="bi bi-box-arrow-right me-1"></i> ทดสอบ data-confirm
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 4. Success Tone -->
        <div class="col-md-6 col-lg-3">
            <div class="content-card p-4 h-100 border-start border-4 border-success">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge text-bg-success">Success (สีเขียว)</span>
                </div>
                <h3 class="h6 fw-bold">รับเงิน / รับของครบ</h3>
                <p class="small text-muted mb-3">การรับชำระหนี้ครบถ้วน หรือตรวจรับสินค้าเข้าคลังครบตามใบสั่งซื้อ</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-success" onclick="testSuccess()">
                        <i class="bi bi-check-lg me-1"></i> ทดสอบ erpConfirm
                    </button>
                    <form method="post" action="#" data-confirm="ยืนยันการรับชำระเงินและปิดยอดลูกหนี้ครบถ้วน?" data-confirm-tone="success" data-confirm-ok="บันทึกรับเงิน">
                        <button class="btn btn-outline-success w-100">
                            <i class="bi bi-wallet2 me-1"></i> ทดสอบ data-confirm
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function testDanger() {
    window.erpConfirm('ยืนยันยกเลิกเอกสารนี้?', 'ระบบจะกลับรายการสต๊อกและบัญชี ย้อนไม่ได้', {
        tone: 'danger',
        confirmButtonText: 'ยกเลิกเอกสาร',
        cancelButtonText: 'ไม่ยกเลิก',
    });
}
function testWarning() {
    window.erpConfirm('ยืนยันอนุมัติและปรับยอดสต็อก?', 'สต็อกของคลังนี้จะถูกปรับตามยอดที่ตรวจนับจริงทันที', {
        tone: 'warning',
        confirmButtonText: 'อนุมัติและปรับยอด',
        cancelButtonText: 'ยกเลิก',
    });
}
function testPrimary() {
    window.erpConfirm('แปลงใบเสนอราคานี้ไปยังใบสั่งขาย?', 'ระบบจะคัดลอกรายการสินค้าทั้งหมดไปยังใบสั่งขายใหม่', {
        tone: 'primary',
        confirmButtonText: 'แปลงเอกสาร',
        cancelButtonText: 'ยกเลิก',
    });
}
function testSuccess() {
    window.erpConfirm('ทำเครื่องหมายว่าเก็บเงินครบแล้ว?', 'ยอดหนี้ตามใบวางบิลนี้จะเปลี่ยนสถานะเป็นเก็บเงินแล้ว', {
        tone: 'success',
        confirmButtonText: 'เก็บเงินแล้ว',
        cancelButtonText: 'ยกเลิก',
    });
}
</script>
@endsection
