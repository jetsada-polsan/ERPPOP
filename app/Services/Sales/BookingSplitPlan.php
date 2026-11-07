<?php

namespace App\Services\Sales;

use App\Support\DecimalMath;
use Illuminate\Support\Collection;
use RuntimeException;

/** Read-only preparation of an explicit item-to-book allocation. */
final class BookingSplitPlan
{
    /**
     * @param Collection<int, object> $items persisted booking lines with product loaded
     * @param array<int|string, int|string> $assignments line id => book id
     * @param Collection<int, object> $books active credit-sale books keyed by id
     * @return array<int, array{book: object, items: Collection, total: string}>
     */
    public function prepare(Collection $items, array $assignments, Collection $books): array
    {
        if ($items->isEmpty()) {
            throw new RuntimeException('ใบจองนี้ไม่มีรายการสินค้า');
        }
        $sourceIds = $items->map(fn ($item) => (string) $item->id)->all();
        $selectedIds = array_map('strval', array_keys($assignments));
        sort($sourceIds);
        sort($selectedIds);
        if ($sourceIds !== $selectedIds) {
            throw new RuntimeException('ต้องเลือกเล่มให้ครบทุกรายการ และห้ามเพิ่มรายการที่ไม่อยู่ในใบจอง');
        }

        $groups = [];
        foreach ($items as $item) {
            $bookId = (int) $assignments[$item->id];
            $book = $books->get($bookId);
            if (! $book || ! $book->is_active) {
                throw new RuntimeException('เล่มขายที่เลือกไม่พร้อมใช้งาน');
            }
            if (! $item->product) {
                throw new RuntimeException('ไม่พบสินค้าของรายการใบจอง');
            }
            if ($book->no_vat && $item->product->is_vat) {
                throw new RuntimeException('สินค้าที่กำหนดคิด VAT ไม่สามารถใส่เล่มที่ไม่คำนวณ VAT');
            }
            if (! isset($groups[$bookId])) {
                $groups[$bookId] = ['book' => $book, 'items' => collect(), 'total' => '0.00000000'];
            }
            $groups[$bookId]['items']->push($item);
            $groups[$bookId]['total'] = DecimalMath::add($groups[$bookId]['total'], DecimalMath::multiply($item->qty, $item->unit_price));
        }

        return $groups;
    }
}
