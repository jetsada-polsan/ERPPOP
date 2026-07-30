<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;

class ImportBplusCustomers extends Command
{
    protected $signature = 'customers:import-bplus
        {file : UTF-8 customer master CSV}
        {--apply : Create missing customers; otherwise run as a preview}';

    protected $description = 'Create missing ERP customers from a Business Plus CSV without overwriting existing customers';

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
        $required = ['customer_code', 'customer_name', 'is_active'];
        if (array_diff($required, $headers)) {
            fclose($handle);
            $this->error('CSV requires headers: '.implode(', ', $required));

            return self::FAILURE;
        }

        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count($values) === count($headers)) {
                $rows[] = array_combine($headers, $values);
            }
        }
        fclose($handle);

        $existing = Customer::whereIn('code', collect($rows)->pluck('customer_code')->map('trim'))
            ->pluck('code')
            ->flip();
        $created = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $code = trim((string) $row['customer_code']);
            if ($code === '' || $existing->has($code)) {
                $skipped++;
                continue;
            }
            if ($this->option('apply')) {
                Customer::create([
                    'code' => $code,
                    'name_th' => trim((string) $row['customer_name']) ?: $code,
                    'is_active' => (string) $row['is_active'] === '1',
                ]);
            }
            $created++;
        }

        $this->table(['Rows', $this->option('apply') ? 'Created' : 'Would create', 'Existing/skipped'], [[
            count($rows), $created, $skipped,
        ]]);

        return self::SUCCESS;
    }
}
