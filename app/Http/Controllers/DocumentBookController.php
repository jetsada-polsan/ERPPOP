<?php

namespace App\Http\Controllers;

use App\Models\DocumentBook;
use App\Models\DocumentType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DocumentBookController extends Controller
{
    // ประเภทเอกสารที่ให้แยกเล่มได้ (เอกสารขาย/ซื้อ/รับคืน/จอง)
    private const BOOKABLE_TYPES = ['CREDIT_SALE', 'CASH_SALE', 'SALE_RETURN', 'PURCHASE', 'BOOKING'];

    public function index(): View
    {
        $books = DocumentBook::with('documentType')->withCount('documents')
            ->orderBy('document_type_id')->orderByDesc('is_default')->orderBy('code')->get();

        $types = DocumentType::whereIn('code', self::BOOKABLE_TYPES)->get();

        return view('document-books.index', compact('books', 'types'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'document_type_id' => ['required', 'integer', 'exists:document_types,id'],
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:150'],
            'prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'is_default' => ['nullable', 'boolean'],
        ], [
            'prefix.regex' => 'คำนำหน้าเลขที่ใช้ได้เฉพาะ A-Z 0-9',
        ]);

        $exists = DocumentBook::where('document_type_id', $data['document_type_id'])->where('code', $data['code'])->exists();
        if ($exists) {
            return back()->withErrors(['code' => 'รหัสเล่มนี้มีอยู่แล้วในประเภทเอกสารนี้'])->withInput();
        }

        $data['is_default'] = $request->boolean('is_default');
        if ($data['is_default']) {
            DocumentBook::where('document_type_id', $data['document_type_id'])->update(['is_default' => false]);
        }
        $data['is_active'] = true;
        DocumentBook::create($data);

        return redirect()->route('document-books.index')->with('success', "เพิ่มเล่มเอกสาร {$data['code']} แล้ว");
    }

    public function update(Request $request, DocumentBook $documentBook): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('is_default') && ! $documentBook->is_default) {
            DocumentBook::where('document_type_id', $documentBook->document_type_id)->update(['is_default' => false]);
        }

        $documentBook->update([
            'name' => $data['name'],
            'is_default' => $request->boolean('is_default'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('document-books.index')->with('success', 'บันทึกเล่มเอกสารแล้ว');
    }
}
