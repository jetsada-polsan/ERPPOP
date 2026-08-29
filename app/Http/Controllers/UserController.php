<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\Role;
use App\Models\SalesArea;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use RuntimeException;

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
        $users = User::with(['branch', 'salesman', 'salesArea', 'roles'])
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
        $salesmen = Salesman::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
        $salesAreas = SalesArea::where('area_type', 'route')->where('is_active', true)->orderBy('code')->get();
        $posUsers = User::with(['branch', 'salesman'])
            ->where('is_active', true)
            ->whereHas('roles.permissions', fn ($query) => $query->where('code', 'pos.sell'))
            ->orderBy('name')
            ->get();

        return view('users.index', compact('users', 'posUsers', 'branches', 'roles', 'salesmen', 'salesAreas', 'q', 'status'));
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
            'sales_area_id' => ['nullable', 'integer', 'exists:sales_areas,id'],
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
            'sales_area_id' => $data['sales_area_id'] ?? null,
            'password' => $data['password'],
            'is_active' => true,
            'must_change_password' => true,
        ]);
        $user->roles()->sync($data['role_ids']);
        $this->syncPosProfile($user, $user->salesman_id, $this->roleIdsCanSellPos($data['role_ids']));
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
            'sales_area_id' => $user->sales_area_id,
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
            'sales_area_id' => ['nullable', 'integer', 'exists:sales_areas,id'],
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
            'sales_area_id' => $data['sales_area_id'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
            $user->must_change_password = true;
        }
        $user->save();
        $user->roles()->sync($data['role_ids']);
        $this->syncPosProfile($user, $user->salesman_id, $this->roleIdsCanSellPos($data['role_ids']));
        $this->audit('user_update', $user, $oldValues, [
            'name' => $user->name,
            'branch_id' => $user->branch_id,
            'salesman_id' => $user->salesman_id,
            'sales_area_id' => $user->sales_area_id,
            'is_active' => $user->is_active,
            'role_ids' => $data['role_ids'],
            'password_changed' => ! empty($data['password']),
        ]);

        return redirect()->route('users.index')->with('success', 'บันทึกข้อมูลผู้ใช้แล้ว');
    }

    public function resetPassword(User $user): RedirectResponse
    {
        $temporary = $this->temporaryPassword();

        DB::transaction(function () use ($user, $temporary) {
            $user->forceFill([
                'password' => $temporary,
                'must_change_password' => true,
                'password_changed_at' => null,
                'remember_token' => null,
            ])->save();

            // ไม่บันทึกตัวรหัสลง audit — เก็บแค่ว่าใครรีเซ็ตให้ใครและบังคับเปลี่ยน
            $this->audit('user_password_reset', $user, [], [
                'username' => $user->username,
                'must_change_password' => true,
            ]);
        });

        // รหัสสุ่มต่างกันทุกครั้ง ส่งกลับให้แอดมินคัดลอกครั้งเดียวผ่าน modal ที่ค้างจนกดปิด
        // (ไม่ใช้ toast ที่หายใน 3 วิ ซึ่งคัดลอกรหัสสุ่มไม่ทัน)
        return redirect()->route('users.index')
            ->with('reset_password_result', [
                'type' => 'password',
                'username' => $user->username,
                'password' => $temporary,
            ]);
    }

    public function resetPosPin(User $user): RedirectResponse
    {
        abort_unless($user->is_active, 422, 'บัญชีผู้ใช้นี้ถูกปิดใช้งาน');
        abort_unless($user->hasPermission('pos.sell'), 422, 'ผู้ใช้นี้ยังไม่มีสิทธิ์ขายหน้า POS');

        $cashier = $user->salesman;
        abort_unless($cashier && $cashier->is_active, 422, 'กรุณาผูกโปรไฟล์แคชเชียร์ที่เปิดใช้งานก่อนออก PIN POS');
        abort_if(
            $user->branch_id && $cashier->branch_id && (int) $user->branch_id !== (int) $cashier->branch_id,
            422,
            'สาขาของผู้ใช้กับโปรไฟล์แคชเชียร์ไม่ตรงกัน'
        );

        $temporary = $this->temporaryPosPin($cashier);

        DB::transaction(function () use ($user, $cashier, $temporary) {
            $cashier->setPin($temporary, true);

            // PIN เดิมถูกยกเลิกแล้ว ต้องถอนการยืนยันบนทุกเครื่องทันทีด้วย
            PosDevice::where('active_cashier_id', $cashier->id)->update([
                'active_cashier_id' => null,
                'active_cashier_user_id' => null,
                'cashier_verified_at' => null,
            ]);

            AuditLog::create([
                'user_id' => auth()->id(),
                'branch_id' => auth()->user()?->branch_id,
                'action' => 'cashier_pin_reset',
                'table_name' => 'salesmen',
                'record_id' => $cashier->id,
                'new_values' => [
                    'username' => $user->username,
                    'cashier_code' => $cashier->code,
                    'must_change_pin' => true,
                ],
            ]);
        });

        return redirect()->route('users.index')->with('reset_pos_pin_result', [
            'type' => 'pos_pin',
            'username' => $user->username,
            'password' => $temporary,
        ]);
    }

    /**
     * รหัสชั่วคราวแบบสุ่ม เดิมตั้งเป็น 12345678 ตายตัวทุกคน ซึ่งเดาได้ และช่วงก่อนที่
     * ผู้ใช้จะเปลี่ยน ใครรู้ก็ล็อกอินได้ ตัดตัวที่สับสน (0/O/1/l/I) ออกให้พิมพ์ครั้งเดียวไม่ผิด
     */
    private function temporaryPassword(): string
    {
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $pick = function (string $pool, int $count): string {
            $out = '';
            for ($i = 0; $i < $count; $i++) {
                $out .= $pool[random_int(0, strlen($pool) - 1)];
            }

            return $out;
        };

        $chars = str_split($pick($letters, 7).$pick($digits, 3));
        shuffle($chars); // กันไม่ให้ตัวเลขไปกองท้ายทุกครั้ง

        return implode('', $chars);
    }

    /** PIN ชั่วคราวต้องไม่ชนคนในสาขา เพราะ POS รุ่นใหม่ล็อกอินด้วย PIN อย่างเดียว */
    private function temporaryPosPin(Salesman $cashier): string
    {
        $candidates = Salesman::query()
            ->where('is_active', true)
            ->whereNotNull('pos_pin_hash')
            ->whereKeyNot($cashier->id)
            ->when($cashier->branch_id, fn ($query) => $query->where(fn ($where) => $where
                ->whereNull('branch_id')
                ->orWhere('branch_id', $cashier->branch_id)))
            ->get(['id', 'pos_pin_hash']);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $pin = (string) random_int(100000, 999999);
            if (! $candidates->contains(fn (Salesman $candidate) => Hash::check($pin, $candidate->pos_pin_hash))) {
                return $pin;
            }
        }

        throw new RuntimeException('ไม่สามารถสร้าง PIN POS ที่ไม่ซ้ำได้ กรุณาลองใหม่');
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

    private function roleIdsCanSellPos(array $roleIds): bool
    {
        return Role::whereIn('id', $roleIds)
            ->whereHas('permissions', fn ($query) => $query->where('code', 'pos.sell'))
            ->exists();
    }

    private function syncPosProfile(User $user, ?int $salesmanId, bool $canSellPos): void
    {
        if (! $salesmanId && $canSellPos) {
            $profile = Salesman::create([
                'branch_id' => $user->branch_id,
                'code' => $this->uniquePosProfileCode($user),
                'name' => $user->name,
                'is_active' => true,
            ]);
            $salesmanId = $profile->id;
            $user->forceFill(['salesman_id' => $salesmanId])->save();
        }

        Salesman::where('user_id', $user->id)->update(['user_id' => null]);
        if ($salesmanId) {
            Salesman::whereKey($salesmanId)->update([
                'user_id' => $user->id,
                'branch_id' => $user->branch_id,
                'name' => $user->name,
                'is_active' => true,
            ]);
        }
    }

    private function uniquePosProfileCode(User $user): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9_-]+/', '', $user->username) ?: 'U'.$user->id);
        $base = substr($base, 0, 20) ?: 'U'.$user->id;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $suffix = $attempt === 0 ? '' : '-'.$attempt;
            $candidate = substr($base, 0, 20 - strlen($suffix)).$suffix;

            if (! Salesman::where('code', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('ไม่สามารถสร้างรหัสโปรไฟล์ POS ที่ไม่ซ้ำได้ กรุณาระบุโปรไฟล์เอง');
    }
}
