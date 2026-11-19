<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Str;

final class MenuVisibility
{
    public const REQUIRED = ['dashboard', 'portal', 'apps.launcher', 'settings.index', 'settings.menus', 'settings.menus.save', 'system.status', 'login', 'login.attempt', 'logout', 'password.change', 'password.update', 'mfa.*', 'profile.*'];

    public static function key(array $item): string
    {
        return $item['route'].(isset($item['queryCategory']) ? '?category='.$item['queryCategory'] : '');
    }

    public static function protected(string $route): bool
    {
        return Str::is(self::REQUIRED, $route);
    }

    public static function hidden(): array
    {
        $attributes = request()->attributes;
        if (!$attributes->has('erp_hidden_menus')) {
            $attributes->set('erp_hidden_menus', json_decode(AppSetting::get('hidden_menus', '[]'), true) ?: []);
        }
        return $attributes->get('erp_hidden_menus');
    }

    public static function blocked(string $route, array $params = []): bool
    {
        if (self::protected($route) || Str::is(['pos.download', 'pos.release.*'], $route)) {
            return false;
        }
        $hidden = null;
        $best = -1;
        $blocked = false;
        foreach (ErpMenu::all() as $section) {
            foreach ($section['items'] as $item) {
                $category = $item['queryCategory'] ?? null;
                if ($category && ($params['category'] ?? null) !== $category) {
                    continue;
                }
                foreach ([$item['route'], $item['pattern'], ...(array) ($item['extraPattern'] ?? [])] as $pattern) {
                    if (! Str::is($pattern, $route)) {
                        continue;
                    }
                    $score = strlen(rtrim($pattern, '*')) + ($pattern === $route ? 100 : 0) + ($category ? 200 : 0);
                    if ($score > $best) {
                        $best = $score;
                        $hidden ??= self::hidden();
                        $blocked = in_array(self::key($item), $hidden, true);
                    }
                }
            }
        }

        // Closing the report centre also closes its direct category URLs.
        return $blocked || ($route === 'reports.index' && in_array('reports.index', $hidden ?? [], true));
    }
}
