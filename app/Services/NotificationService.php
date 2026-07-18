<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * แจ้งเตือนกระดิ่งบน header: แต่ละส่วนงานเห็นเฉพาะเรื่องตามภาระหน้าที่
 * (กรองด้วย permission ของ user) - คืนเฉพาะรายการที่มีจำนวน > 0
 */
class NotificationService
{
    /** @return array<int, array{icon:string,color:string,label:string,count:int,url:string}> */
    public function forUser(User $user): array
    {
        $items = [];

        // คลังสินค้า: ของโอนเข้าวันนี้ / สต๊อกติดลบ / ใบสั่งซื้อรอรับของ
        if ($user->hasPermission('stock.manage')) {
            $this->push($items, 'bi-arrow-left-right', '#7c3aed', 'ใบโอนสินค้าวันนี้',
                DB::table('documents as d')->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
                    ->where('dt.code', 'STOCK_TRANSFER')->whereDate('d.doc_date', now())->count(),
                route('stock-transfers.index'));

            $this->push($items, 'bi-exclamation-triangle-fill', '#dc2626', 'สต๊อกติดลบ',
                DB::table('stock_balances')->where('on_hand_qty', '<', 0)->count(),
                route('reports.index', ['category' => 'inventory', 'report' => 'stock_alerts']));

            $this->push($items, 'bi-box-arrow-in-down', '#059669', 'ใบสั่งซื้อรอรับของเข้าคลัง',
                DB::table('purchase_orders')->where('status', 'ordered')->count(),
                route('purchase-orders.index', ['status' => 'ordered']));
        }

        // จัดซื้อ: ใบขอซื้อรออนุมัติ
        if ($user->hasPermission('purchasing.manage')) {
            $this->push($items, 'bi-cart-plus-fill', '#d97706', 'ใบขอซื้อรออนุมัติ',
                DB::table('purchase_orders')->where('status', 'requested')->count(),
                route('purchase-orders.index', ['status' => 'requested']));
        }

        // งานขาย: ใบจองค้างแปลง / ใบเสนอราคารอลูกค้าตอบ
        if ($user->hasPermission('sales.manage')) {
            $this->push($items, 'bi-receipt-cutoff', '#2563eb', 'ใบจองค้างแปลงขาย',
                DB::table('sale_bookings')->where('status', 'pending')->count(),
                route('bookings.index'));

            $this->push($items, 'bi-file-earmark-text', '#64748b', 'ใบเสนอราคารอลูกค้าตอบ',
                DB::table('quotations')->where('status', 'open')->count(),
                route('quotations.index', ['status' => 'open']));
        }

        // การเงิน: ลูกหนี้เกินกำหนด / เช็ครับในมือรอฝาก
        if ($user->hasPermission('finance.manage')) {
            $this->push($items, 'bi-alarm-fill', '#db2777', 'ลูกหนี้เกินกำหนดชำระ',
                DB::table('customer_open_items')->whereIn('status', ['open', 'partial'])
                    ->where('due_date', '<', now()->toDateString())->count(),
                route('reports.index', ['category' => 'ar', 'report' => 'overdue_customers']));

            $this->push($items, 'bi-journal-check', '#0f766e', 'เช็ครับในมือรอนำฝาก',
                DB::table('cheques')->where('direction', 'in')->where('status', 'on_hand')->count(),
                route('cheques.index'));
        }

        // ผจก./ผู้บริหาร: บิล POS ถูกยกเลิกวันนี้ (ตรวจสอบ)
        if ($user->hasPermission('pos.void')) {
            $this->push($items, 'bi-x-octagon-fill', '#dc2626', 'บิล POS ถูกยกเลิกวันนี้',
                DB::table('pos_receipts')->whereNotNull('voided_at')->whereDate('voided_at', now())->count(),
                route('reports.index', ['category' => 'audit', 'report' => 'void_bill_history']));
        }

        return $items;
    }

    private function push(array &$items, string $icon, string $color, string $label, int $count, string $url): void
    {
        if ($count > 0) {
            $items[] = compact('icon', 'color', 'label', 'count', 'url');
        }
    }
}
