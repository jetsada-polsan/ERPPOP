<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\SalesArea;
use Illuminate\Console\Command;

class ImportCustomerSalesAssignments extends Command
{
    protected $signature = 'customers:import-sales-assignments
        {file : UTF-8 CSV exported from Business Plus}
        {--apply : Persist assignments; otherwise run as a preview}';

    protected $description = 'Link customers to ERP login users and sales routes using Business Plus history';

    /**
     * Confirmed Business Plus SALESMAN code to current employee code.
     *
     * @var array<string, string>
     */
    private const EMPLOYEE_BY_LEGACY_SALESMAN = [
        '01' => 'EMP0026',
        '02' => 'EMP0022',
        '03' => 'EMP0030',
        '04' => 'EMP0025',
        '05' => 'EMP0023',
        '07' => 'EMP0028',
        '08' => 'EMP0033',
        '09' => 'EMP0034',
        '12' => 'EMP0027',
        '14' => 'EMP0029',
        '21' => 'EMP0095',
    ];

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_readable($path)) {
            $this->error("Cannot read CSV: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'rb');
        $headers = array_map(fn ($value) => trim((string) $value), fgetcsv($handle) ?: []);
        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        }
        $required = ['customer_code', 'route_code', 'legacy_salesman_code'];
        if (array_diff($required, $headers)) {
            fclose($handle);
            $this->error('CSV requires headers: '.implode(', ', $required));

            return self::FAILURE;
        }

        $counts = ['rows' => 0, 'updated' => 0, 'missing_customer' => 0, 'missing_route' => 0, 'missing_user' => 0];
        $preview = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count($values) !== count($headers)) {
                continue;
            }
            $counts['rows']++;
            $row = array_combine($headers, $values);
            $customerCode = trim((string) $row['customer_code']);
            $routeCode = trim((string) $row['route_code']);
            $legacyCode = str_pad(trim((string) $row['legacy_salesman_code']), 2, '0', STR_PAD_LEFT);

            $customer = Customer::where('code', $customerCode)->first();
            if (! $customer) {
                $counts['missing_customer']++;
                continue;
            }
            $route = SalesArea::where('area_type', 'route')->where('code', $routeCode)->first();
            if (! $route) {
                $counts['missing_route']++;
                continue;
            }

            $employeeCode = self::EMPLOYEE_BY_LEGACY_SALESMAN[$legacyCode] ?? null;
            $userId = $employeeCode
                ? Employee::where('employee_code', $employeeCode)->value('user_id')
                : null;
            if (! $userId) {
                $counts['missing_user']++;
            }

            $changes = ['sales_area_id' => $route->id];
            if ($userId) {
                $changes['sales_user_id'] = $userId;
            }
            if ($this->option('apply')) {
                $customer->update($changes);
            }
            $counts['updated']++;

            if (count($preview) < 15) {
                $preview[] = [$customerCode, $routeCode, $employeeCode ?? '-', $userId ?? '-', $this->option('apply') ? 'APPLIED' : 'PREVIEW'];
            }
        }
        fclose($handle);

        $this->table(['Customer', 'Route', 'Employee', 'User ID', 'Mode'], $preview);
        $this->table(['Rows', 'Matched', 'No customer', 'No route', 'No confirmed user'], [[
            $counts['rows'], $counts['updated'], $counts['missing_customer'], $counts['missing_route'], $counts['missing_user'],
        ]]);

        return self::SUCCESS;
    }
}
