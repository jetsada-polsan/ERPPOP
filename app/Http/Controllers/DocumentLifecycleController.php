<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\Documents\DocumentLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DocumentLifecycleController extends Controller
{
    public function submit(Request $request, Document $document, DocumentLifecycleService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('sales.manage'), 403);

        try {
            $service->submit($document, (int) $request->user()->id);
            return back()->with('success', 'ส่งเอกสารเข้าตรวจแล้ว');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function approve(Request $request, Document $document, DocumentLifecycleService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('sales.manage'), 403);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        try {
            $service->approve($document, (int) $request->user()->id, $data['note'] ?? null);
            return back()->with('success', 'อนุมัติเอกสารแล้ว');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reject(Request $request, Document $document, DocumentLifecycleService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('sales.manage'), 403);
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        try {
            $service->reject($document, (int) $request->user()->id, $data['note']);
            return back()->with('success', 'ตีกลับเอกสารแล้ว');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
