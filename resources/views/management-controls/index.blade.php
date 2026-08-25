@extends('layout')

@section('title', 'ศูนย์ควบคุมบริหาร - POPSTAR ERP')
@section('page-title', 'ศูนย์ควบคุมบริหาร')
@section('page-subtitle', 'Budget · Payroll · Purchase Plan · E-Commerce · Profit · Monitoring')

@php
    $money = fn ($v) => number_format((float) $v, 2);

    // สรุปรวมทุกสาขา — เดิมมีแต่ตารางรายสาขา ต้องบวกเองถึงจะรู้ภาพรวม
    $totalSales = $profit->sum('sales');
    $totalCogs = $profit->sum('cogs');
    $totalExpenses = $profit->sum('expenses');
    $totalNet = $profit->sum('net_profit');
    $hasProfitData = $profit->contains(fn ($row) => (float) $row->sales !== 0.0
        || (float) $row->cogs !== 0.0 || (float) $row->expenses !== 0.0);
    $margin = $totalSales > 0 ? round($totalNet / $totalSales * 100, 1) : null;

    $tabs = ['profit' => 'กำไรสุทธิ'];
    $user = auth()->user();
    if ($user->hasPermission('purchasing.manage')) { $tabs['purchase'] = 'แผนซื้อ'; }
    if ($user->hasPermission('budget.manage')) { $tabs['budget'] = 'Budget'; }
    if ($user->hasPermission('payroll.manage')) { $tabs['attendance'] = 'เวลา / Payroll'; }
    if ($user->hasPermission('ecommerce.sync')) { $tabs['ecommerce'] = 'E-Commerce'; }
    if ($user->hasPermission('monitoring.manage')) { $tabs['monitor'] = 'Monitoring'; }
@endphp

@section('content')
<div x-data="{ tab: 'profit' }">

    <div class="od-ctrl">
        <div class="od-tabs" role="tablist">
            @foreach ($tabs as $key => $label)
                <button type="button" class="od-tab" :class="tab === '{{ $key }}' && 'on'"
                        role="tab" :aria-selected="tab === '{{ $key }}'"
                        @click="tab = '{{ $key }}'">{{ $label }}</button>
            @endforeach
        </div>
        <div class="od-ctrl-right">
            <form method="get" class="od-range">
                <label for="mc-period">งวด</label>
                <input id="mc-period" type="month" name="period" value="{{ $period }}">
                <button type="submit" class="od-btn od-btn-primary">เปลี่ยนงวด</button>
            </form>
        </div>
    </div>

    {{-- ── กำไรสุทธิ ─────────────────────────────────────────── --}}
    <section x-show="tab === 'profit'">
        <div class="od-kpis four">
            <div class="od-kpi">
                <span class="od-ico t-blue"><i class="bi bi-receipt"></i></span>
                <div><div class="od-lbl">ยอดขายรวม</div><div class="od-val">{{ $money($totalSales) }}</div>
                    <div class="od-sub">{{ $profit->count() }} สาขา</div></div>
            </div>
            <div class="od-kpi">
                <span class="od-ico t-amber"><i class="bi bi-box-seam"></i></span>
                <div><div class="od-lbl">ต้นทุนขาย</div><div class="od-val">{{ $money($totalCogs) }}</div>
                    <div class="od-sub">{{ $totalSales > 0 ? round($totalCogs / $totalSales * 100, 1).'% ของยอดขาย' : '—' }}</div></div>
            </div>
            <div class="od-kpi">
                <span class="od-ico t-info"><i class="bi bi-cash-stack"></i></span>
                <div><div class="od-lbl">ค่าใช้จ่าย</div><div class="od-val">{{ $money($totalExpenses) }}</div>
                    <div class="od-sub">{{ $totalSales > 0 ? round($totalExpenses / $totalSales * 100, 1).'% ของยอดขาย' : '—' }}</div></div>
            </div>
            <div class="od-kpi">
                <span class="od-ico {{ $totalNet < 0 ? 't-red' : 't-green' }}"><i class="bi bi-graph-up-arrow"></i></span>
                <div><div class="od-lbl">กำไรสุทธิ</div>
                    <div class="od-val">{{ $money($totalNet) }}</div>
                    <div class="od-sub">
                        @if ($margin !== null)
                            <b class="{{ $totalNet < 0 ? 'dn' : 'up' }}">{{ $margin }}% ของยอดขาย</b>
                        @else — @endif
                    </div></div>
            </div>
        </div>

        <section class="od-card">
            <header><h3>กำไรหลังต้นทุนและค่าใช้จ่าย</h3><span class="od-meta">งวด {{ $period }}</span></header>
            @if (! $hasProfitData)
                {{-- เดิมแสดง 0.00 เต็มตารางโดยไม่บอกอะไร คนอ่านแยกไม่ออกว่าไม่มีข้อมูล หรือขายไม่ได้จริง --}}
                <p class="od-empty">
                    ยังไม่มีการเคลื่อนไหวในงวด {{ $period }}<br>
                    <span style="font-size:11.5px">ตัวเลขจะขึ้นเมื่อมีการขายหรือบันทึกค่าใช้จ่ายในงวดนี้</span>
                </p>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr>
                            <th>สาขา</th><th class="text-end">ยอดขาย</th><th class="text-end">ต้นทุนขาย</th>
                            <th class="text-end">ค่าใช้จ่าย</th><th class="text-end">กำไรสุทธิ</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($profit as $row)
                            <tr>
                                <td><b>{{ $row->code }}</b> {{ $row->name_th }}</td>
                                <td class="text-end">{{ $money($row->sales) }}</td>
                                <td class="text-end">{{ $money($row->cogs) }}</td>
                                <td class="text-end">{{ $money($row->expenses) }}</td>
                                <td class="text-end">
                                    <span class="od-pill {{ $row->net_profit < 0 ? 'fail' : 'ok' }}">{{ $money($row->net_profit) }}</span>
                                </td>
                            </tr>
                        @endforeach
                        <tr class="od-total">
                            <td>รวมทุกสาขา</td>
                            <td class="text-end">{{ $money($totalSales) }}</td>
                            <td class="text-end">{{ $money($totalCogs) }}</td>
                            <td class="text-end">{{ $money($totalExpenses) }}</td>
                            <td class="text-end">{{ $money($totalNet) }}</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </section>

    {{-- ── แผนซื้อ ───────────────────────────────────────────── --}}
    @if ($user->hasPermission('purchasing.manage'))
    <section x-show="tab === 'purchase'" x-cloak>
        <section class="od-card">
            <header><h3>แผนซื้อจาก Min/Max</h3>
                <form method="post" action="{{ route('management-controls.purchase-plans.generate') }}" style="margin-left:auto">
                    @csrf<button class="od-btn od-btn-primary">สร้างคำแนะนำใหม่</button>
                </form>
            </header>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>เลขแผน</th><th>สินค้า</th><th>ผู้ขาย</th><th class="text-end">แนะนำซื้อ</th><th>สถานะ</th></tr></thead>
                    <tbody>
                    @forelse ($purchasePlans as $plan)
                        <tr>
                            <td>{{ $plan->plan_no }}</td>
                            <td>{{ $plan->product?->sku_code }} {{ $plan->product?->name_th }}</td>
                            <td>{{ $plan->supplier?->name_th ?? '—' }}</td>
                            <td class="text-end">{{ number_format($plan->suggested_qty, 4) }}</td>
                            <td><span class="od-pill wait">{{ $plan->status }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีแผน — กด “สร้างคำแนะนำใหม่” เพื่อคำนวณจาก Min/Max</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </section>
    @endif

    {{-- ── Budget ────────────────────────────────────────────── --}}
    @if ($user->hasPermission('budget.manage'))
    <section x-show="tab === 'budget'" x-cloak>
        <section class="od-card" style="margin-bottom:12px">
            <header><h3>เพิ่ม Cost Center</h3></header>
            <div class="od-pad">
                <form method="post" action="{{ route('management-controls.cost-centers.store') }}" class="od-form">
                    @csrf
                    <div class="od-field"><label for="cc-code">รหัส</label><input id="cc-code" name="code" required class="form-control"></div>
                    <div class="od-field" style="grid-column:span 2"><label for="cc-name">ชื่อ Cost Center</label><input id="cc-name" name="name" required class="form-control"></div>
                    <div class="od-form-actions"><button class="od-btn od-btn-primary">เพิ่ม</button></div>
                </form>
            </div>
        </section>

        <section class="od-card" style="margin-bottom:12px">
            <header><h3>ตั้งวงเงิน Budget</h3></header>
            <div class="od-pad">
                <form method="post" action="{{ route('management-controls.budgets.store') }}" class="od-form">
                    @csrf
                    <div class="od-field"><label for="bg-year">ปีงบ</label><input id="bg-year" name="fiscal_year" value="{{ substr($period, 0, 4) }}" required class="form-control"></div>
                    <div class="od-field"><label for="bg-cc">Cost Center</label><select id="bg-cc" name="cost_center_id" required class="form-select">@foreach ($costCenters as $c)<option value="{{ $c->id }}">{{ $c->code }} {{ $c->name }}</option>@endforeach</select></div>
                    <div class="od-field"><label for="bg-month">เดือน</label><input id="bg-month" type="number" min="1" max="12" name="month" value="{{ (int) substr($period, 5, 2) }}" class="form-control"></div>
                    <div class="od-field"><label for="bg-acc">ผังบัญชี</label><select id="bg-acc" name="account_id" required class="form-select">@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->code }} {{ $a->name_th }}</option>@endforeach</select></div>
                    <div class="od-field"><label for="bg-amt">วงเงิน</label><input id="bg-amt" type="number" step="0.01" min="0" name="budget_amount" required class="form-control"></div>
                    <div class="od-form-actions"><button class="od-btn od-btn-primary">บันทึก</button></div>
                </form>
            </div>
        </section>

        <section class="od-card">
            <header><h3>Budget ที่ตั้งไว้</h3><span class="od-meta">{{ count($budgets) }} รายการ</span></header>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>เลข Budget</th><th>Cost Center</th><th class="text-end">วงเงินรวม</th><th>สถานะ</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($budgets as $b)
                        <tr>
                            <td>{{ $b->budget_no }}</td>
                            <td>{{ $b->cost_center_name }}</td>
                            <td class="text-end">{{ $money($b->total_amount) }}</td>
                            <td><span class="od-pill {{ $b->status === 'approved' ? 'ok' : 'wait' }}">{{ $b->status === 'approved' ? 'อนุมัติแล้ว' : 'ร่าง' }}</span></td>
                            <td class="text-end"><a href="{{ route('management-controls.budgets.show', $b->id) }}" class="od-btn">เทียบงบ vs จริง</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มี Budget</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </section>
    @endif

    {{-- ── เวลา / Payroll ────────────────────────────────────── --}}
    @if ($user->hasPermission('payroll.manage'))
    <section x-show="tab === 'attendance'" x-cloak>
        <section class="od-card" style="margin-bottom:12px">
            <header><h3>บันทึกเวลาเข้างาน</h3></header>
            <div class="od-pad">
                <form method="post" action="{{ route('management-controls.attendance.store') }}" class="od-form">
                    @csrf
                    <div class="od-field" style="grid-column:span 2"><label for="at-emp">พนักงาน</label><select id="at-emp" name="employee_id" required class="form-select">@foreach ($employees as $e)<option value="{{ $e->id }}">{{ $e->employee_code }} {{ $e->full_name }}</option>@endforeach</select></div>
                    <div class="od-field"><label for="at-date">วันที่</label><input id="at-date" type="date" name="work_date" value="{{ now()->toDateString() }}" class="form-control"></div>
                    <div class="od-field"><label for="at-status">สถานะ</label><select id="at-status" name="status" class="form-select"><option value="present">มาทำงาน</option><option value="late">สาย</option><option value="leave">ลา</option><option value="absent">ขาด</option><option value="holiday">วันหยุด</option></select></div>
                    <div class="od-field"><label for="at-ot">OT (ชั่วโมง)</label><input id="at-ot" type="number" step="0.25" min="0" name="overtime_hours" class="form-control"></div>
                    <div class="od-form-actions"><button class="od-btn od-btn-primary">บันทึก</button></div>
                </form>
            </div>
        </section>

        <section class="od-card">
            <header><h3>รอบ Payroll</h3>
                <form method="post" action="{{ route('management-controls.payroll.generate') }}" style="margin-left:auto;display:flex;gap:8px;align-items:center">
                    @csrf
                    <input type="month" name="period" value="{{ $period }}" class="form-control" style="max-width:150px;font-size:13px">
                    <button class="od-btn" style="border-color:var(--erp-danger);color:var(--erp-danger)">คำนวณ Payroll</button>
                </form>
            </header>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>งวด</th><th class="text-end">รายได้รวม</th><th class="text-end">หักรวม</th><th class="text-end">สุทธิ</th><th>สถานะ</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($payrollRuns as $run)
                        <tr>
                            <td>{{ $run->period }}</td>
                            <td class="text-end">{{ $money($run->gross_amount) }}</td>
                            <td class="text-end">{{ $money($run->deduction_amount) }}</td>
                            <td class="text-end fw-bold">{{ $money($run->net_amount) }}</td>
                            <td><span class="od-pill {{ ['draft' => 'wait', 'approved' => 'wait', 'paid' => 'ok'][$run->status] ?? 'wait' }}">{{ ['draft' => 'ร่าง', 'approved' => 'อนุมัติแล้ว', 'paid' => 'จ่ายแล้ว'][$run->status] ?? $run->status }}</span></td>
                            <td class="text-end"><a href="{{ route('management-controls.payroll.show', $run->id) }}" class="od-btn">ตรวจ / อนุมัติ</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีรอบ Payroll ในระบบ</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </section>
    @endif

    {{-- ── E-Commerce ────────────────────────────────────────── --}}
    @if ($user->hasPermission('ecommerce.sync'))
    <section x-show="tab === 'ecommerce'" x-cloak>
        <section class="od-card" style="margin-bottom:12px">
            <header><h3>นำเข้าคำสั่งซื้อ</h3></header>
            <div class="od-pad">
                <form method="post" action="{{ route('management-controls.ecommerce.orders.store') }}" class="od-form">
                    @csrf
                    <div class="od-field"><label for="ec-ch">ช่องทาง</label><select id="ec-ch" name="ecommerce_channel_id" required class="form-select">@foreach ($channels as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
                    <div class="od-field"><label for="ec-oid">Order ID</label><input id="ec-oid" name="external_order_id" required class="form-control"></div>
                    <div class="od-field"><label for="ec-cust">ลูกค้า</label><input id="ec-cust" name="customer_name" class="form-control"></div>
                    <div class="od-field"><label for="ec-st">สถานะ</label><input id="ec-st" name="status" value="new" required class="form-control"></div>
                    <div class="od-field"><label for="ec-amt">ยอดรวม</label><input id="ec-amt" type="number" step="0.01" name="total_amount" required class="form-control"></div>
                    <div class="od-form-actions"><button class="od-btn od-btn-primary">นำเข้า</button></div>
                    <div class="od-field" style="grid-column:1 / -1">
                        <label for="ec-items">รายการสินค้า (JSON)</label>
                        <textarea id="ec-items" name="items" class="form-control" rows="2" placeholder='[{"sku":"100001","qty":2,"unit_price":50}]'></textarea>
                    </div>
                </form>
            </div>
        </section>

        <section class="od-card">
            <header><h3>คำสั่งซื้อที่นำเข้าแล้ว</h3><span class="od-meta">{{ count($orders) }} รายการ</span></header>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>ช่องทาง</th><th>Order</th><th>ลูกค้า</th><th>สถานะ</th><th class="text-end">ยอด</th></tr></thead>
                    <tbody>
                    @forelse ($orders as $order)
                        <tr>
                            <td>{{ $order->channel_name }}</td>
                            <td>{{ $order->external_order_id }}</td>
                            <td>{{ $order->customer_name ?: '—' }}</td>
                            <td><span class="od-pill wait">{{ $order->status }}</span></td>
                            <td class="text-end">{{ $money($order->total_amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีคำสั่งซื้อ</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </section>
    @endif

    {{-- ── Monitoring ────────────────────────────────────────── --}}
    @if ($user->hasPermission('monitoring.manage'))
    <section x-show="tab === 'monitor'" x-cloak>
        <section class="od-card">
            <header><h3>Monitoring Events</h3><span class="od-meta">{{ count($monitorEvents) }} เหตุการณ์</span></header>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>เวลา</th><th>Check</th><th>ระดับ</th><th>สถานะ</th><th>รายละเอียด</th></tr></thead>
                    <tbody>
                    @forelse ($monitorEvents as $event)
                        <tr>
                            <td>{{ $event->detected_at }}</td>
                            <td>{{ $event->check_code }}</td>
                            <td><span class="od-pill {{ in_array($event->severity, ['critical', 'error'], true) ? 'fail' : 'wait' }}">{{ $event->severity }}</span></td>
                            <td>{{ $event->status }}</td>
                            <td>{{ $event->message }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">ไม่มีเหตุผิดปกติ</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </section>
    @endif
</div>
@endsection
