@extends('layout')

@section('title', "Batch #{$batch->id} - POS Import - POPSTAR ERP")

@section('content')
    <a href="{{ route('pos-import.page') }}" class="text-sm text-blue-600 hover:underline">&larr; กลับไปรายการ Batch</a>

    <div class="bg-white rounded-xl shadow p-5 my-4">
        <div class="flex items-start justify-between flex-wrap gap-4">
            <div>
                <h1 class="text-xl font-bold">Batch #{{ $batch->id }} — POS {{ $batch->pos_code }} ({{ $batch->terminal?->branch?->name_th }})</h1>
                <p class="text-sm text-gray-500 mt-1">วันที่ขาย {{ $batch->sale_date }} &middot; สถานะ
                    <span class="font-semibold">{{ $batch->status }}</span>
                    &middot; {{ $batch->record_count }} ใบเสร็จ
                </p>
                @if($batch->confirmedBy)
                <p class="text-xs text-gray-400 mt-1">ยืนยันโดย {{ $batch->confirmedBy->name }} เมื่อ {{ $batch->confirmed_at }}</p>
                @endif
            </div>
            <div class="flex gap-2">
                <form method="post" action="{{ route('pos-import.batches.revalidate', $batch) }}">
                    @csrf
                    <button class="bg-gray-600 text-white text-sm px-3 py-2 rounded">ตรวจสอบใหม่</button>
                </form>
                @if($batch->status === 'validated')
                <form id="confirm-form" method="post" action="{{ route('pos-import.batches.confirm', $batch) }}">
                    @csrf
                    <button type="button" onclick="confirmAction('confirm-form', 'ยืนยันข้อมูล Batch นี้?', 'หลังยืนยันแล้วจะแก้ใบเสร็จในนี้ไม่ได้ ต้องกด Post ต่อไป')"
                        class="bg-amber-600 text-white text-sm px-3 py-2 rounded">ยืนยัน (Confirm)</button>
                </form>
                @endif
                @if($batch->status === 'confirmed')
                <form id="post-form" method="post" action="{{ route('pos-import.batches.post', $batch) }}">
                    @csrf
                    <button type="button" onclick="confirmAction('post-form', 'Post เข้าระบบจริง?', 'จะตัดสต๊อกและบันทึกยอดขายถาวร ย้อนกลับไม่ได้')"
                        class="bg-green-600 text-white text-sm px-3 py-2 rounded">Post เข้าระบบ</button>
                </form>
                @endif
            </div>
        </div>
    </div>

    @if($errors_table = $errors->count())
    <div class="bg-white rounded-xl shadow p-5 mb-6">
        <h2 class="font-semibold mb-3 text-red-700">Errors ({{ $errors->total() }})</h2>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 border-b">
                    <th class="py-2">ใบเสร็จ</th><th>บรรทัด</th><th>ประเภท</th><th>รายละเอียด</th>
                </tr>
            </thead>
            <tbody>
                @foreach($errors as $e)
                <tr class="border-b last:border-0">
                    <td class="py-2">{{ $e->receipt_no }}</td>
                    <td>{{ $e->line_no }}</td>
                    <td><span class="px-2 py-0.5 rounded text-xs bg-red-100 text-red-700">{{ $e->error_type }}</span></td>
                    <td class="text-gray-600">{{ $e->error_message }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div class="mt-4">{{ $errors->links() }}</div>
    </div>
    @endif

    <div class="bg-white rounded-xl shadow p-5">
        <h2 class="font-semibold mb-3">ใบเสร็จ ({{ $receipts->total() }})</h2>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 border-b">
                    <th class="py-2">เลขที่</th><th>วันที่</th><th>แคชเชียร์</th><th class="text-right">ยอดสุทธิ</th>
                    <th class="text-right">รายการ</th><th class="text-right">การชำระ</th><th>สถานะ</th>
                </tr>
            </thead>
            <tbody>
                @forelse($receipts as $r)
                @php
                $rcolors = ['pending' => 'bg-gray-100 text-gray-600', 'valid' => 'bg-blue-100 text-blue-700', 'error' => 'bg-red-100 text-red-700', 'posted' => 'bg-green-100 text-green-700', 'voided' => 'bg-gray-100 text-gray-400'];
                @endphp
                <tr class="border-b last:border-0">
                    <td class="py-2">{{ $r->receipt_no }}</td>
                    <td>{{ $r->receipt_date }} {{ $r->receipt_time }}</td>
                    <td>{{ $r->cashier_code }}</td>
                    <td class="text-right">{{ number_format($r->net_amount, 2) }}</td>
                    <td class="text-right">{{ $r->items_count }}</td>
                    <td class="text-right">{{ $r->payments_count }}</td>
                    <td><span class="px-2 py-0.5 rounded text-xs {{ $rcolors[$r->status] ?? '' }}">{{ $r->status }}</span></td>
                </tr>
                @empty
                <tr><td colspan="7" class="py-6 text-center text-gray-400">ไม่มีใบเสร็จ</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="mt-4">{{ $receipts->links() }}</div>
    </div>
@endsection

@push('scripts')
<script>
    function confirmAction(formId, title, text) {
        Swal.fire({
            title: title,
            text: text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'ยืนยัน',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#16a34a',
            cancelButtonColor: '#6b7280',
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById(formId).submit();
            }
        });
    }

    (() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('import_popup') !== '1') return;

        const created = Number(params.get('created') || 0);
        const skipped = Number(params.get('skipped') || 0);
        let text = `รับไฟล์ POS แล้ว ${created} ไฟล์`;
        if (skipped > 0) text += ` / ข้ามไฟล์เดิม ${skipped} ไฟล์`;

        Swal.fire({
            icon: 'success',
            title: created > 0 ? 'Import สำเร็จ' : 'ไฟล์นี้เคยนำเข้าแล้ว',
            text,
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#10b981',
            timer: 4200,
            timerProgressBar: true,
        });

        window.history.replaceState({}, document.title, window.location.pathname);
    })();
</script>
@endpush
