<?php

namespace App\Http\Controllers;

use App\Support\ErpMockData;
use Illuminate\View\View;

/**
 * หน้าตัวอย่างการออกแบบระบบหลังบ้าน — อ่านจาก ErpMockData เท่านั้น
 *
 * ไม่แตะฐานข้อมูลโดยตั้งใจ ทั้งคลาสนี้ไม่มี query สักบรรทัด จึงเปิดดูได้
 * โดยไม่มีทางกระทบข้อมูลจริง และไม่ต้องกังวลว่าจะไปชนกับหน้าที่ใช้งานอยู่
 */
class ErpMockupController extends Controller
{
    public function launcher(): View
    {
        return view('erp-mockup.launcher', ['apps' => ErpMockData::apps()]);
    }

    public function dashboard(): View
    {
        return view('erp-mockup.dashboard', [
            'kpis' => ErpMockData::kpiSummary(),
            'branches' => ErpMockData::branches(),
            'topProducts' => ErpMockData::topProducts(),
            'warnings' => ErpMockData::stockWarnings(),
            'posOrders' => array_slice(ErpMockData::posOrders(), 0, 5),
            'activities' => ErpMockData::activities(),
        ]);
    }

    public function products(): View
    {
        return view('erp-mockup.products.index', ['products' => ErpMockData::products()]);
    }

    public function productForm(): View
    {
        return view('erp-mockup.products.form', ['activities' => ErpMockData::activities()]);
    }

    public function posOrders(): View
    {
        $orders = ErpMockData::posOrders();

        return view('erp-mockup.pos-orders.index', [
            'orders' => $orders,
            'kpis' => [
                ['label' => 'บิลวันนี้', 'value' => (string) count($orders), 'sub' => 'ทุกสาขา', 'delta' => null, 'tone' => 'primary', 'icon' => 'bi-receipt'],
                ['label' => 'ซิงค์แล้ว', 'value' => (string) count(array_filter($orders, fn ($o) => $o['sync'] === 'synced')), 'sub' => 'บิล', 'delta' => null, 'tone' => 'up', 'icon' => 'bi-check-circle'],
                ['label' => 'รอซิงค์', 'value' => (string) count(array_filter($orders, fn ($o) => $o['sync'] === 'pending')), 'sub' => 'บิล', 'delta' => null, 'tone' => 'warn', 'icon' => 'bi-clock'],
                ['label' => 'ซิงค์ล้มเหลว', 'value' => (string) count(array_filter($orders, fn ($o) => $o['sync'] === 'failed')), 'sub' => 'ต้องตาม', 'delta' => null, 'tone' => 'danger', 'icon' => 'bi-exclamation-triangle'],
                ['label' => 'ยกเลิก', 'value' => (string) count(array_filter($orders, fn ($o) => $o['sync'] === 'voided')), 'sub' => 'บิล', 'delta' => null, 'tone' => 'info', 'icon' => 'bi-slash-circle'],
            ],
        ]);
    }

    public function inventory(): View
    {
        return view('erp-mockup.inventory.overview', ['cards' => ErpMockData::inventoryCards()]);
    }

    public function purchase(): View
    {
        return view('erp-mockup.purchase.index', ['columns' => ErpMockData::purchaseOrders()]);
    }
}
