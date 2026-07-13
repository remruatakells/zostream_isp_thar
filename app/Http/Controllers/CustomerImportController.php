<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Services\MikroTikService;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CustomerImportController extends Controller
{
    private const MAX_ROWS = 1000;

    private const HEADER_ALIASES = [
        'name' => 'name',
        'full_name' => 'name',
        'customer' => 'name',
        'customer_name' => 'name',
        'phone' => 'phone',
        'mobile' => 'phone',
        'mobile_number' => 'phone',
        'phone_number' => 'phone',
        'contact' => 'phone',
        'address' => 'address',
        'installation_address' => 'address',
        'username' => 'username',
        'user' => 'username',
        'pppoe_username' => 'username',
        'password' => 'password',
        'pass' => 'password',
        'pppoe_password' => 'password',
        'status' => 'status',
        'expires_at' => 'expires_at',
        'expiry' => 'expires_at',
        'expiry_date' => 'expires_at',
        'expiration_date' => 'expires_at',
    ];

    public function create(): View
    {
        return view('customers.import', [
            'routers' => Router::where('is_active', true)->orderBy('name')->get(),
            'packages' => Package::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, MikroTikService $mikrotik): RedirectResponse
    {
        $data = $request->validate([
            'router_id' => [
                'required',
                Rule::exists('routers', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'package_id' => [
                'required',
                Rule::exists('packages', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'duplicate_action' => ['required', Rule::in(['skip', 'update'])],
            'sync_to_mikrotik' => ['nullable', 'boolean'],
        ]);

        try {
            $reader = IOFactory::createReaderForFile($request->file('file')->getRealPath());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($request->file('file')->getRealPath());
            $rows = $spreadsheet->getActiveSheet()->toArray(null, false, false, false);
            $spreadsheet->disconnectWorksheets();
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->withErrors(['file' => 'The spreadsheet could not be read. Confirm that it is a valid Excel or CSV file.']);
        }

        if (count($rows) < 2) {
            return back()->withInput()->withErrors(['file' => 'The spreadsheet has no customer rows.']);
        }

        $headers = $this->mapHeaders(array_shift($rows));
        foreach (['username', 'password'] as $requiredHeader) {
            if (! in_array($requiredHeader, $headers, true)) {
                return back()->withInput()->withErrors([
                    'file' => "Missing required column: {$requiredHeader}. Download the template and keep its header row.",
                ]);
            }
        }

        $router = Router::findOrFail($data['router_id']);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $synced = 0;
        $syncFailed = 0;
        $errors = [];

        foreach ($rows as $index => $values) {
            $excelRow = $index + 2;
            if ($excelRow > self::MAX_ROWS + 1) {
                $errors[] = 'Import stopped after '.self::MAX_ROWS.' data rows.';
                break;
            }

            $row = $this->associateRow($headers, $values);
            if (collect($row)->filter(fn ($value) => filled($value))->isEmpty()) {
                continue;
            }

            try {
                $customerData = $this->customerData($row, $router->id, (int) $data['package_id']);
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = "Row {$excelRow}: {$e->getMessage()}";
                continue;
            }

            $customer = Customer::where('router_id', $router->id)
                ->where('username', $customerData['username'])
                ->first();

            if ($customer && $data['duplicate_action'] === 'skip') {
                $skipped++;
                $errors[] = "Row {$excelRow}: {$customerData['username']} already exists on {$router->name}; skipped.";
                continue;
            }

            if ($customer) {
                $customer->update($customerData);
                $updated++;
            } else {
                $customer = Customer::create($customerData);
                $created++;
            }

            if ($request->boolean('sync_to_mikrotik')) {
                try {
                    $mikrotik->syncCustomer($customer);
                    $synced++;
                } catch (Throwable $e) {
                    report($e);
                    $syncFailed++;
                    $errors[] = "Row {$excelRow}: imported locally, but MikroTik sync failed for {$customer->username}: {$e->getMessage()}";
                }
            }
        }

        $summary = "Excel import complete — {$created} created, {$updated} updated, {$skipped} skipped";
        if ($request->boolean('sync_to_mikrotik')) {
            $summary .= ", {$synced} synced, {$syncFailed} sync failed";
        }
        $summary .= '.';

        return redirect()->route('customers.import.create')
            ->with($errors ? 'warning' : 'success', $summary)
            ->with('import_errors', array_slice($errors, 0, 50));
    }

    public function template(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Customers');
        $sheet->fromArray([
            ['name', 'phone', 'address', 'username', 'password', 'status', 'expires_at'],
            ['Sample Customer', '9876543210', 'Locality / address', 'customer001', 'ChangeMe123', 'active', now()->addMonth()->format('Y-m-d')],
        ]);
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
        foreach (range('A', 'G') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'zostream-customer-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function mapHeaders(array $headers): array
    {
        return array_map(function ($header) {
            $normalized = Str::of((string) $header)->trim()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

            return self::HEADER_ALIASES[$normalized] ?? null;
        }, $headers);
    }

    private function associateRow(array $headers, array $values): array
    {
        $row = [];
        foreach ($headers as $index => $header) {
            if ($header !== null) {
                $row[$header] = $values[$index] ?? null;
            }
        }

        return $row;
    }

    private function customerData(array $row, int $routerId, int $packageId): array
    {
        $username = trim((string) ($row['username'] ?? ''));
        $password = (string) ($row['password'] ?? '');
        if ($username === '') {
            throw new \InvalidArgumentException('username is required.');
        }
        if ($password === '') {
            throw new \InvalidArgumentException('password is required.');
        }

        $statusValue = Str::lower(trim((string) ($row['status'] ?? 'active')));
        $status = match ($statusValue) {
            '', 'active', 'enabled', 'yes', '1' => 'active',
            'suspended', 'disabled', 'inactive', 'no', '0' => 'suspended',
            default => throw new \InvalidArgumentException("invalid status '{$statusValue}'. Use active or suspended."),
        };

        return [
            'router_id' => $routerId,
            'package_id' => $packageId,
            'name' => trim((string) ($row['name'] ?? '')) ?: $username,
            'phone' => filled($row['phone'] ?? null) ? trim((string) $row['phone']) : null,
            'address' => filled($row['address'] ?? null) ? trim((string) $row['address']) : null,
            'username' => $username,
            'password' => $password,
            'status' => $status,
            'expires_at' => $this->parseDate($row['expires_at'] ?? null),
        ];
    }

    private function parseDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }
        if (is_numeric($value) && (float) $value > 1000) {
            return Carbon::instance(Date::excelToDateTimeObject((float) $value))->toDateString();
        }

        $value = trim((string) $value);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (Throwable) {
                // Try the next explicitly supported format.
            }
        }

        throw new \InvalidArgumentException("invalid expiry date '{$value}'. Use YYYY-MM-DD or DD/MM/YYYY.");
    }
}
