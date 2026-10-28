{{-- เลือกบัญชีธนาคารเมื่อรับ/จ่ายด้วยโอนหรือเช็ค (ข้อ 12) ไม่เลือก = บัญชีของสาขา ถ้าไม่มีใช้บัญชีกลาง --}}
@php
    $bankChoices = \App\Models\BankAccount::with('branch')->whereNotNull('chart_of_account_id')->orderBy('bank_name')->get();
@endphp
<div x-show="{{ $model }} !== 'cash'" x-cloak class="{{ $class ?? 'mt-2' }}">
    <label class="form-label text-muted small">เข้า/ออกบัญชีธนาคาร</label>
    <select name="bank_account_id" class="form-select" :disabled="{{ $model }} === 'cash'">
        <option value="">อัตโนมัติ — บัญชีของสาขา (ถ้าไม่มีใช้บัญชีกลาง)</option>
        @foreach($bankChoices as $bank)
            <option value="{{ $bank->id }}">{{ $bank->bank_name }} {{ $bank->account_no }}{{ $bank->branch ? ' · '.$bank->branch->code : ' · บัญชีกลาง' }}</option>
        @endforeach
    </select>
    @if($bankChoices->isEmpty())
        <div class="form-text text-warning">ยังไม่มีบัญชีธนาคารที่ผูกผังบัญชี — ตั้งที่เมนู บัญชีธนาคาร</div>
    @endif
</div>
