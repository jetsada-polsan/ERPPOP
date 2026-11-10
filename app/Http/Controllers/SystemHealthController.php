<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RecordResponseMetrics;
use App\Models\PosOfflineSale;
use App\Models\SystemError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class SystemHealthController extends Controller
{
    /**
     * Display the System Health (APM) dashboard.
     */
    public function index(Request $request): View
    {
        $period = $request->query('period', '24h');
        $since = $period === '7d' ? now()->subDays(7) : now()->subHours(24);

        // 5xx errors from system_errors table
        $hasErrorsTable = Schema::hasTable('system_errors');
        $systemErrors = $hasErrorsTable
            ? SystemError::where('occurred_at', '>=', $since)
                ->orderByDesc('count')
                ->paginate(15)
                ->withQueryString()
            : collect();

        $errors24h = $hasErrorsTable ? (int) SystemError::where('occurred_at', '>=', now()->subHours(24))->sum('count') : 0;
        $errors7d = $hasErrorsTable ? (int) SystemError::where('occurred_at', '>=', now()->subDays(7))->sum('count') : 0;
        $unique24h = $hasErrorsTable ? (int) SystemError::where('occurred_at', '>=', now()->subHours(24))->count() : 0;
        $unique7d = $hasErrorsTable ? (int) SystemError::where('occurred_at', '>=', now()->subDays(7))->count() : 0;

        // Sampled response time from lightweight middleware
        $samples = Cache::get(RecordResponseMetrics::SAMPLES_CACHE_KEY, []);
        $samplesCollection = collect(is_array($samples) ? $samples : []);
        $sampleCount = $samplesCollection->count();
        $avgResponseMs = $sampleCount > 0 ? round($samplesCollection->avg('duration'), 1) : null;
        $p95ResponseMs = null;
        if ($sampleCount > 0) {
            $sorted = $samplesCollection->pluck('duration')->sort()->values();
            $p95Index = (int) floor($sampleCount * 0.95);
            $p95ResponseMs = round($sorted->get(min($p95Index, $sampleCount - 1)), 1);
        }

        // POS rejected / needs_review sales today
        $posRejectedToday = 0;
        $posRejectedList = collect();
        if (Schema::hasTable('pos_offline_sales')) {
            $posQuery = PosOfflineSale::where('status', PosOfflineSale::NEEDS_REVIEW)
                ->whereDate('created_at', today());
            $posRejectedToday = (clone $posQuery)->count();
            $posRejectedList = (clone $posQuery)->with(['branch', 'device'])->latest()->take(10)->get();
        }

        // Disk space
        $storagePath = storage_path();
        $diskFreeBytes = @disk_free_space($storagePath) ?: 0;
        $diskTotalBytes = @disk_total_space($storagePath) ?: 0;
        $diskUsedBytes = max(0, $diskTotalBytes - $diskFreeBytes);
        $diskUsedPercent = $diskTotalBytes > 0 ? round(($diskUsedBytes / $diskTotalBytes) * 100, 1) : 0;
        $diskFreeGb = round($diskFreeBytes / (1024 * 1024 * 1024), 2);
        $diskTotalGb = round($diskTotalBytes / (1024 * 1024 * 1024), 2);

        // Log file size
        $logPath = storage_path('logs/laravel.log');
        $logSizeBytes = file_exists($logPath) ? (@filesize($logPath) ?: 0) : 0;
        $logSizeMb = round($logSizeBytes / (1024 * 1024), 2);

        // Alert email configuration
        $alertEmail = env('ERP_ALERT_EMAIL');
        $isAlertEmailConfigured = ! empty($alertEmail);

        return view('operations.health', [
            'period' => $period,
            'systemErrors' => $systemErrors,
            'errors24h' => $errors24h,
            'errors7d' => $errors7d,
            'unique24h' => $unique24h,
            'unique7d' => $unique7d,
            'avgResponseMs' => $avgResponseMs,
            'p95ResponseMs' => $p95ResponseMs,
            'sampleCount' => $sampleCount,
            'recentSamples' => $samplesCollection->take(-10)->reverse()->values(),
            'posRejectedToday' => $posRejectedToday,
            'posRejectedList' => $posRejectedList,
            'diskFreeGb' => $diskFreeGb,
            'diskTotalGb' => $diskTotalGb,
            'diskUsedPercent' => $diskUsedPercent,
            'logSizeMb' => $logSizeMb,
            'alertEmail' => $alertEmail,
            'isAlertEmailConfigured' => $isAlertEmailConfigured,
        ]);
    }

    /**
     * Mark an error as resolved.
     */
    public function resolveError(SystemError $systemError): RedirectResponse
    {
        $systemError->markResolved();

        return back()->with('success', 'บันทึกการตรวจสอบเรียบร้อยแล้ว');
    }
}
