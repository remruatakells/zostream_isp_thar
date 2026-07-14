<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'phone' => 'phone',
        'mobile' => 'phone',
        'mobile_number' => 'phone',
        'phone_number' => 'phone',
        'contact' => 'phone',
        'address' => 'address',
        'installation_address' => 'address',
        'address_line1' => 'address_line1',
        'address_line2' => 'address_line2',
        'address_city' => 'address_city',
        'address_state' => 'address_state',
        'address_pin' => 'address_pin',
        'installation_address_line1' => 'installation_address_line1',
        'installation_address_line2' => 'installation_address_line2',
        'installation_address_city' => 'installation_address_city',
        'installation_address_state' => 'installation_address_state',
        'installation_address_pin' => 'installation_address_pin',
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
        'expiration_time' => 'expires_at',
        'group_name' => 'package_group',
        'sub_plan' => 'package_reference',
    ];

    public function create(): View
    {
        return view('customers.import', [
            'routers' => Router::where('is_active', true)->orderBy('name')->get(),
            'packages' => Package::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'router_id' => [
                'required',
                Rule::exists('routers', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'package_id' => [
                'nullable',
                Rule::exists('packages', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'duplicate_action' => ['required', Rule::in(['skip', 'update'])],
        ]);

        try {
            $rows = $this->readRows($request->file('file'));
        } catch (Throwable $e) {
            report($e);

            $message = in_array(strtolower($request->file('file')->getClientOriginalExtension()), ['xlsx', 'xls'], true)
                && ! class_exists(IOFactory::class)
                ? 'Excel support is not installed on this server. Run composer install, or upload a CSV file instead.'
                : 'The spreadsheet could not be read. Confirm that it is a valid Excel or CSV file.';

            return back()->withInput()->withErrors(['file' => $message]);
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

        $isJazeImport = in_array('package_reference', $headers, true)
            || in_array('package_group', $headers, true);
        if (! $isJazeImport && blank($data['package_id'] ?? null)) {
            return back()->withInput()->withErrors([
                'package_id' => 'Choose a package for a generic Excel/CSV file. Jaze exports are matched automatically from Sub_plan.',
            ]);
        }

        $router = Router::findOrFail($data['router_id']);
        $packages = Package::where('is_active', true)->get();
        if ($isJazeImport) {
            $requiredProfiles = collect($rows)
                ->map(fn (array $values) => $this->associateRow($headers, $values))
                ->pluck('package_reference')
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->unique(fn (string $value) => Str::lower($value))
                ->values();
            $availableProfiles = $packages->pluck('mikrotik_profile')
                ->map(fn (string $value) => Str::lower(trim($value)));
            $missingProfiles = $requiredProfiles->reject(
                fn (string $value) => $availableProfiles->contains(Str::lower($value))
            );
            if ($missingProfiles->isNotEmpty()) {
                return back()->withInput()->withErrors([
                    'file' => 'Import stopped before creating customers. Create and activate packages with these exact MikroTik profiles: '.$missingProfiles->implode(', ').'.',
                ]);
            }
        }
        $created = 0;
        $updated = 0;
        $skipped = 0;
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
                $package = $this->packageForRow($row, $packages, $data['package_id'] ?? null, $isJazeImport);
                $customerData = $this->customerData($row, $router->id, $package->id);
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = "Row {$excelRow}: {$e->getMessage()}";

                continue;
            }

            $customer = Customer::where('username', $customerData['username'])->first();

            if ($customer && $customer->router_id !== $router->id) {
                $skipped++;
                $errors[] = "Row {$excelRow}: {$customerData['username']} belongs to another router. RADIUS usernames must be globally unique.";

                continue;
            }

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

        }

        $summary = "Excel import complete — {$created} created, {$updated} updated, {$skipped} skipped";
        $summary .= '.';

        return redirect()->route('customers.import.create')
            ->with($errors ? 'warning' : 'success', $summary)
            ->with('import_errors', array_slice($errors, 0, 50));
    }

    public function template(): StreamedResponse
    {
        if (! class_exists(Spreadsheet::class)) {
            return response()->streamDownload(function (): void {
                $output = fopen('php://output', 'wb');
                fputcsv($output, ['name', 'phone', 'address', 'username', 'password', 'status', 'expires_at'], ',', '"', '');
                fputcsv($output, ['Sample Customer', '9876543210', 'Locality / address', 'customer001', 'ChangeMe123', 'active', now()->addMonth()->format('Y-m-d')], ',', '"', '');
                fclose($output);
            }, 'zostream-customer-import-template.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

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

    private function readRows(UploadedFile $file): array
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'csv') {
            if (! class_exists(IOFactory::class)) {
                throw new \RuntimeException('PhpSpreadsheet is required for Excel files.');
            }

            $reader = IOFactory::createReaderForFile($file->getRealPath());
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file->getRealPath());

            try {
                return $spreadsheet->getActiveSheet()->toArray(null, false, false, false);
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }

        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The CSV file could not be opened.');
        }

        try {
            $rows = [];
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($rows === [] && isset($row[0])) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                }
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
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
        if (mb_strlen($username) > 64) {
            throw new \InvalidArgumentException('username must not exceed 64 characters for RADIUS.');
        }
        if ($password === '') {
            throw new \InvalidArgumentException('password is required.');
        }

        $statusValue = Str::lower(trim((string) ($row['status'] ?? 'active')));
        $status = match ($statusValue) {
            '', 'active', 'enabled', 'yes', '1' => 'active',
            'suspended', 'disabled', 'inactive', 'expired', 'blacklisted', 'no', '0' => 'suspended',
            default => throw new \InvalidArgumentException("invalid status '{$statusValue}'. Use active or suspended."),
        };

        $name = trim(implode(' ', array_filter([
            trim((string) ($row['first_name'] ?? '')),
            trim((string) ($row['last_name'] ?? '')),
        ])));
        $name = trim((string) ($row['name'] ?? '')) ?: ($name ?: $username);

        $address = trim((string) ($row['address'] ?? ''));
        if ($address === '') {
            $address = implode(', ', array_values(array_unique(array_filter(array_map(
                fn ($value) => trim((string) $value),
                [
                    $row['installation_address_line1'] ?? null,
                    $row['installation_address_line2'] ?? null,
                    $row['installation_address_city'] ?? null,
                    $row['installation_address_state'] ?? null,
                    $row['installation_address_pin'] ?? null,
                    $row['address_line1'] ?? null,
                    $row['address_line2'] ?? null,
                    $row['address_city'] ?? null,
                    $row['address_state'] ?? null,
                    $row['address_pin'] ?? null,
                ],
            )))));
        }

        return [
            'router_id' => $routerId,
            'package_id' => $packageId,
            'name' => $name,
            'phone' => filled($row['phone'] ?? null) ? trim((string) $row['phone']) : null,
            'address' => $address !== '' ? $address : null,
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
        foreach (['Y-m-d H:i:s', 'd/m/Y H:i:s', 'd-m-Y H:i:s', 'm/d/Y H:i:s', 'Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (Throwable) {
                // Try the next explicitly supported format.
            }
        }

        throw new \InvalidArgumentException("invalid expiry date '{$value}'. Use YYYY-MM-DD, DD/MM/YYYY or the Jaze DD-MM-YYYY HH:MM:SS format.");
    }

    private function packageForRow(array $row, $packages, mixed $fallbackPackageId, bool $isJazeImport): Package
    {
        if (! $isJazeImport) {
            return $packages->firstWhere('id', (int) $fallbackPackageId)
                ?? throw new \InvalidArgumentException('the selected package is not active.');
        }

        $reference = trim((string) ($row['package_reference'] ?? ''));
        $group = trim((string) ($row['package_group'] ?? ''));
        $package = $packages->first(
            fn (Package $candidate) => $reference !== ''
                && Str::lower($candidate->mikrotik_profile) === Str::lower($reference)
        );
        $package ??= $packages->first(
            fn (Package $candidate) => $reference === '' && $group !== ''
                && (Str::lower($candidate->name) === Str::lower($group)
                    || Str::lower($candidate->mikrotik_profile) === Str::lower($group))
        );

        if ($package) {
            return $package;
        }

        $label = $reference !== '' ? "Sub_plan '{$reference}'" : "Group_name '{$group}'";

        throw new \InvalidArgumentException("no active admin package matches {$label}. Set the package MikroTik profile to the exact Jaze Sub_plan value.");
    }
}
