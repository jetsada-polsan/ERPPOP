<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * เอกสารเบิก/คืนเบิก/ตัดชำรุด (BPlus manual ch.5): one-sided stock documents.
 * - requisition (DR ใบเบิกสินค้า): stock OUT - เบิกใช้ในสำนักงาน/เพื่อผลิต/สินค้าตัวอย่าง
 * - requisition_return (IR ใบคืนสินค้าจากการเบิก): stock IN - คืนของเบิกที่ใช้ไม่หมด
 * - damage (DD ใบตัดสินค้าชำรุด): stock OUT - ตัดของเสียที่ขาย/แปรรูปไม่ได้
 */
class StockIssueService
{
    public const TYPES = [
        'requisition' => 'STOCK_REQUISITION',
        'requisition_return' => 'STOCK_REQUISITION_RETURN',
        'damage' => 'STOCK_DAMAGE',
    ];

    public const PURPOSES = [
        'office' => 'เบิกใช้ในสำนักงาน',
        'production' => 'เบิกเพื่อการผลิต',
        'sample' => 'เบิกเป็นสินค้าตัวอย่าง',
    ];

    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly FifoStockService $fifo,
    ) {}

    /**
     * @param  array{type:string, branch_id:int, warehouse_location_id:?int, purpose:?string, reference:?string, remark:?string, items: array<int, array{product_id:int, qty:float}>}  $data
     */
    public function create(array $data): Document
    {
        $typeCode = self::TYPES[$data['type']] ?? null;
        if (! $typeCode) {
            throw new RuntimeException('ประเภทเอกสารไม่ถูกต้อง');
        }
        if (empty($data['items'])) {
            throw new RuntimeException('ต้องมีรายการสินค้าอย่างน้อย 1 รายการ');
        }

        $branch = Branch::findOrFail($data['branch_id']);
        $locationId = $data['warehouse_location_id'] ?? $branch->default_warehouse_location_id;
        if (! $locationId) {
            throw new RuntimeException("สาขา {$branch->name_th} ยังไม่ได้กำหนดคลังสินค้าเริ่มต้น");
        }

        $isReturn = $data['type'] === 'requisition_return';
        $documentType = DocumentType::where('code', $typeCode)->firstOrFail();

        $remarkParts = [];
        if ($data['type'] === 'requisition' && ! empty($data['purpose'])) {
            $remarkParts[] = self::PURPOSES[$data['purpose']] ?? $data['purpose'];
        }
        if (! empty($data['remark'])) {
            $remarkParts[] = $data['remark'];
        }

        return DB::transaction(function () use ($data, $branch, $locationId, $isReturn, $documentType, $typeCode, $remarkParts) {
            $items = collect($data['items']);

            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $branch->id,
                'doc_number' => $this->numbers->next($typeCode, $branch->id),
                'doc_date' => now()->toDateString(),
                'reference' => $data['reference'] ?? null,
                'status' => 'active',
                'total_items' => $items->count(),
                'total_amount' => 0,
                'remark' => $remarkParts !== [] ? implode(' | ', $remarkParts) : null,
            ]);

            $stockDocument = StockDocument::create([
                'document_id' => $document->id,
                'total_qty' => $items->sum('qty'),
                'total_items' => $items->count(),
            ]);

            $seq = 1;
            foreach ($items as $item) {
                StockDocumentItem::create([
                    'stock_document_id' => $stockDocument->id,
                    'seq' => $seq++,
                    'product_id' => $item['product_id'],
                    'warehouse_location_id' => $locationId,
                    'qty' => $item['qty'],
                ]);

                if ($isReturn) {
                    $this->fifo->receive((int) $item['product_id'], (int) $locationId, (float) $item['qty'], $document->id, 'in');
                } else {
                    $this->fifo->issue(
                        (int) $item['product_id'], (int) $locationId, (float) $item['qty'],
                        $document->id, 'out', allowExpired: $data['type'] === 'damage'
                    );
                }
            }

            return $document->fresh();
        });
    }
}
