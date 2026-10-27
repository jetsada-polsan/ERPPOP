<div x-data="{ source: @js(old('price_source', $priceTag->price_source ?? 'default')), validate(event) {
    const form = event.target;
    const problems = [];
    if (!form.elements.code.value.trim()) problems.push('กรุณากรอกรหัสป้ายราคา');
    if (!form.elements.name.value.trim()) problems.push('กรุณากรอกชื่อป้ายราคา');
    if (this.source === 'price_table' && !form.elements.price_table_id.value) problems.push('กรุณาเลือกตารางราคา');
    window.erpStopSubmit(event, problems);
} }">
    <form method="post" action="{{ isset($priceTag) ? route('price-tags.update', $priceTag) : route('price-tags.store') }}" class="row g-3" novalidate @submit="validate($event)">
        @csrf
        @isset($priceTag) @method('PUT') @endisset
        <div class="col-md-2"><label class="form-label small text-muted">รหัสป้ายราคา</label><input name="code" value="{{ old('code', $priceTag->code ?? '') }}" required class="form-control"></div>
        <div class="col-md-3"><label class="form-label small text-muted">ชื่อป้ายราคา</label><input name="name" value="{{ old('name', $priceTag->name ?? '') }}" required class="form-control" placeholder="เช่น ป้ายขายสดหน้าร้าน"></div>
        <div class="col-md-3">
            <label class="form-label small text-muted">แหล่งราคา</label>
            <select name="price_source" x-model="source" class="form-select">
                <option value="default">ราคาขายทั่วไป</option>
                <option value="price_table">ตามตารางราคา</option>
                <option value="flash_sale">ราคานาทีทอง (ถ้ามีแคมเปญ)</option>
                <option value="no_price">ไม่แสดงราคา</option>
            </select>
        </div>
        <div class="col-md-3" x-show="source === 'price_table'" x-cloak>
            <label class="form-label small text-muted">ตารางราคา</label>
            <select name="price_table_id" class="form-select">
                <option value="">-- เลือกตารางราคา --</option>
                @foreach($priceTables as $pt)
                    <option value="{{ $pt->id }}" @selected(old('price_table_id', $priceTag->price_table_id ?? null) == $pt->id)>{{ $pt->code }} - {{ $pt->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-1 d-flex align-items-end"><div class="form-check mb-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $priceTag->is_active ?? true)) class="form-check-input" id="tagActive"><label class="form-check-label" for="tagActive">ใช้</label></div></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">{{ isset($priceTag) ? 'บันทึก' : 'เพิ่ม' }}</button></div>
    </form>
</div>
