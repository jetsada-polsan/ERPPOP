<?php

namespace App\Services\Monitoring;

use App\Models\SystemError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SystemErrorRecorder
{
    /**
     * Record a 5xx or unhandled server error.
     */
    public function record(Throwable $e, ?Request $request = null): ?SystemError
    {
        try {
            if (! $this->is5xxError($e)) {
                return null;
            }

            if (! Schema::hasTable('system_errors')) {
                return null;
            }

            $status = $this->resolveStatusCode($e);
            $request = $request ?? (app()->bound('request') ? request() : null);

            $rawUrl = $request ? $request->fullUrl() : (app()->runningInConsole() ? 'cli: '.implode(' ', $_SERVER['argv'] ?? []) : 'internal');
            $sanitizedUrl = $this->sanitizeUrl($rawUrl);
            $routeName = $request?->route()?->getName();
            $userId = auth()->id();

            $fileLine = $e->getFile().':'.$e->getLine();
            $exceptionClass = get_class($e);
            $sanitizedMessage = $this->sanitizeMessage($e->getMessage() ?: $exceptionClass);

            // Compute deterministic fingerprint for deduplication
            $fingerprint = hash('sha256', $exceptionClass.'|'.$fileLine.'|'.mb_substr($sanitizedMessage, 0, 200));

            $error = SystemError::where('fingerprint', $fingerprint)->first();
            $isNew = false;

            if ($error) {
                $error->increment('count');
                $error->update([
                    'occurred_at' => now(),
                    'url' => $sanitizedUrl,
                    'route_name' => $routeName,
                    'user_id' => $userId,
                    'status' => $status,
                ]);
            } else {
                $error = SystemError::create([
                    'occurred_at' => now(),
                    'status' => $status,
                    'url' => $sanitizedUrl,
                    'route_name' => $routeName,
                    'user_id' => $userId,
                    'message' => mb_substr($sanitizedMessage, 0, 500),
                    'exception_class' => $exceptionClass,
                    'file_line' => $fileLine,
                    'fingerprint' => $fingerprint,
                    'count' => 1,
                ]);
                $isNew = true;
            }

            $this->checkAndSendAlert($error, $isNew);

            return $error;
        } catch (Throwable) {
            // APM error recording must NEVER crash the application
            return null;
        }
    }

    /**
     * Check if the exception represents a 5xx server-side error.
     */
    public function is5xxError(Throwable $e): bool
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode() >= 500;
        }

        if ($e instanceof \Illuminate\Validation\ValidationException ||
            $e instanceof \Illuminate\Auth\AuthenticationException ||
            $e instanceof \Illuminate\Auth\Access\AuthorizationException ||
            $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ||
            $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException ||
            $e instanceof \Illuminate\Session\TokenMismatchException) {
            return false;
        }

        return true;
    }

    /**
     * Resolve the HTTP status code.
     */
    public function resolveStatusCode(Throwable $e): int
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        return 500;
    }

    /**
     * Sanitize URL to strip sensitive tokens, passwords, and PINs.
     */
    public function sanitizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return mb_substr($url, 0, 1000);
        }

        if (empty($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);
        $sensitiveKeys = ['password', 'pwd', 'pass', 'pin', 'token', 'secret', 'key', 'auth', 'bearer', 'credential', 'credit_card', 'cvv'];

        $sanitizedQuery = [];
        foreach ($query as $key => $val) {
            $lower = strtolower((string) $key);
            $isSensitive = false;
            foreach ($sensitiveKeys as $sensitive) {
                if (str_contains($lower, $sensitive)) {
                    $isSensitive = true;
                    break;
                }
            }
            $sanitizedQuery[$key] = $isSensitive ? '[FILTERED]' : $val;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $queryString = ! empty($sanitizedQuery) ? '?'.http_build_query($sanitizedQuery) : '';

        return $scheme.$host.$port.$path.$queryString;
    }

    /**
     * Sanitize message to never leak passwords, tokens, or PINs.
     */
    public function sanitizeMessage(string $message): string
    {
        $cleaned = preg_replace(
            '/(password|token|pin|secret|auth|key|credential)\s*[:=]\s*[\'"]?[^\s\'"]+[\'"]?/i',
            '$1: [FILTERED]',
            $message
        );

        return mb_substr($cleaned ?: $message, 0, 500);
    }

    /**
     * Check alert conditions and send email if necessary.
     */
    protected function checkAndSendAlert(SystemError $error, bool $isNew): void
    {
        $alertEmail = env('ERP_ALERT_EMAIL');
        if (blank($alertEmail)) {
            return;
        }

        $shouldAlert = false;
        $reason = '';

        if ($isNew) {
            $shouldAlert = true;
            $reason = 'ตรวจพบ Error 5xx รูปแบบใหม่ในระบบ';
        } else {
            // Check if 5xx errors > 10 in 10 minutes
            $recentCount = SystemError::where('occurred_at', '>=', now()->subMinutes(10))->sum('count');
            if ($recentCount > 10 && ! Cache::has('system_error_spike_alert_sent')) {
                $shouldAlert = true;
                $reason = 'ตรวจพบ Error 5xx เกิน 10 ครั้งในรอบ 10 นาทีล่าสุด (รวม '.$recentCount.' ครั้ง)';
                Cache::put('system_error_spike_alert_sent', true, now()->addMinutes(10));
            }
        }

        if (! $shouldAlert) {
            return;
        }

        try {
            Mail::to($alertEmail)->send(new \App\Mail\SystemErrorAlertMail($error, $reason));
        } catch (Throwable) {
            // Mail failures must never interrupt the application
        }
    }
}
