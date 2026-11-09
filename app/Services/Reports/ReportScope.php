<?php

namespace App\Services\Reports;

final class ReportScope
{
    public function branchId(mixed $requested = null): ?int
    {
        $user = auth()->user();
        if ($user && ! $user->canSeeAllReportBranches()) {
            if ($requested !== null && $requested !== '' && $requested !== 'all') {
                abort_unless($user->reportBranchIds(null)->contains((int) $requested), 403);
            }

            return $user->resolveReportBranch($requested) ?? -1;
        }

        return $requested !== null && $requested !== '' && $requested !== 'all' ? (int) $requested : null;
    }

    public function apply($query, string $column = 'branch_id', mixed $requested = null)
    {
        $branch = $this->branchId($requested);

        return $query->when($branch !== null, fn ($q) => $q->where($column, $branch));
    }

    public function applyGl($query, string $alias, mixed $requested = null)
    {
        $branch = $this->branchId($requested);
        if ($branch !== null) {
            $query->whereRaw("coalesce((select scope_doc.branch_id from documents scope_doc where scope_doc.id = {$alias}.document_id), (select scope_payment.branch_id from payment_documents scope_payment where scope_payment.id = {$alias}.payment_document_id)) = ?", [$branch]);
        }

        return $query;
    }

    public function authorize(mixed $branch): void
    {
        $user = auth()->user();
        abort_if($user && ! $user->canSeeAllReportBranches()
            && ($branch === null || ! $user->reportBranchIds(null)->contains((int) $branch)), 403);
    }

    public function canSeeProfit(): bool
    {
        return auth()->user()?->hasPermission('management.view') ?? true;
    }

    /** Remove sensitive fields from the payload itself, including exports. */
    public function redact(array $result): array
    {
        if ($this->canSeeProfit()) {
            return $result;
        }
        $sensitive = fn ($key) => preg_match('/cost|cogs|profit|margin|loss|avg_cost/i', (string) $key);
        $result['columns'] = array_values(array_filter($result['columns'], fn ($c) => ! $sensitive($c['key'])));
        $result['rows'] = collect($result['rows'])->map(function ($row) use ($sensitive) {
            $values = (array) $row;
            foreach (array_keys($values) as $key) {
                if ($sensitive($key)) {
                    unset($values[$key]);
                }
            }

            return (object) $values;
        });

        if (isset($result['pagination'])) {
            $result['pagination']->setCollection($result['rows']);
        }

        return $result;
    }
}
