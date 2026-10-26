{{--
    ตัวเลือกสาขาสำหรับฟอร์มที่ออกเอกสารตัด/รับสต๊อก.

    สาขาที่ยังไม่ได้ตั้งคลังเริ่มต้นออกเอกสารไม่ได้ จึงบอกไว้ตรง ๆ ในรายการและกด
    เลือกไม่ได้ แทนที่จะปล่อยให้ผู้ใช้กรอกจนจบแล้วค่อยโดนเด้งกลับ.
    ฝั่ง server ยังเช็คซ้ำด้วย Controller::guardBranchWarehouse() เสมอ.

    ใช้: @include('partials.branch-options', ['branches' => $branches])
--}}
@foreach($branches as $branch)
    @if($branch->hasDefaultWarehouse())
        <option value="{{ $branch->id }}">{{ $branch->code }} - {{ $branch->name_th }}</option>
    @else
        <option value="{{ $branch->id }}" disabled>{{ $branch->code }} - {{ $branch->name_th }} (ยังไม่ได้ตั้งคลัง)</option>
    @endif
@endforeach
