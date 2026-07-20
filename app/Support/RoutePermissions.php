<?php

namespace App\Support;

/**
 * Maps route-name prefixes to the permission codes seeded in the BPlus-style
 * role set. Used by ErpAuthorize (blocks the request) and the layout menu
 * (hides items the user can't open) so both always agree.
 * Longest prefix wins; unmapped routes only require being logged in.
 */
class RoutePermissions
{
    private const MAP = [
        // POS + งานหน้าร้าน: เห็นหน้า POS = pos.use (แคชเชียร์/ผจก.สาขา/ผู้บริหาร)
        // แต่เปิดกะ+คิดเงิน = pos.sell (แคชเชียร์เท่านั้น) - longest prefix ชนะ
        'pos.' => 'pos.use',
        'pos.checkout' => 'pos.sell',
        'pos.shift.open' => 'pos.sell',
        'pos.receipts.void' => 'pos.void',
        'bplus.pos-preparation' => 'pos.use',
        'bplus.pos-workbench' => 'pos.use',

        // งานขาย / เอกสารขาย / ลูกค้า
        'bookings.' => 'sales.manage',
        'cash-sales.' => 'sales.manage',
        'sale-returns.' => 'sales.manage',
        'sales.' => 'sales.manage',
        'documents.' => 'sales.manage',
        'document-books.' => 'settings.manage',
        'pos-import.' => 'sales.manage',
        'customers.' => 'sales.manage',
        'customers.credit-limit.approve' => 'finance.credit.approve',
        'customers.credit-limit.reject' => 'finance.credit.approve',
        'billing-notes.' => 'sales.manage',
        'credit-debit-notes.' => 'sales.manage',
        'credit-debit-notes.approve' => 'finance.note.approve',
        'credit-debit-notes.reject' => 'finance.note.approve',
        'quotations.' => 'sales.manage',

        // คลังสินค้า / ผลิต
        // คลังมือถือ: เปิดหน้า+เช็คสต๊อก = stock.manage, ส่วนบันทึกรับเข้า/รับ PO
        // สร้าง "ใบซื้อ" จึงใช้สิทธิ์จัดซื้อ (longest-prefix ชนะ)
        'wh.' => 'stock.manage',
        'wh.receive' => 'purchasing.manage',
        'wh.purchase-orders' => 'purchasing.manage',
        'stock-transfers.' => 'stock.manage',
        'stock-transfers.request' => 'stock.request', // พนักงานสาขาขอโอน (longest-prefix)
        'stock-adjustments.' => 'stock.manage',
        'stock-adjustments.approve' => 'stock.adjust.approve',
        'stock-adjustments.reject' => 'stock.adjust.approve',
        'stock-counts.' => 'stock.manage',
        'stock-issues.' => 'stock.manage',
        'stock-issues.approve' => 'stock.damage.approve',
        'stock-issues.reject' => 'stock.damage.approve',
        'stock-transforms.' => 'stock.manage',
        'production.' => 'stock.manage',
        'warehouse-locations.' => 'stock.manage',

        // จัดซื้อ
        'purchases.' => 'purchasing.manage',
        'purchase-orders.' => 'purchasing.manage',
        'purchase-orders.approve' => 'purchasing.approve',
        'suppliers.' => 'purchasing.manage',
        'bplus.purchase-planning' => 'purchasing.manage',

        // การเงิน / บัญชี
        'chart-of-accounts.' => 'finance.manage',
        'accounting-periods.' => 'finance.manage',
        'monthly-accounting.' => 'finance.manage',
        'tax-compliance.' => 'finance.manage',
        'gl-journals.' => 'finance.manage',
        'financial-statements.' => 'finance.manage',
        'fixed-assets.' => 'finance.manage',
        'bank-accounts.' => 'finance.manage',
        'payments.' => 'finance.manage',
        'cheques.' => 'finance.manage',
        'bplus.finance' => 'finance.manage',
        'bplus.tax' => 'finance.manage',
        'bplus.cash-books' => 'finance.manage',
        'bplus.vat-rates' => 'finance.manage',
        'bplus.approvals' => 'finance.manage',

        // ข้อมูลตั้งต้น / ราคา / โปรโมชั่น
        'products.' => 'masterdata.manage',
        'products.lots.quality-checks' => 'inventory.quality.manage',
        'products.lots.recalls' => 'inventory.quality.manage',
        'products.recall-contacts' => 'inventory.quality.manage',
        'product-units.' => 'masterdata.manage',
        'price-tables.' => 'masterdata.manage',
        'scale-prices.' => 'masterdata.manage',
        'promotions.' => 'masterdata.manage',
        'flash-sales.' => 'masterdata.manage',
        'price-tags.' => 'masterdata.manage',
        'discount-cards.' => 'masterdata.manage',
        'member-points.' => 'masterdata.manage',
        'qty-promotions.' => 'masterdata.manage',
        'members.' => 'masterdata.manage',
        'salesmen.' => 'masterdata.manage',

        // รายงาน
        'reports.' => 'reports.view',
        'legacy-reports.' => 'reports.view',
        'core-modules.' => 'reports.view',
        'features.' => 'reports.view',
        'management-controls.' => 'management.view',
        'management-controls.cost-centers' => 'budget.manage',
        'management-controls.budgets' => 'budget.manage',
        'management-controls.purchase-plans' => 'purchasing.manage',
        'management-controls.attendance' => 'payroll.manage',
        'management-controls.payroll' => 'payroll.manage',
        'management-controls.ecommerce' => 'ecommerce.sync',

        // ระบบ
        'users.' => 'users.manage',
        'employees.' => 'users.manage',
        'organizational-units.' => 'users.manage',
        'settings.' => 'settings.manage',
        'operations.' => 'settings.manage',
        'mfa.' => null,
        'line-integrations.' => 'settings.manage',
        'ecommerce-channels.' => 'settings.manage',
        'bplus.qr-payments' => 'settings.manage',
        'bplus.show-price' => 'settings.manage',
    ];

    public static function resolve(?string $routeName): ?string
    {
        if (! $routeName) {
            return null;
        }

        $best = null;
        $bestLen = -1;
        foreach (self::MAP as $prefix => $permission) {
            if (str_starts_with($routeName, $prefix) && strlen($prefix) > $bestLen) {
                $best = $permission;
                $bestLen = strlen($prefix);
            }
        }

        return $best;
    }
}
