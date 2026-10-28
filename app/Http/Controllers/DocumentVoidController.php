<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\Accounting\DocumentVoidService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DocumentVoidController extends Controller
{
    public function store(Request $request, Document $document, DocumentVoidService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('documents.void'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $service->void($document, $data['reason'], (int) $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', 'ยกเลิก '.$document->doc_number.' แล้ว กลับรายการสต๊อก/บัญชีเรียบร้อย');
    }
}
