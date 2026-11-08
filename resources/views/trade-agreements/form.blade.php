<form method="post" action="{{ $agreement ? route($routePrefix.'.trade-agreements.update', [$party, $agreement->id]) : route($routePrefix.'.trade-agreements.store', $party) }}" class="row g-3 pt-3">
    @csrf
    @if($agreement) @method('PUT') @endif
    <label class="col-md-4">ชื่อข้อตกลง
        <input class="form-control" name="name" maxlength="120" required value="{{ $agreement?->name }}">
    </label>
    <label class="col-md-4">ภาษีมูลค่าเพิ่ม
        <select class="form-select" name="vat_mode">
            @foreach(\App\Models\TradeAgreement::VAT_MODES as $value => $label)
                <option value="{{ $value }}" @selected(($agreement?->vat_mode ?? 'standard') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="col-md-4">รูปแบบราคา
        <select class="form-select" name="prices_include_vat">
            <option value="1" @selected($agreement?->prices_include_vat ?? true)>รวม VAT แล้ว</option>
            <option value="0" @selected($agreement && ! $agreement->prices_include_vat)>ยังไม่รวม VAT</option>
        </select>
    </label>
    <label class="col-md-4">เงื่อนไขชำระเงิน
        <input class="form-control" name="payment_terms" maxlength="60" value="{{ $agreement?->payment_terms }}" placeholder="เช่น เงินสด หรือ โอนเงิน">
    </label>
    <label class="col-md-4">จำนวนวันเครดิต
        <input class="form-control" type="number" name="credit_days" min="0" max="3650" value="{{ $agreement?->credit_days }}" placeholder="ใช้ค่าเริ่มต้นของระบบ">
    </label>
    <label class="col-md-4">เริ่มใช้
        <input class="form-control" type="date" name="starts_on" value="{{ $agreement?->starts_on }}">
    </label>
    <label class="col-md-4">สิ้นสุด
        <input class="form-control" type="date" name="ends_on" value="{{ $agreement?->ends_on }}">
    </label>
    <div class="col-12 d-flex align-items-center gap-3 flex-wrap">
        <input type="hidden" name="is_active" value="0">
        <label><input type="checkbox" name="is_active" value="1" @checked($agreement?->is_active ?? true)> ใช้งาน</label>
        <input type="hidden" name="is_default" value="0">
        <label><input type="checkbox" name="is_default" value="1" @checked($agreement?->is_default)> ข้อตกลงเริ่มต้น</label>
        <button class="btn btn-primary" type="submit">บันทึกข้อตกลง</button>
    </div>
</form>
