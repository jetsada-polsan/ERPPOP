<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\OperationRun;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class OperationsController extends Controller
{
    public function index(): View
    {
        $backups = collect(glob(storage_path('app/backups/erp-db-*.gz')) ?: [])->sortByDesc(fn ($file) => filemtime($file))->take(20)->map(fn ($file) => [
            'name' => basename($file), 'size' => filesize($file), 'created_at' => filemtime($file),
            'checksum' => is_file($file.'.sha256') ? strtok(trim(File::get($file.'.sha256')), " \t") : null,
        ])->values();
        $users = User::where('is_active', true)->count();
        $mfaUsers = User::where('is_active', true)->whereNotNull('mfa_enabled_at')->count();

        return view('operations.index', [
            'backups' => $backups,
            'users' => User::with('branch')->where('is_active', true)->orderBy('name')->get(),
            'runs' => OperationRun::with('runner')->latest('started_at')->limit(30)->get(),
            'security' => [
                'active_users' => $users, 'mfa_users' => $mfaUsers,
                'mfa_percent' => $users ? round($mfaUsers * 100 / $users) : 0,
                'stale_passwords' => User::where('is_active', true)->where(fn ($q) => $q->whereNull('password_changed_at')->orWhere('password_changed_at', '<', now()->subDays(180)))->count(),
                'active_sessions' => Schema::hasTable('sessions') ? DB::table('sessions')->where('last_activity', '>=', now()->subHours(8)->timestamp)->count() : 0,
            ],
            'recentAudits' => AuditLog::with(['user', 'branch'])->whereIn('action', ['login', 'mfa_login', 'mfa_enable', 'mfa_disable', 'backup', 'restore_verify'])->latest('created_at')->limit(30)->get(),
            'monitorEvents' => Schema::hasTable('monitor_events') ? DB::table('monitor_events')->where('status', 'open')->latest('detected_at')->get() : collect(),
        ]);
    }

    public function backup(): RedirectResponse
    {
        return $this->run('backup', 'erp:backup', ['--keep-days' => 30], 'backup');
    }

    public function verifyRestore(): RedirectResponse
    {
        return $this->run('restore_verify', 'erp:restore-drill', [], 'restore_verify');
    }

    public function resetMfa(Request $request, User $user): RedirectResponse
    {
        $request->validate(['confirmation' => ['required', 'in:RESET']]);
        $wasEnabled = (bool) $user->mfa_enabled_at;
        $user->forceFill(['mfa_secret' => null, 'mfa_enabled_at' => null])->save();
        AuditLog::create([
            'user_id' => auth()->id(), 'branch_id' => $user->branch_id, 'action' => 'mfa_admin_reset',
            'table_name' => 'users', 'record_id' => $user->id, 'old_values' => ['mfa_enabled' => $wasEnabled],
            'new_values' => ['mfa_enabled' => false],
        ]);

        return back()->with('success', "รีเซ็ต MFA ของ {$user->name} แล้ว ผู้ใช้ต้องตั้งค่าใหม่");
    }

    private function run(string $operation, string $command, array $arguments, string $auditAction): RedirectResponse
    {
        $run = OperationRun::create(['operation' => $operation, 'status' => 'running', 'run_by' => auth()->id(), 'started_at' => now()]);
        $exit = Artisan::call($command, $arguments);
        $output = trim(Artisan::output());
        $status = $exit === 0 ? 'success' : 'failed';
        $run->update(['status' => $status, 'message' => $output, 'finished_at' => now()]);
        AuditLog::create(['user_id' => auth()->id(), 'branch_id' => auth()->user()?->branch_id, 'action' => $auditAction, 'table_name' => 'operation_runs', 'record_id' => $run->id, 'new_values' => ['status' => $status]]);

        return back()->with($exit === 0 ? 'success' : 'error', $exit === 0 ? $output : 'ดำเนินการไม่สำเร็จ: '.$output);
    }
}
