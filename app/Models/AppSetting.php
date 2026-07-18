<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    private const CACHE_KEY = 'app_settings.all';

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());

        $value = $all[$key] ?? null;

        return ($value !== null && $value !== '') ? $value : $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    // Company info helpers: settings win, config/company.php is the fallback.
    public static function company(string $field): ?string
    {
        return static::get('company_'.$field, config('company.'.$field));
    }

    public static function logoUrl(): ?string
    {
        $path = static::get('logo_path');
        if (! $path) {
            return null;
        }

        // version param กันเบราว์เซอร์แคชรูปเก่าเมื่อไฟล์ถูกแก้ทับชื่อเดิม
        $file = public_path($path);
        $version = is_file($file) ? filemtime($file) : 0;

        return asset($path).'?v='.$version;
    }
}
