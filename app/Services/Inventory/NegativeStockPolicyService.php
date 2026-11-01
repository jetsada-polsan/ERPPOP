<?php

namespace App\Services\Inventory;

use App\Models\AppSetting;
use App\Models\Product;

class NegativeStockPolicyService
{
    public const GLOBAL_KEY = 'negative_stock_global_policy';
    public const CATEGORY_KEY = 'negative_stock_category_policies';

    public function globalPolicy(): string
    {
        return AppSetting::get(self::GLOBAL_KEY, 'allow') === 'block' ? 'block' : 'allow';
    }

    /** @return array<int, string> */
    public function categoryPolicies(): array
    {
        $value = json_decode(AppSetting::get(self::CATEGORY_KEY, '{}') ?? '{}', true);

        return is_array($value) ? collect($value)->filter(fn ($policy) => in_array($policy, ['allow', 'block'], true))->mapWithKeys(fn ($policy, $id) => [(int) $id => $policy])->all() : [];
    }

    public function policyFor(Product $product): string
    {
        $categoryPolicies = $this->categoryPolicies();
        $categoryId = (int) ($product->product_category_id ?? 0);

        if (isset($categoryPolicies[$categoryId])) {
            return $categoryPolicies[$categoryId];
        }

        if ($product->negative_stock_policy === 'block') {
            return 'block';
        }

        return $this->globalPolicy();
    }

    public function save(string $globalPolicy, array $categoryPolicies): void
    {
        AppSetting::set(self::GLOBAL_KEY, $globalPolicy);
        AppSetting::set(self::CATEGORY_KEY, json_encode($categoryPolicies, JSON_UNESCAPED_UNICODE));
    }
}
