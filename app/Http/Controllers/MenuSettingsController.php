<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Support\ErpMenu;
use App\Support\MenuVisibility;
use Illuminate\Http\Request;

class MenuSettingsController extends Controller
{
    public function index()
    {
        return view('settings.menus', ['sections' => ErpMenu::all(), 'hidden' => MenuVisibility::hidden()]);
    }

    public function save(Request $request)
    {
        $data = $request->validate(['enabled' => 'sometimes|array', 'enabled.*' => 'string', 'sections' => 'sometimes|array', 'sections.*' => 'string', 'action' => 'required|in:save,restore']);
        $hidden = [];
        if ($data['action'] === 'save') {
            foreach (ErpMenu::all() as $section) {
                $groupEnabled = in_array($section['label'], $data['sections'] ?? [], true);
                foreach ($section['items'] as $item) {
                    $key = MenuVisibility::key($item);
                    if (! MenuVisibility::protected($item['route']) && (! $groupEnabled || ! in_array($key, $data['enabled'] ?? [], true))) {
                        $hidden[] = $key;
                    }
                }
            }
        }
        AppSetting::set('hidden_menus', json_encode(array_values(array_unique($hidden)), JSON_THROW_ON_ERROR));

        $request->attributes->remove('erp_hidden_menus');

        return back()->with('success', 'บันทึกการเปิด/ปิดเมนูแล้ว');
    }
}
