<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductDepartment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ModuleControlController extends Controller
{
    public function index(): View
    {
        return view('settings.module-controls', [
            'taxonomies' => [
                'category' => ['label' => 'หมวดสินค้า', 'items' => ProductCategory::withCount('products')->orderBy('code')->get()],
                'department' => ['label' => 'แผนกสินค้า', 'items' => ProductDepartment::withCount('products')->orderBy('code')->get()],
                'brand' => ['label' => 'ยี่ห้อสินค้า', 'items' => ProductBrand::withCount('products')->orderBy('code')->get()],
            ],
            'masterModules' => $this->masterModules(),
            'documentModules' => $this->documentModules(),
        ]);
    }

    public function storeTaxonomy(Request $request, string $type): RedirectResponse
    {
        $modelClass = $this->taxonomyClass($type);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique((new $modelClass)->getTable(), 'code')],
            'name_th' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
        ]);
        $record = $modelClass::create($data);
        $this->audit('create', $record, [], $record->toArray());

        return back()->with('success', 'เพิ่ม'.$this->taxonomyLabel($type).' '.$record->code.' แล้ว');
    }

    public function updateTaxonomy(Request $request, string $type, int $id): RedirectResponse
    {
        $modelClass = $this->taxonomyClass($type);
        $record = $modelClass::findOrFail($id);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique($record->getTable(), 'code')->ignore($record->id)],
            'name_th' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
        ]);
        $old = $record->toArray();
        $record->update($data);
        $this->audit('update', $record, $old, $record->fresh()->toArray());

        return back()->with('success', 'แก้ไข'.$this->taxonomyLabel($type).'แล้ว');
    }

    public function destroyTaxonomy(string $type, int $id): RedirectResponse
    {
        $modelClass = $this->taxonomyClass($type);
        /** @var ProductCategory|ProductDepartment|ProductBrand $record */
        $record = $modelClass::withCount('products')->findOrFail($id);
        if ($record->products_count > 0) {
            return back()->withErrors([
                'taxonomy' => "ลบไม่ได้ เพราะมีสินค้า {$record->products_count} รายการใช้งานอยู่ ให้ย้ายสินค้าออกจากรายการนี้ก่อน",
            ]);
        }

        $old = $record->toArray();
        $this->audit('delete', $record, $old, []);
        $record->delete();

        return back()->with('success', 'ลบ'.$this->taxonomyLabel($type).' '.$record->code.' แล้ว');
    }

    private function taxonomyClass(string $type): string
    {
        return match ($type) {
            'category' => ProductCategory::class,
            'department' => ProductDepartment::class,
            'brand' => ProductBrand::class,
            default => abort(404),
        };
    }

    private function taxonomyLabel(string $type): string
    {
        return match ($type) {
            'category' => 'หมวดสินค้า',
            'department' => 'แผนกสินค้า',
            'brand' => 'ยี่ห้อสินค้า',
            default => 'ข้อมูล',
        };
    }

    private function audit(string $action, Model $record, array $old, array $new): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'table_name' => $record->getTable(),
            'record_id' => $record->getKey(),
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }

    private function masterModules(): array
    {
        return [
            ['สินค้า', 'products.index', 'เพิ่ม / แก้ไข / ปิดใช้งาน', 'มีประวัติราคา Lot ผู้ขาย และต้นทุนรายงวด'],
            ['หมวด แผนก ยี่ห้อสินค้า', 'settings.module-controls', 'เพิ่ม / แก้ไข / ลบเมื่อไม่ถูกใช้', 'จัดการในหน้านี้'],
            ['หน่วยสินค้า', 'product-units.index', 'เพิ่ม / แก้ไข', 'หน่วยที่ถูกเอกสารใช้แล้วไม่ควรลบ'],
            ['ตารางราคา', 'price-tables.index', 'เพิ่ม / แก้ไข / ปิดใช้งาน', 'ราคาใหม่มีประวัติและวันเริ่มใช้'],
            ['ลูกค้า / ลูกหนี้', 'customers.index', 'เพิ่ม / แก้ไข / ปิดใช้งาน', 'ห้ามปิดเมื่อยังมียอดลูกหนี้'],
            ['ผู้จำหน่าย / เจ้าหนี้', 'suppliers.index', 'เพิ่ม / แก้ไข / ปิดใช้งาน', 'ห้ามปิดเมื่อยังมียอดเจ้าหนี้'],
            ['สมาชิก', 'members.index', 'เพิ่ม / แก้ไข / ปิดใช้งาน', 'แต้มคงเหลือและประวัติยังอยู่'],
            ['พนักงานขาย', 'salesmen.index', 'เพิ่ม / แก้ไข / ปิดใช้งาน', 'เอกสารเดิมยังอ้างอิงคนขายได้'],
            ['คลังและตำแหน่ง', 'warehouse-locations.index', 'เพิ่ม / แก้ไข / ปิดใช้งาน', 'ห้ามลบตำแหน่งที่มีสต๊อก'],
            ['ผู้ใช้และสิทธิ์', 'users.index', 'เพิ่ม / แก้ไข / ปิดใช้งาน', 'เก็บ Audit และสิทธิ์แยกหน้าที่'],
            ['บัญชีธนาคาร', 'bank-accounts.index', 'เพิ่ม / แก้ไข / ปิดใช้งาน', 'รายการกระทบยอดเดิมไม่หาย'],
            ['ผังบัญชี', 'chart-of-accounts.index', 'เพิ่ม / แก้ไข / ปิดใช้งาน', 'บัญชีที่มีรายการแล้วห้ามลบ'],
            ['โปรโมชั่น / Flash sale', 'promotions.index', 'เพิ่ม / แก้ไข / ปิดใช้งาน', 'รักษาประวัติกติกาที่เคยขาย'],
            ['ตั้งค่าระบบและ POS', 'settings.index', 'แก้ไข / ออก Token / ออกรุ่น POS', 'ตั้งค่ากลางของทุกสาขา'],
        ];
    }

    private function documentModules(): array
    {
        return [
            ['POS / ขายสด', 'pos.index', 'สร้าง → ชำระ → ยกเลิกในกะ หรือรับคืน', 'ยกเลิกแล้วคืน Stock และกลับ GL ห้ามลบประวัติ'],
            ['ขายเชื่อ / ลูกหนี้', 'bookings.index', 'สร้างร่าง → ยืนยันขาย → รับชำระ', 'หลังยืนยันต้องใช้ใบลดหนี้/รับคืน ไม่แก้ยอดเดิม'],
            ['ใบเสนอราคา', 'quotations.index', 'สร้าง → เปลี่ยนสถานะ → แปลงเป็นขาย', 'แก้ได้ก่อนแปลง เอกสารอ้างอิงยังอยู่'],
            ['ซื้อ / รับสินค้า', 'purchases.index', 'รับเข้า → เปิด AP → ลง GL', 'หลังรับแล้วต้องคืนซื้อ/กลับรายการ ไม่ลบ Lot'],
            ['ใบสั่งซื้อ', 'purchase-orders.index', 'สร้าง → อนุมัติ → สั่ง → รับบางส่วน/ครบ → ยกเลิก', 'แก้รายการก่อนอนุมัติเท่านั้น'],
            ['โอนคลัง', 'stock-transfers.index', 'ขอ → อนุมัติ → ส่ง → รับ', 'ปฏิเสธได้ก่อนตัด Stock; หลังรับใช้โอนกลับ'],
            ['ปรับสต๊อก', 'stock-adjustments.index', 'สร้างร่าง → ตรวจ → อนุมัติ/ปฏิเสธ', 'ผู้จัดทำอนุมัติตนเองไม่ได้'],
            ['ตรวจนับ', 'stock-counts.index', 'สร้าง → นับ → ส่งตรวจ → Post', 'หลัง Post ใช้รอบใหม่แก้ผลต่าง'],
            ['เบิก/ของเสีย', 'stock-issues.index', 'สร้าง → อนุมัติ/ปฏิเสธ → ตัด Lot', 'เอกสารอนุมัติแล้วห้ามลบ'],
            ['ผลิต / แปรรูป', 'production.index', 'สูตร → ใบสั่ง → ตัดวัตถุดิบ → รับผลผลิต', 'ต้นทุนผูก Batch และ Lot lineage'],
            ['วางบิล / รับชำระ', 'billing-notes.index', 'สร้าง → ส่ง → รับชำระ / ยกเลิก', 'ยอดต้องตรง Open item'],
            ['เพิ่มหนี้ / ลดหนี้', 'credit-debit-notes.index', 'ร่าง → อนุมัติ/ปฏิเสธ → ลง AR/GL', 'ใช้แก้เอกสารเดิมโดยไม่ลบประวัติ'],
            ['ค่าใช้จ่ายสาขา', 'monthly-accounting.index', 'บันทึก → ตรวจ Statement/VAT/WHT → ส่งออก', 'แก้ก่อนปิดงวด'],
            ['ภาษีและงวดบัญชี', 'accounting-periods.index', 'ตรวจ → ปิดงวด → เปิดงวดเมื่อได้รับอนุมัติ', 'งวดปิดห้ามแก้ Document และ GL'],
        ];
    }
}
