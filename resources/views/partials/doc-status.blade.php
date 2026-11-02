@php
    $docStatus = [
        'active' => ['เสร็จสมบูรณ์', 'text-bg-success'],
        'posted' => ['ลงบัญชีแล้ว', 'text-bg-success'],
        'approved' => ['อนุมัติแล้ว', 'text-bg-success'],
        'completed' => ['เสร็จสมบูรณ์', 'text-bg-success'],
        'pending_approval' => ['รออนุมัติ', 'text-bg-warning'],
        'open' => ['เปิดอยู่', 'text-bg-info'],
        'rejected' => ['ไม่อนุมัติ', 'text-bg-danger'],
        'cancelled' => ['ยกเลิก', 'text-bg-secondary'],
        'void' => ['ยกเลิก', 'text-bg-secondary'],
    ][$status ?? ''] ?? [$status ?? '-', 'text-bg-light border'];
@endphp
<span class="badge {{ $docStatus[1] }}">{{ $docStatus[0] }}</span>
