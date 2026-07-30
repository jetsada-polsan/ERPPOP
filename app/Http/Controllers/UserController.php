<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $users = User::with(['branch', 'salesman', 'roles'])
            ->when($q !== '', fn ($query) => $query->where(fn ($where) => $where
                ->whereLike('username', "%{$q}%")
                ->orWhereLike('name', "%{$q}%")
                ->orWhereLike('phone', "%{$q}%")
                ->orWhereLike('position', "%{$q}%")
            ))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($status === 'must_change', fn ($query) => $query->where('must_change_password', true))
            ->orderBy('username')
            ->paginate(50)
            ->withQueryString();
        $branches = Branch::orderBy('code')->get(['id', 'code', 'name_th']);
        $roles = Role::with('permissions')->orderBy('id')->get();
        $salesmen = \App\Models\Salesman::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);

        return view('users.index', compact('users', 'branches', 'roles', 'salesmen', 'q', 'status'));
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
        $this->audit('user_create', $user, [], [
            'username' => $user->username,
            'role_ids' => $data['role_ids'],
            'must_change_password' => true,
        ]);

        return redirect()->route('users.index')->with('success', "เพิ่มผู้ใช้ {$user->username} แล้ว");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $oldValues = [
            'name' => $user->name,
            'branch_id' => $user->branch_id,
            'salesman_id' => $user->salesman_id,
            'is_active' => $user->is_active,
            'role_ids' => $user->roles()->pluck('roles.id')->all(),
        ];
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
        $this->audit('user_update', $user, $oldValues, [
            'name' => $user->name,
            'branch_id' => $user->branch_id,
            'salesman_id' => $user->salesman_id,
            'is_active' => $user->is_active,
            'role_ids' => $data['role_ids'],
            'password_changed' => ! empty($data['password']),
        ]);

        return redirect()->route('users.index')->with('success', 'บันทึกข้อมูลผู้ใช้แล้ว');
    }

    public function resetPassword(User $user): RedirectResponse
    {
        DB::transaction(function () use ($user) {
            $user->forceFill([
                'password' => '12345678',
                'must_change_password' => true,
                'password_changed_at' => null,
                'remember_token' => null,
            ])->save();

            $this->audit('user_password_reset', $user, [], [
                'username' => $user->username,
                'must_change_password' => true,
            ]);
        });

        return redirect()->route('users.index')
            ->with('success', "รีเซ็ตรหัสผ่าน {$user->username} เป็น 12345678 แล้ว และบังคับเปลี่ยนเมื่อเข้าใช้ครั้งแรก");
    }

    private function audit(string $action, User $target, array $oldValues, array $newValues): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'branch_id' => auth()->user()?->branch_id,
            'action' => $action,
            'table_name' => 'users',
            'record_id' => $target->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
