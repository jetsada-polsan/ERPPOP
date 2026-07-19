<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name_th', 'name_en', 'account_type', 'default_role'])]
class ChartOfAccount extends Model
{
    public $timestamps = false;

    public const TYPES = [
        'asset' => 'สินทรัพย์',
        'liability' => 'หนี้สิน',
        'equity' => 'ส่วนของเจ้าของ',
        'revenue' => 'รายได้',
        'expense' => 'ค่าใช้จ่าย',
    ];

    // Posting targets for automatic GL journal entries (see GlPostingService).
    public const ROLE_CASH = 'cash';

    public const ROLE_BANK = 'bank';

    public const ROLE_AR = 'ar';

    public const ROLE_AP = 'ap';

    public const ROLE_INVENTORY = 'inventory';

    public const ROLE_VAT_INPUT = 'vat_input';

    public const ROLE_VAT_OUTPUT = 'vat_output';

    public const ROLE_SALES_REVENUE = 'sales_revenue';

    public const ROLE_SALES_RETURN = 'sales_return';

    public const ROLE_COGS = 'cogs';

    public const ROLE_EXPENSE = 'expense';

    public const ROLE_WHT_PAYABLE = 'wht_payable';

    public const ROLE_RETAINED_EARNINGS = 'retained_earnings';

    public const ROLES = [
        self::ROLE_CASH => 'บัญชีเงินสด/รับจ่ายเริ่มต้น',
        self::ROLE_BANK => 'บัญชีเงินฝากธนาคารเริ่มต้น',
        self::ROLE_AR => 'บัญชีลูกหนี้การค้าเริ่มต้น',
        self::ROLE_AP => 'บัญชีเจ้าหนี้การค้าเริ่มต้น',
        self::ROLE_INVENTORY => 'บัญชีสินค้าคงเหลือ',
        self::ROLE_VAT_INPUT => 'บัญชีภาษีซื้อ',
        self::ROLE_VAT_OUTPUT => 'บัญชีภาษีขาย',
        self::ROLE_SALES_REVENUE => 'บัญชีรายได้จากการขาย',
        self::ROLE_SALES_RETURN => 'บัญชีรับคืน/ส่วนลดจ่าย',
        self::ROLE_COGS => 'บัญชีต้นทุนขาย',
        self::ROLE_EXPENSE => 'บัญชีค่าใช้จ่าย',
        self::ROLE_WHT_PAYABLE => 'บัญชีภาษีหัก ณ ที่จ่ายค้างจ่าย',
        self::ROLE_RETAINED_EARNINGS => 'บัญชีกำไรสะสม',
    ];

    public function journalEntries(): HasMany
    {
        return $this->hasMany(GlJournal::class, 'account_id');
    }
}
