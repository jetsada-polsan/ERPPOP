<?php

namespace App\Services\Sales;

use App\Models\AuditLog;
use App\Models\SaleBooking;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * บันทึกการส่งของตามใบจอง
 *
 * ก่อนหน้านี้ `delivery_status` ถูกตั้งเป็น pending ตอนสร้างใบจองแล้วไม่มีอะไรเปลี่ยนอีกเลย
 * รายงาน "ใบจองครบกำหนด/เกินกำหนดส่ง" จึงจะเห็นทุกใบค้างตลอดกาล ต่อให้ส่งของไปแล้ว
 *
 * กติกา:
 *  - ใบจองแบบรับเองที่สาขาไม่มีสถานะส่งของ บันทึกไม่ได้
 *  - ส่งครบแล้วบันทึกซ้ำไม่ได้ กันกดสองรอบแล้ววันที่ส่งเปลี่ยน
 *  - ยกเลิกการส่งได้เฉพาะที่ยังส่งไม่ครบ
 *  - ทุกการเปลี่ยนสถานะเขียน audit log เพราะเป็นข้อมูลที่ใช้วัดว่าส่งตรงเวลาไหม
 */
class BookingDeliveryService
{
    /** @param  SaleBooking::DELIVERY_*  $status */
    public function record(SaleBooking $booking, string $status, ?string $note = null): SaleBooking
    {
        if (! in_array($status, [SaleBooking::DELIVERY_PARTIAL, SaleBooking::DELIVERY_DELIVERED, SaleBooking::DELIVERY_CANCELLED], true)) {
            throw new RuntimeException('สถานะการส่งไม่ถูกต้อง');
        }

        return DB::transaction(function () use ($booking, $status, $note) {
            $locked = SaleBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDelivery()) {
                throw new RuntimeException('ใบจองนี้เป็นแบบรับเองที่สาขา ไม่มีการส่งของให้บันทึก');
            }
            if ($locked->delivery_status === SaleBooking::DELIVERY_DELIVERED) {
                throw new RuntimeException('ใบจองนี้บันทึกส่งครบแล้ว');
            }
            if ($locked->delivery_status === SaleBooking::DELIVERY_CANCELLED) {
                throw new RuntimeException('การส่งของใบจองนี้ถูกยกเลิกไปแล้ว');
            }

            $previous = $locked->delivery_status;
            $locked->update([
                'delivery_status' => $status,
                // วันเวลาส่งจริงบันทึกเฉพาะตอนส่งครบ ส่งบางส่วนยังไม่ถือว่าจบ
                'delivered_at' => $status === SaleBooking::DELIVERY_DELIVERED ? now() : null,
            ]);

            AuditLog::create([
                'user_id' => auth()->id(),
                'branch_id' => $locked->document?->branch_id,
                'action' => 'booking_delivery_'.$status,
                'table_name' => 'sale_bookings',
                'record_id' => $locked->id,
                'old_values' => ['delivery_status' => $previous],
                'new_values' => [
                    'delivery_status' => $status,
                    'delivered_at' => $locked->delivered_at?->toIso8601String(),
                    'note' => $note,
                ],
            ]);

            return $locked->fresh();
        });
    }

    /** ใบจองที่ยังต้องตามส่ง — ฐานของรายงาน P0 "ใบจองครบกำหนด/เกินกำหนดส่ง" */
    public function outstanding(?int $branchId = null)
    {
        return SaleBooking::query()
            ->with('document')
            ->where('fulfillment_type', SaleBooking::FULFILLMENT_DELIVERY)
            ->whereIn('delivery_status', SaleBooking::DELIVERY_OUTSTANDING)
            ->when($branchId, fn ($query) => $query->whereHas('document', fn ($d) => $d->where('branch_id', $branchId)))
            ->orderBy('delivery_due_at');
    }
}
