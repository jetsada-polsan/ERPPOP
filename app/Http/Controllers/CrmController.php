<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerOpenItem;
use App\Models\CrmActivity;
use App\Models\CrmOpportunity;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CrmController extends Controller
{
    private const STAGES = [
        'new' => 'ลูกค้าใหม่',
        'contacted' => 'ติดต่อแล้ว',
        'qualified' => 'สนใจ',
        'quotation' => 'ส่งใบเสนอราคา',
        'waiting' => 'รอปิดการขาย',
        'won' => 'ชนะการขาย',
        'lost' => 'ไม่สำเร็จ',
    ];

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

        $activityCustomers = (clone $visibleCustomers)
            ->where('is_active', true)->orderBy('name_th')->limit(200)->get(['id', 'code', 'name_th']);

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

        $activities = CrmActivity::query()
            ->with(['customer', 'assignedTo'])
            ->whereIn('customer_id', $customerIds)
            ->where('status', 'pending')
            ->orderByRaw('due_at is null, due_at asc')
            ->limit(8)
            ->get();
        $pipeline = CrmOpportunity::query()
            ->whereIn('customer_id', (clone $visibleCustomers)->select('customers.id'))
            ->whereNotIn('stage', ['won', 'lost'])
            ->selectRaw('count(*) as open_count, coalesce(sum(expected_amount), 0) as open_value')
            ->first();
        $overdueActivities = CrmActivity::query()
            ->whereIn('customer_id', (clone $visibleCustomers)->select('customers.id'))
            ->where('status', 'pending')->whereNotNull('due_at')->where('due_at', '<', now())->count();
        $activityTypes = [
            'call' => 'โทรศัพท์', 'visit' => 'เข้าพบ', 'line' => 'LINE',
            'email' => 'อีเมล', 'note' => 'หมายเหตุ',
        ];
        $activityUsers = User::query()->where('is_active', true)
            ->whereHas('roles.permissions', fn ($query) => $query->where('code', 'sales.manage'))
            ->when($user->branch_id && ! $user->hasPermission('reports.all_branches'), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->orderBy('name')->get(['id', 'name']);

        return view('crm.index', compact(
            'customers', 'topCustomers', 'recentDocuments', 'counts',
            'outstandingBalance', 'q', 'status', 'activities', 'activityTypes', 'activityUsers', 'activityCustomers', 'pipeline', 'overdueActivities'
        ));
    }

    public function storeActivity(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'activity_type' => ['required', 'in:call,visit,line,email,note'],
            'subject' => ['required', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:2000'],
            'due_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $user = $request->user();
        $customer = $this->visibleCustomers($user)->whereKey($data['customer_id'])->firstOrFail();
        $assignedTo = $user->id;
        if ($user->hasPermission('sales.assign') && ! empty($data['assigned_to'])) {
            $assignedTo = User::query()->where('is_active', true)
                ->whereHas('roles.permissions', fn ($query) => $query->where('code', 'sales.manage'))
                ->when($user->branch_id && ! $user->hasPermission('reports.all_branches'), fn ($query) => $query->where('branch_id', $user->branch_id))
                ->whereKey($data['assigned_to'])->value('id');
            abort_unless($assignedTo, 422, 'ผู้รับผิดชอบไม่อยู่ในสาขาหรือสิทธิ์ที่กำหนด');
        }
        CrmActivity::create([
            ...$data, 'branch_id' => $customer->branch_id,
            'assigned_to' => $assignedTo, 'created_by' => $user->id,
            'status' => 'pending',
        ]);

        return redirect()->route('crm.index')->with('success', 'เพิ่มงานติดตามลูกค้าแล้ว');
    }

    public function completeActivity(Request $request, CrmActivity $activity): RedirectResponse
    {
        $visible = $this->visibleCustomers($request->user())->whereKey($activity->customer_id)->exists();
        abort_unless($visible, 404);
        $activity->update(['status' => 'completed', 'completed_at' => now()]);

        return redirect()->route('crm.index')->with('success', 'ปิดงานติดตามแล้ว');
    }

    public function pipeline(Request $request): View
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));
        $stage = array_key_exists($request->query('stage'), self::STAGES) ? (string) $request->query('stage') : 'all';
        $visibleCustomers = $this->visibleCustomers($user);

        $opportunities = CrmOpportunity::query()
            ->with(['customer', 'salesUser', 'salesArea'])
            ->whereIn('customer_id', (clone $visibleCustomers)->select('customers.id'))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('title', 'ilike', "%{$q}%")
                ->orWhereHas('customer', fn ($customer) => $customer
                    ->where('code', 'ilike', "%{$q}%")
                    ->orWhere('name_th', 'ilike', "%{$q}%"))
            ))
            ->when($stage !== 'all', fn ($query) => $query->where('stage', $stage))
            ->orderByRaw('expected_close_date is null, expected_close_date asc')
            ->orderByDesc('id')
            ->get();

        $opportunitiesByStage = collect(self::STAGES)->mapWithKeys(fn ($label, $key) => [$key => $opportunities->where('stage', $key)->values()]);

        return view('crm.pipeline', [
            'stages' => self::STAGES,
            'opportunitiesByStage' => $opportunitiesByStage,
            'pipelineCustomers' => (clone $visibleCustomers)->where('is_active', true)->orderBy('name_th')->limit(300)->get(['id', 'code', 'name_th']),
            'salesUsers' => $this->activityUsers($user),
            'q' => $q,
            'stage' => $stage,
            'totalOpportunities' => $opportunities->count(),
        ]);
    }

    public function storeOpportunity(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'title' => ['required', 'string', 'max:200'],
            'stage' => ['required', 'in:'.implode(',', array_keys(self::STAGES))],
            'expected_amount' => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $user = $request->user();
        $customer = $this->visibleCustomers($user)->whereKey($data['customer_id'])->firstOrFail();
        $assignedTo = $user;
        if ($user->hasPermission('sales.assign') && ! empty($data['assigned_to'])) {
            $assignedTo = $this->activityUsers($user)->firstWhere('id', (int) $data['assigned_to']);
            abort_unless($assignedTo, 422, 'ผู้รับผิดชอบไม่อยู่ในสาขาหรือสิทธิ์ที่กำหนด');
        }

        CrmOpportunity::create([
            'customer_id' => $customer->id,
            'branch_id' => $customer->branch_id,
            'sales_user_id' => $assignedTo->id,
            'sales_area_id' => $assignedTo->sales_area_id,
            'title' => $data['title'],
            'stage' => $data['stage'],
            'expected_amount' => $data['expected_amount'] ?? 0,
            'expected_close_date' => $data['expected_close_date'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        return redirect()->route('crm.pipeline')->with('success', 'เพิ่มโอกาสการขายแล้ว');
    }

    public function updateOpportunityStage(Request $request, CrmOpportunity $opportunity): RedirectResponse
    {
        $visible = $this->visibleCustomers($request->user())->whereKey($opportunity->customer_id)->exists();
        abort_unless($visible, 404);
        $data = $request->validate([
            'stage' => ['required', 'in:'.implode(',', array_keys(self::STAGES))],
            'lost_reason' => ['nullable', 'string', 'max:500'],
        ]);
        $opportunity->update([
            'stage' => $data['stage'],
            'lost_reason' => $data['stage'] === 'lost' ? ($data['lost_reason'] ?? $opportunity->lost_reason) : null,
        ]);

        return redirect()->route('crm.pipeline')->with('success', 'อัปเดตสถานะโอกาสการขายแล้ว');
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

    private function activityUsers($user)
    {
        return User::query()->where('is_active', true)
            ->whereHas('roles.permissions', fn ($query) => $query->where('code', 'sales.manage'))
            ->when($user->branch_id && ! $user->hasPermission('reports.all_branches'), fn ($query) => $query->where('branch_id', $user->branch_id))
            ->orderBy('name')->get(['id', 'name', 'sales_area_id']);
    }
}
