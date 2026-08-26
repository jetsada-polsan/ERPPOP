<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CrmController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['active', 'inactive'], true)
            ? (string) $request->query('status') : 'active';

        $visibleCustomers = $this->visibleCustomers($user);
        $searchableCustomers = (clone $visibleCustomers)->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
            ->where('code', 'ilike', "%{$q}%")
            ->orWhere('name_th', 'ilike', "%{$q}%")
        ));
        $customerIds = (clone $searchableCustomers)->select('customers.id');

        $counts = [
            'active' => (clone $visibleCustomers)->where('is_active', true)->count(),
            'inactive' => (clone $visibleCustomers)->where('is_active', false)->count(),
            'unassigned' => (clone $visibleCustomers)->whereNull('sales_user_id')->count(),
        ];
        $outstandingBalance = (float) CustomerOpenItem::query()
            ->whereIn('customer_id', (clone $visibleCustomers)->select('customers.id'))
            ->whereIn('status', ['open', 'partial'])
            ->sum('balance_amount');

        $customers = (clone $searchableCustomers)
            ->with(['branch', 'salesUser', 'salesArea'])
            ->withCount(['contacts', 'addresses'])
            ->withSum(['openItems as outstanding_balance' => fn ($query) => $query->whereIn('status', ['open', 'partial'])], 'balance_amount')
            ->when($status !== 'all', fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name_th')
            ->paginate(15)
            ->withQueryString();

        $topCustomers = (clone $searchableCustomers)
            ->where('is_active', true)
            ->withSum(['documents as sales_total' => fn ($query) => $query->where('status', 'active')], 'total_amount')
            ->orderByDesc('sales_total')
            ->limit(5)
            ->get();

        $recentDocuments = Document::query()
            ->with(['customer', 'documentType', 'branch'])
            ->whereIn('customer_id', $customerIds)
            ->where('status', 'active')
            ->orderByDesc('doc_date')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return view('crm.index', compact(
            'customers', 'topCustomers', 'recentDocuments', 'counts',
            'outstandingBalance', 'q', 'status'
        ));
    }

    private function visibleCustomers($user)
    {
        return Customer::query()->when(
            $user->branch_id && ! $user->hasPermission('reports.all_branches'),
            fn ($query) => $query->where(fn ($scope) => $scope
                ->where('branch_id', $user->branch_id)
                ->orWhere('sales_user_id', $user->id)
            )
        );
    }
}
