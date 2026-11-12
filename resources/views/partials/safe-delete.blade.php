<form method="post" action="{{ route('master-data.destroy', [$type, $id]) }}" class="d-inline" data-confirm="{{ $type === 'branches' ? 'ปิดหรือลบสาขานี้? ถ้าสาขามีประวัติใช้งาน ระบบจะปิดใช้งานสาขาทันที เครื่อง POS และผู้ใช้ของสาขานี้อาจใช้งานไม่ได้' : 'ลบรายการนี้? ถ้ามีประวัติใช้งาน ระบบจะปิดใช้งานแทนการลบ' }}" data-confirm-tone="danger">
    @csrf @method('DELETE')
    <button class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash3"></i></button>
</form>
