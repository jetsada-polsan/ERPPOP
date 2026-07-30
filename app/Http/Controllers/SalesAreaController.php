<?php

namespace App\Http\Controllers;

use App\Models\DocumentBook;
use App\Models\DocumentType;
use App\Models\SalesArea;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesAreaController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateArea($request);
        DB::transaction(function () use ($data) {
            $data['document_book_id'] = $this->syncBookingBook($data);
            SalesArea::create($data);
        });

        return redirect()->route('salesmen.index')->with('success', "เพิ่มสายการขาย {$data['code']} แล้ว");
    }

    public function update(Request $request, SalesArea $salesArea): RedirectResponse
    {
        $data = $this->validateArea($request, $salesArea->id);
        DB::transaction(function () use ($data, $salesArea) {
            $data['document_book_id'] = $this->syncBookingBook($data, $salesArea);
            $salesArea->update($data);
        });

        return redirect()->route('salesmen.index')->with('success', 'บันทึกสายการขายแล้ว');
    }

    private function validateArea(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:sales_areas,code,'.($ignoreId ?? 'NULL').',id'],
            'name' => ['required', 'string', 'max:150'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['area_type'] = 'route';
        $data['branch_id'] = $data['branch_id'] ?? null;
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function syncBookingBook(array $data, ?SalesArea $area = null): int
    {
        $bookingTypeId = DocumentType::where('code', DocumentType::BOOKING)->value('id');

        $book = $area?->documentBook;
        if (! $book) {
            $book = DocumentBook::where('document_type_id', $bookingTypeId)
                ->where('code', $data['code'])
                ->first();
        }

        $book ??= new DocumentBook([
            'document_type_id' => $bookingTypeId,
            'is_default' => false,
        ]);
        $book->fill([
            'code' => $data['code'],
            'name' => $data['name'],
            'prefix' => $data['code'],
            'is_active' => $data['is_active'],
        ])->save();

        return $book->id;
    }
}
