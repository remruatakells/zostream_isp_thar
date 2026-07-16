<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

    private const PACKAGE_ALIASES = [
        'wis_special_20m' => 'zostream-starter',
    ];

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
        'branch' => 'branch',
        'branch_name' => 'branch',
        'area' => 'area',
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
        'running_package' => 'session_package_reference',
        'base_package' => 'session_base_package_reference',
        'start_time' => 'session_started_at',
        'online_time' => 'session_online_time',
        'download' => 'session_download',
        'upload' => 'session_upload',
        'ipaddress' => 'session_ip_address',
        'mac' => 'session_mac_address',
        'nas_ip' => 'session_nas_ip',
        'server_name' => 'session_server_name',
        'nas_port_id' => 'session_nas_port_id',
        'sessionid' => 'session_id',
        'protocol' => 'session_protocol',
        'protocal' => 'session_protocol',
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
        $isSessionHistoryImport = in_array('session_id', $headers, true)
            && in_array('session_nas_port_id', $headers, true);
        $requiredHeaders = $isSessionHistoryImport ? ['username'] : ['username', 'password'];
        foreach ($requiredHeaders as $requiredHeader) {
            if (! in_array($requiredHeader, $headers, true)) {
                return back()->withInput()->withErrors([
                    'file' => "Missing required column: {$requiredHeader}. Download the template and keep its header row.",
                ]);
            }
        }

        $router = Router::findOrFail($data['router_id']);
        if ($isSessionHistoryImport) {
            return $this->importSessionHistory(
                $rows,
                $headers,
                $router,
                $data['duplicate_action'],
                $data['package_id'] ?? null,
            );
        }

        $isJazeImport = in_array('package_reference', $headers, true)
            || in_array('package_group', $headers, true);
        if (! $isJazeImport && blank($data['package_id'] ?? null)) {
            return back()->withInput()->withErrors([
                'package_id' => 'Choose a package for a generic Excel/CSV file. Jaze exports are matched automatically from Sub_plan.',
            ]);
        }

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
                fn (string $value) => $availableProfiles->contains(
                    $this->packageProfileForReference($value)
                )
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
                $customerData = $this->customerData($row, $router->id, $package);
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
                fputcsv($output, ['name', 'phone', 'branch', 'address', 'username', 'password', 'status', 'expires_at'], ',', '"', '');
                fputcsv($output, ['Sample Customer', '9876543210', 'Ngopa', 'Locality / address', 'customer001', 'ChangeMe123', 'active', now()->addMonth()->format('Y-m-d')], ',', '"', '');
                fclose($output);
            }, 'zostream-customer-import-template.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Customers');
        $sheet->fromArray([
            ['name', 'phone', 'branch', 'address', 'username', 'password', 'status', 'expires_at'],
            ['Sample Customer', '9876543210', 'Ngopa', 'Locality / address', 'customer001', 'ChangeMe123', 'active', now()->addMonth()->format('Y-m-d')],
        ]);
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        foreach (range('A', 'H') as $column) {
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

    private function customerData(array $row, int $routerId, Package $package): array
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

        $branch = $this->branchForRow($row);
        $this->assignPackageToBranch($branch, $package);

        return [
            'router_id' => $routerId,
            'package_id' => $package->id,
            'name' => $name,
            'phone' => filled($row['phone'] ?? null) ? trim((string) $row['phone']) : null,
            'branch_id' => $branch?->id,
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

    private function branchIdForName(mixed $value): ?int
    {
        $name = trim((string) $value);
        if ($name === '') {
            return null;
        }

        $branch = Branch::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();

        return ($branch ?? Branch::create(['name' => $name, 'is_active' => true]))->id;
    }

    private function branchForRow(array $row): ?Branch
    {
        foreach ([
            $row['branch'] ?? null,
            $row['installation_address_city'] ?? null,
            $row['address_city'] ?? null,
            $row['area'] ?? null,
        ] as $candidate) {
            $name = trim((string) $candidate);
            if ($name === '') {
                continue;
            }

            $branchId = $this->branchIdForName($name);

            return $branchId ? Branch::find($branchId) : null;
        }

        return null;
    }

    private function assignPackageToBranch(?Branch $branch, Package $package): void
    {
        if ($branch) {
            $branch->packages()->syncWithoutDetaching([$package->id]);
        }
    }

    private function packageForRow(array $row, $packages, mixed $fallbackPackageId, bool $isJazeImport): Package
    {
        if (! $isJazeImport) {
            return $packages->firstWhere('id', (int) $fallbackPackageId)
                ?? throw new \InvalidArgumentException('the selected package is not active.');
        }

        $reference = trim((string) ($row['package_reference'] ?? ''));
        $group = trim((string) ($row['package_group'] ?? ''));
        $profileReference = $this->packageProfileForReference($reference);
        $package = $packages->first(
            fn (Package $candidate) => $reference !== ''
                && Str::lower($candidate->mikrotik_profile) === $profileReference
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

    private function importSessionHistory(
        array $rows,
        array $headers,
        Router $router,
        string $duplicateAction,
        mixed $fallbackPackageId,
    ): RedirectResponse
    {
        $packages = Package::where('is_active', true)->get();
        $fallbackPackage = filled($fallbackPackageId)
            ? $packages->firstWhere('id', (int) $fallbackPackageId)
            : null;
        $routerUsernames = Customer::where('router_id', $router->id)->pluck('username');
        DB::table('radacct')
            ->where('class', 'jaze-session-import')
            ->whereNull('acctstoptime')
            ->whereIn('username', $routerUsernames)
            ->update(['acctstoptime' => now(), 'acctupdatetime' => now()]);

        $imported = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $values) {
            $csvRow = $index + 2;
            if ($csvRow > self::MAX_ROWS + 1) {
                $errors[] = 'Import stopped after '.self::MAX_ROWS.' data rows.';
                break;
            }

            $row = $this->associateRow($headers, $values);
            if (collect($row)->filter(fn ($value) => filled($value))->isEmpty()) {
                continue;
            }

            $username = trim((string) ($row['username'] ?? ''));
            $sessionId = trim((string) ($row['session_id'] ?? ''));
            $nasPortId = trim((string) ($row['session_nas_port_id'] ?? ''));
            if ($username === '' || $sessionId === '' || $nasPortId === '') {
                $skipped++;
                $errors[] = "Row {$csvRow}: Username, SessionId and Nas Port Id are required.";
                continue;
            }

            try {
                $startedAt = $this->parseSessionStart($row['session_started_at'] ?? null);
                $sessionSeconds = $this->parseSessionDuration($row['session_online_time'] ?? null);
                $nasIp = trim((string) ($row['session_nas_ip'] ?? '')) ?: $router->host;
                $uniqueId = md5('jaze|'.$nasIp.'|'.$sessionId.'|'.$username);

                $customer = Customer::where('username', $username)->first();
                if ($customer && $customer->router_id !== $router->id) {
                    throw new \InvalidArgumentException("{$username} belongs to another router; skipped.");
                }

                if (! $customer) {
                    $package = $this->packageForSessionRow($row, $packages, $fallbackPackage);
                    $branch = $this->branchForRow($row);
                    $this->assignPackageToBranch($branch, $package);
                    $customer = Customer::create([
                        'router_id' => $router->id,
                        'package_id' => $package->id,
                        'name' => trim((string) ($row['name'] ?? '')) ?: $username,
                        'phone' => filled($row['phone'] ?? null)
                            ? preg_replace('/\s+/', '', trim((string) $row['phone']))
                            : null,
                        'branch_id' => $branch?->id,
                        'address' => null,
                        'username' => $username,
                        'password' => 'password',
                        'status' => 'active',
                        'expires_at' => today()->addDays($package->validity_days)->toDateString(),
                    ]);
                    $created++;
                } else {
                    $customerChanges = [];
                    if ($duplicateAction === 'update') {
                        if (filled($row['name'] ?? null)) {
                            $customerChanges['name'] = trim((string) $row['name']);
                        }
                        if (filled($row['phone'] ?? null)) {
                            $customerChanges['phone'] = preg_replace('/\s+/', '', trim((string) $row['phone']));
                        }
                    }
                    $branch = $this->branchForRow($row);
                    if ($branch) {
                        $customerChanges['branch_id'] = $branch?->id;
                        $this->assignPackageToBranch($branch, $customer->package);
                    }
                    if ($customerChanges !== []) {
                        $customer->update($customerChanges);
                        $updated++;
                    }
                }

                DB::table('radacct')->updateOrInsert(
                    ['acctuniqueid' => $uniqueId],
                    [
                        'router_id' => $router->id,
                        'acctsessionid' => $sessionId,
                        'username' => $username,
                        'realm' => null,
                        'nasipaddress' => $nasIp,
                        'nasportid' => $nasPortId,
                        'nasporttype' => 'Ethernet',
                        'acctstarttime' => $startedAt,
                        'acctupdatetime' => now(),
                        'acctstoptime' => null,
                        'acctinterval' => 300,
                        'acctsessiontime' => $sessionSeconds,
                        'acctauthentic' => 'RADIUS',
                        'acctinputoctets' => $this->parseTrafficBytes($row['session_upload'] ?? null),
                        'acctoutputoctets' => $this->parseTrafficBytes($row['session_download'] ?? null),
                        'calledstationid' => trim((string) ($row['session_server_name'] ?? '')) ?: null,
                        'callingstationid' => trim((string) ($row['session_mac_address'] ?? '')) ?: null,
                        'servicetype' => 'Framed-User',
                        'framedprotocol' => Str::upper(trim((string) ($row['session_protocol'] ?? ''))) === 'PPPOE' ? 'PPP' : null,
                        'framedipaddress' => trim((string) ($row['session_ip_address'] ?? '')) ?: null,
                        'class' => 'jaze-session-import',
                    ],
                );
                $imported++;
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = "Row {$csvRow}: {$e->getMessage()}";
            }
        }

        $summary = "Session history import complete — {$imported} sessions imported, {$created} customers created, {$updated} updated, {$skipped} skipped.";

        return redirect()->route('customers.import.create')
            ->with($errors ? 'warning' : 'success', $summary)
            ->with('import_errors', array_slice($errors, 0, 50));
    }

    private function packageForSessionRow($row, $packages, ?Package $fallbackPackage): Package
    {
        $reference = trim((string) (
            $row['session_package_reference']
            ?? $row['session_base_package_reference']
            ?? ''
        ));
        $profileReference = $this->packageProfileForReference($reference);
        $referenceTokens = $this->planTokens($reference);

        $package = $packages->first(fn (Package $candidate) => $reference !== '' && (
            Str::lower(trim($candidate->name)) === Str::lower($reference)
            || Str::lower(trim($candidate->mikrotik_profile)) === $profileReference
        ));
        $package ??= $packages->first(function (Package $candidate) use ($referenceTokens): bool {
            $nameTokens = $this->planTokens($candidate->name);

            return $referenceTokens !== []
                && $nameTokens !== []
                && collect($nameTokens)->every(fn (string $token) => in_array($token, $referenceTokens, true));
        });
        $package ??= $fallbackPackage;

        if ($package) {
            return $package;
        }

        $label = $reference !== '' ? "Running Package '{$reference}'" : 'the blank Running Package';
        throw new \InvalidArgumentException("no active admin package matches {$label}. Choose a Default package and import again.");
    }

    private function packageProfileForReference(string $reference): string
    {
        $normalized = Str::of($reference)
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        return self::PACKAGE_ALIASES[$normalized] ?? Str::lower(trim($reference));
    }

    private function planTokens(string $value): array
    {
        return array_values(array_filter(explode('_', Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString())));
    }

    private function parseSessionStart(mixed $value): string
    {
        $value = trim((string) $value);
        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'Y-m-d H:i:s'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->toDateTimeString();
                }
            } catch (Throwable) {
                // Try the next format.
            }
        }

        throw new \InvalidArgumentException("invalid Start Time '{$value}'.");
    }

    private function parseSessionDuration(mixed $value): int
    {
        preg_match_all('/(\d+)\s*([dhms])/i', trim((string) $value), $matches, PREG_SET_ORDER);
        if ($matches === []) {
            return 0;
        }

        $seconds = 0;
        foreach ($matches as $match) {
            $seconds += (int) $match[1] * match (Str::lower($match[2])) {
                'd' => 86400,
                'h' => 3600,
                'm' => 60,
                default => 1,
            };
        }

        return $seconds;
    }

    private function parseTrafficBytes(mixed $value): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }
        if (! preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(KB|MB|GB|TB|B)?$/i', $value, $matches)) {
            throw new \InvalidArgumentException("invalid traffic value '{$value}'.");
        }

        $multiplier = match (Str::upper($matches[2] ?? 'B')) {
            'KB' => 1024,
            'MB' => 1024 ** 2,
            'GB' => 1024 ** 3,
            'TB' => 1024 ** 4,
            default => 1,
        };

        return (int) round((float) $matches[1] * $multiplier);
    }
}
