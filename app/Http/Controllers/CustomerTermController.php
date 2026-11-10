<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerTerm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerTermController extends Controller
{
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $this->validateTerm($request);
        $data['is_default'] = $request->boolean('is_default');

        $customer->terms()->create($data);

        return redirect()->route('customers.show', ['customer' => $customer, 'tab' => 'terms'])
            ->with('success', 'เพิ่มข้อตกลงลูกค้าเรียบร้อยแล้ว');
    }

    public function update(Request $request, Customer $customer, CustomerTerm $term): RedirectResponse
    {
        abort_unless($term->customer_id === $customer->id, 404);

        $data = $this->validateTerm($request);
        $data['is_default'] = $request->boolean('is_default');

        $term->update($data);

        return redirect()->route('customers.show', ['customer' => $customer, 'tab' => 'terms'])
            ->with('success', 'บันทึกข้อตกลงลูกค้าเรียบร้อยแล้ว');
    }

    public function destroy(Request $request, Customer $customer, CustomerTerm $term): RedirectResponse
    {
        abort_unless($term->customer_id === $customer->id, 404);

        $term->delete();

        return redirect()->route('customers.show', ['customer' => $customer, 'tab' => 'terms'])
            ->with('success', 'ลบข้อตกลงลูกค้าเรียบร้อยแล้ว');
    }

    private function validateTerm(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'price_table_id' => ['nullable', 'integer', 'exists:price_tables,id'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'trade_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cash_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_default' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'name.required' => 'กรุณากรอกชื่อข้อตกลง',
            'name.string' => 'ชื่อข้อตกลงต้องเป็นตัวอักษร',
            'name.max' => 'ชื่อข้อตกลงต้องมีความยาวไม่เกิน 255 ตัวอักษร',
            'start_date.date' => 'วันที่เริ่มต้นไม่ถูกต้อง',
            'end_date.date' => 'วันที่สิ้นสุดไม่ถูกต้อง',
            'end_date.after_or_equal' => 'วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่มต้น',
            'price_table_id.exists' => 'ไม่พบตารางราคาที่เลือก',
            'credit_days.integer' => 'จำนวนวันเครดิตต้องเป็นตัวเลขจำนวนเต็ม',
            'credit_days.min' => 'จำนวนวันเครดิตต้องไม่น้อยกว่า 0 วัน',
            'credit_days.max' => 'จำนวนวันเครดิตต้องไม่เกิน 9999 วัน',
            'trade_discount_percent.numeric' => 'ส่วนลดการค้า (%) ต้องเป็นตัวเลข',
            'trade_discount_percent.min' => 'ส่วนลดการค้า (%) ต้องไม่น้อยกว่า 0%',
            'trade_discount_percent.max' => 'ส่วนลดการค้า (%) ต้องไม่เกิน 100%',
            'cash_discount_percent.numeric' => 'ส่วนลดเงินสด (%) ต้องเป็นตัวเลข',
            'cash_discount_percent.min' => 'ส่วนลดเงินสด (%) ต้องไม่น้อยกว่า 0%',
            'cash_discount_percent.max' => 'ส่วนลดเงินสด (%) ต้องไม่เกิน 100%',
            'notes.max' => 'หมายเหตุต้องมีความยาวไม่เกิน 2000 ตัวอักษร',
        ]);
    }
}
