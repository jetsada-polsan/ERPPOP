<?php

namespace App\Services\Pos;

use App\Models\PosBuild;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GithubPosBuildService
{
    public function isConfigured(): bool
    {
        return filled(config('services.github_pos_build.token'))
            && filled(config('services.github_pos_build.repository'));
    }

    public function dispatch(PosBuild $build): void
    {
        $this->ensureConfigured();

        $response = $this->client()->post($this->workflowUrl('/dispatches'), [
            'ref' => config('services.github_pos_build.ref', 'main'),
            'inputs' => [
                'version' => $build->version,
                'publish' => true,
                'build_id' => $build->build_uuid,
                'source_ref' => $build->source_ref,
            ],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('GitHub ปฏิเสธคำสั่ง Build: HTTP '.$response->status());
        }

        $build->update([
            'status' => 'queued',
            'dispatched_at' => now(),
            'failure_message' => null,
        ]);
    }

    public function refresh(PosBuild $build): PosBuild
    {
        $this->ensureConfigured();

        $response = $this->client()->get($this->workflowUrl('/runs'), [
            'event' => 'workflow_dispatch',
            'per_page' => 50,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('อ่านสถานะ GitHub Actions ไม่สำเร็จ: HTTP '.$response->status());
        }

        $run = collect($response->json('workflow_runs', []))->first(function ($candidate) use ($build) {
            $title = (string) ($candidate['display_title'] ?? '');

            return str_contains($title, $build->build_uuid);
        });

        if (! is_array($run)) {
            return $build->fresh();
        }

        $githubStatus = (string) ($run['status'] ?? 'queued');
        $conclusion = (string) ($run['conclusion'] ?? '');
        $status = match (true) {
            $githubStatus !== 'completed' && $githubStatus !== 'in_progress' => 'queued',
            $githubStatus === 'in_progress' => 'in_progress',
            $conclusion === 'success' => 'success',
            $conclusion === 'cancelled' => 'cancelled',
            default => 'failed',
        };

        $build->update([
            'status' => $status,
            'github_run_id' => $run['id'] ?? null,
            'github_run_url' => $run['html_url'] ?? null,
            'commit_sha' => $run['head_sha'] ?? null,
            'started_at' => $run['run_started_at'] ?? $build->started_at,
            'completed_at' => $githubStatus === 'completed' ? ($run['updated_at'] ?? now()) : null,
            'failure_message' => in_array($status, ['failed', 'cancelled'], true)
                ? 'GitHub Actions จบด้วยสถานะ '.($conclusion ?: 'unknown')
                : null,
        ]);

        return $build->fresh();
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken((string) config('services.github_pos_build.token'))
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'PopCentral-POS-Build-Center',
            ])
            ->timeout(20);
    }

    private function workflowUrl(string $suffix): string
    {
        $repository = trim((string) config('services.github_pos_build.repository'), '/');
        $workflow = rawurlencode((string) config('services.github_pos_build.workflow', 'pos-python-windows-uat.yml'));

        return "https://api.github.com/repos/{$repository}/actions/workflows/{$workflow}{$suffix}";
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('ยังไม่ได้ตั้งค่า GITHUB_POS_BUILD_TOKEN บน ERP server');
        }
    }
}
