<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    // มาตรฐานรหัสผ่าน: อย่างน้อย 8 ตัว มีตัวพิมพ์เล็ก/ใหญ่และตัวเลข
    // เก็บแบบ bcrypt hash ผ่าน casts 'password' => 'hashed' ของ User model
    private function passwordRule(): Password
    {
        return Password::min(8)->letters()->mixedCase()->numbers();
    }

    public function index(): View
    {
        $users = User::with(['branch', 'salesman', 'roles'])->orderBy('username')->paginate(50);
        $branches = Branch::orderBy('code')->get(['id', 'code', 'name_th']);
        $roles = Role::with('permissions')->orderBy('id')->get();
        $salesmen = \App\Models\Salesman::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);

        return view('users.index', compact('users', 'branches', 'roles', 'salesmen'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'salesman_id' => ['nullable', 'integer', 'exists:salesmen,id'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'password' => ['required', 'confirmed', $this->passwordRule()],
        ], [
            'username.unique' => 'ชื่อผู้ใช้นี้ถูกใช้แล้ว',
            'username.alpha_dash' => 'ชื่อผู้ใช้ใช้ได้เฉพาะ a-z 0-9 ขีดกลาง/ขีดล่าง',
            'name.required' => 'กรุณากรอกชื่อ-นามสกุลให้ครบ',
            'password.confirmed' => 'ยืนยันรหัสผ่านไม่ตรงกัน',
            'role_ids.required' => 'กรุณาเลือกบทบาท/สิทธิ์อย่างน้อย 1 อัน',
        ]);

        $user = User::create([
            'username' => $data['username'],
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'position' => $data['position'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'salesman_id' => $data['salesman_id'] ?? null,
            'password' => $data['password'],
            'is_active' => true,
            'must_change_password' => true,
        ]);
        $user->roles()->sync($data['role_ids']);

        return redirect()->route('users.index')->with('success', "เพิ่มผู้ใช้ {$user->username} แล้ว");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'salesman_id' => ['nullable', 'integer', 'exists:salesmen,id'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'password' => ['nullable', 'confirmed', $this->passwordRule()],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'กรุณากรอกชื่อ-นามสกุลให้ครบ',
            'password.confirmed' => 'ยืนยันรหัสผ่านไม่ตรงกัน',
            'role_ids.required' => 'กรุณาเลือกบทบาท/สิทธิ์อย่างน้อย 1 อัน',
        ]);

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'position' => $data['position'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'salesman_id' => $data['salesman_id'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
            $user->must_change_password = true;
        }
        $user->save();
        $user->roles()->sync($data['role_ids']);

        return redirect()->route('users.index')->with('success', 'บันทึกข้อมูลผู้ใช้แล้ว');
    }
}
