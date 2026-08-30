<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PosBuild;
use App\Services\Pos\GithubPosBuildService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class PosBuildController extends Controller
{
    public function index(GithubPosBuildService $github): View
    {
        return view('settings.pos-builds', [
            'builds' => PosBuild::with('requester:id,name')->latest()->limit(30)->get(),
            'githubConfigured' => $github->isConfigured(),
            'suggestedVersion' => $this->suggestedVersion(),
            'repository' => config('services.github_pos_build.repository'),
        ]);
    }

    public function store(Request $request, GithubPosBuildService $github): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'regex:/^\d+\.\d+\.\d+([.-][A-Za-z0-9]+)?$/', 'max:30'],
            'source_ref' => ['required', 'regex:/^[A-Za-z0-9._\/-]+$/', 'max:120'],
        ], [
            'version.regex' => 'เวอร์ชันต้องอยู่ในรูป 0.5.0 หรือ 0.5.0-uat1',
            'source_ref.regex' => 'Source ref รองรับชื่อ branch, tag หรือ commit เท่านั้น',
        ]);

        if (PosBuild::whereIn('status', ['queued', 'in_progress'])->exists()) {
            return back()->withErrors(['build' => 'มีงาน Build กำลังทำงานอยู่ กรุณารอให้จบก่อน']);
        }

        $build = PosBuild::create([
            'build_uuid' => (string) Str::uuid(),
            'version' => $data['version'],
            'channel' => 'uat',
            'source_ref' => $data['source_ref'],
            'status' => 'queued',
            'requested_by' => $request->user()->id,
        ]);

        try {
            $github->dispatch($build);
        } catch (Throwable $exception) {
            report($exception);
            $build->update(['status' => 'failed', 'failure_message' => $exception->getMessage(), 'completed_at' => now()]);

            return redirect()->route('settings.pos-builds.index')->withErrors(['build' => $exception->getMessage()]);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'branch_id' => $request->user()->branch_id,
            'action' => 'pos_build_dispatched',
            'table_name' => 'pos_builds',
            'record_id' => $build->id,
            'new_values' => ['version' => $build->version, 'source_ref' => $build->source_ref, 'build_uuid' => $build->build_uuid],
        ]);

        return redirect()->route('settings.pos-builds.index')->with('success', 'ส่งงาน Build รุ่น '.$build->version.' ไป GitHub Actions แล้ว');
    }

    public function refresh(Request $request, PosBuild $posBuild, GithubPosBuildService $github): RedirectResponse|JsonResponse
    {
        try {
            $github->refresh($posBuild);
        } catch (Throwable $exception) {
            report($exception);

            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 502);
            }

            return back()->withErrors(['build' => $exception->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status' => $posBuild->fresh()->status,
                'run_url' => $posBuild->fresh()->github_run_url,
            ]);
        }

        return back()->with('success', 'อัปเดตสถานะ Build แล้ว');
    }

    private function suggestedVersion(): string
    {
        $latest = PosBuild::where('status', 'success')->latest('completed_at')->value('version');
        if (! is_string($latest) || ! preg_match('/^(\d+)\.(\d+)\.(\d+)/', $latest, $matches)) {
            return '0.5.0';
        }

        return $matches[1].'.'.$matches[2].'.'.((int) $matches[3] + 1);
    }
}
