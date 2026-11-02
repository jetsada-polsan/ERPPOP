<form method="post" action="{{ route('master-data.destroy', [$type, $id]) }}" class="d-inline" onsubmit="return confirm('ลบรายการนี้? ถ้ามีประวัติใช้งาน ระบบจะไม่ให้ลบ ให้ปิดใช้งานแทน')">
    @csrf @method('DELETE')
    <button class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash3"></i></button>
</form>
