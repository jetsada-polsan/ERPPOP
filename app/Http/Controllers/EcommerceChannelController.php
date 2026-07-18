<?php

namespace App\Http\Controllers;

use App\Models\EcommerceChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EcommerceChannelController extends Controller
{
    public function index(): View
    {
        $channels = EcommerceChannel::orderBy('platform')->orderBy('code')->paginate(50);

        return view('ecommerce-channels.index', compact('channels'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateChannel($request);
        EcommerceChannel::create($data);

        return redirect()->route('ecommerce-channels.index')->with('success', "เพิ่มช่องทาง {$data['code']} แล้ว");
    }

    public function update(Request $request, EcommerceChannel $ecommerceChannel): RedirectResponse
    {
        $data = $this->validateChannel($request, $ecommerceChannel->id);
        $ecommerceChannel->update($data);

        return redirect()->route('ecommerce-channels.index')->with('success', 'บันทึกช่องทาง E-Commerce แล้ว');
    }

    private function validateChannel(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:ecommerce_channels,code,'.($ignoreId ?? 'NULL').',id'],
            'name' => ['required', 'string', 'max:150'],
            'platform' => ['required', 'string', 'max:40'],
            'shop_name' => ['nullable', 'string', 'max:150'],
            'sync_status' => ['required', 'string', 'max:30'],
            'last_synced_at' => ['nullable', 'date'],
            'credential_note' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
