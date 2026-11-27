<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    private const CACHE_KEY = 'app_settings.all';

    private const REQUEST_ATTR = 'app_settings.all';

    protected static function booted(): void
    {
        static::saved(static fn () => static::clearCache());
        static::deleted(static fn () => static::clearCache());
    }

    /**
     * @return array<string, ?string>
     */
    private static function allCached(): array
    {
        $attributes = app()->bound('request') ? request()->attributes : null;
        if ($attributes !== null && $attributes->has(self::REQUEST_ATTR)) {
            return $attributes->get(self::REQUEST_ATTR);
        }

        $all = Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());

        $attributes?->set(self::REQUEST_ATTR, $all);

        return $all;
    }

    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);

        if (app()->bound('request')) {
            request()->attributes->remove(self::REQUEST_ATTR);
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = static::allCached();

        $value = $all[$key] ?? null;

        return ($value !== null && $value !== '') ? $value : $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        static::clearCache();
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
        if (str_starts_with($path, 'storage/')) {
            $storagePath = substr($path, strlen('storage/'));
            $file = Storage::disk('public')->path($storagePath);
            $url = asset($path);
        } else {
            $file = public_path($path);
            $url = asset($path);
        }
        $version = is_file($file) ? filemtime($file) : 0;

        return $url.'?v='.$version;
    }
}
