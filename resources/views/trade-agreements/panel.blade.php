<section id="trade-agreements" class="content-card p-4 mb-4">
    <h2 class="h5">ข้อตกลง{{ $routePrefix === 'customers' ? 'ขาย' : 'ซื้อ' }}สินค้า</h2>
    <p class="small text-muted">ตั้ง VAT รูปแบบราคา และเงื่อนไขชำระเงินแยกตามข้อตกลง</p>
    @foreach($party->tradeAgreements()->orderByDesc('is_default')->orderBy('name')->get() as $agreement)
        <details class="border rounded p-3 mb-2">
            <summary>
                {{ $agreement->name }} · {{ \App\Models\TradeAgreement::VAT_MODES[$agreement->vat_mode] }}
                @if($agreement->is_default) <span class="badge text-bg-primary">ค่าเริ่มต้น</span> @endif
                @unless($agreement->is_active) <span class="badge text-bg-secondary">ปิดใช้งาน</span> @endunless
            </summary>
            @include('trade-agreements.form')
        </details>
    @endforeach
    <details class="border rounded p-3">
        <summary>เพิ่มข้อตกลง</summary>
        @include('trade-agreements.form', ['agreement' => null])
    </details>
</section>
