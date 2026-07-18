<li>
    <button type="button" class="org-tree-node type-{{ $unit->unit_type }}" onclick="document.getElementById('org-people-{{ $unit->id }}').showModal()" title="ดูรายชื่อพนักงานในฝ่าย">
        <span class="org-tree-icon"><i class="bi {{ $unit->unit_type==='company'?'bi-building-fill':($unit->unit_type==='management'?'bi-person-workspace':'bi-people-fill') }}"></i></span>
        <strong>{{ $unit->name }}</strong>
        <small>{{ $unit->code }} · {{ number_format($unit->assignments_count) }} คน</small>
        <span class="org-tree-manager"><i class="bi bi-person-check"></i> {{ $unit->manager?->full_name ?? 'ยังไม่กำหนดผู้รับผิดชอบ' }}</span>
        @if($unit->positions->isNotEmpty())
            <span class="org-tree-positions">
                @foreach($unit->positions as $position)
                    <em class="{{ $position->holder ? 'filled' : 'vacant' }}">{{ $position->title }}: {{ $position->holder?->nickname ?: ($position->holder?->full_name ?: 'ว่าง') }}</em>
                @endforeach
            </span>
        @endif
    </button>
    @php($children = $units->where('parent_id', $unit->id)->sortBy([['sort_order','asc'],['name','asc']]))
    @if($children->isNotEmpty())
    <ul>
        @foreach($children as $child)
            @include('organizational-units._tree-node', ['unit'=>$child, 'units'=>$units])
        @endforeach
    </ul>
    @endif
</li>
