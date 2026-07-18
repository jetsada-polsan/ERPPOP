<div class="table-responsive report-table-wrap">
    <table class="table report-data-table align-middle mb-0">
        <thead>
            <tr>
                @foreach($columns as $column)
                    <th class="{{ $column['class'] ?? '' }}">
                        {{ $column['label'] }}
                        <span class="sort-mark">↕</span>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($columns as $column)
                        @php
                            $value = data_get($row, $column['key']);
                        @endphp
                        <td class="{{ $column['class'] ?? '' }}">
                            @switch($column['type'] ?? 'text')
                                @case('money')
                                    {{ number_format((float) $value, 2) }}
                                    @break
                                @case('number')
                                    {{ number_format((float) $value, 0) }}
                                    @break
                                @case('badge')
                                    <span class="badge rounded-pill text-bg-light border">{{ $value }}</span>
                                    @break
                                @default
                                    {{ $value }}
                            @endswitch
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columns) }}" class="report-empty-cell">
                        <div class="report-empty-state">
                            <i class="bi bi-file-earmark-text"></i>
                            <strong>{{ $empty }}</strong>
                            <span>เลือกช่วงวันที่หรือเงื่อนไข แล้วกดแสดงผล</span>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@once
    @push('head')
        <style>
            .report-table-wrap {
                padding: 0 24px 24px;
                max-height: calc(100vh - 360px);
                overflow: auto;
                border: 1px solid var(--flow-line, #bae6fd);
                border-top: 0;
                border-radius: 0 0 8px 8px;
                background: #fbfdff;
            }

            .report-data-table thead th {
                background: linear-gradient(180deg, #e0f2fe, #f0f9ff);
                color: var(--flow-blue-ink, #0c4a6e);
                font-size: 14px;
                font-weight: 900;
                border-bottom: 3px solid var(--flow-blue, #0284c7);
                padding: 10px 16px;
                position: sticky;
                top: 0;
                z-index: 2;
                white-space: nowrap;
            }

            .report-data-table tbody td {
                border-bottom: 1px solid #d7efff;
                padding: 11px 16px;
                font-size: 14px;
                color: #0f3554;
                vertical-align: middle;
                white-space: nowrap;
            }

            .report-data-table tbody tr:nth-child(even) {
                background: #f0f9ff;
            }

            .report-data-table tbody tr:hover {
                background: #e0f2fe;
            }

            .sort-mark {
                color: #38bdf8;
                font-size: 12px;
                margin-left: 6px;
            }

            .report-empty-cell {
                padding: 64px 16px !important;
                border-bottom: 0 !important;
                background: #fff !important;
            }

            .report-empty-state {
                display: grid;
                place-items: center;
                gap: 7px;
                color: #64748b;
                text-align: center;
            }

            .report-empty-state i {
                width: 86px;
                height: 86px;
                display: grid;
                place-items: center;
                border: 2px solid #bae6fd;
                border-radius: 22px;
                color: var(--flow-blue, #0284c7);
                background: #e0f2fe;
                font-size: 42px;
            }

            .report-empty-state strong {
                color: #334155;
                font-size: 17px;
                font-weight: 900;
            }

            .report-empty-state span {
                font-size: 14px;
            }

            @media (max-width: 767.98px) {
                .report-table-wrap {
                    padding: 0 12px 12px;
                    max-height: none;
                }

                .report-data-table thead th,
                .report-data-table tbody td {
                    padding: 10px 12px;
                    font-size: 14px;
                }
            }
        </style>
    @endpush
@endonce
