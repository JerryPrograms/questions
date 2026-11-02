<?php

namespace App\Http\Controllers;

use App\Support\AuditLog;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv as CsvWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class ImportController extends Controller
{
    private const HEADERS = ['LOCATION', 'BANK NAME', 'DATE', 'CHECK NO', 'PAID TO/BY', 'ACCOUNT', 'CREDIT', 'DEBIT', 'DESCRIPTION', 'CLASS'];

    public function create(): View
    {
        return view('admin.imports.create', [
            'imports' => DB::table('imports')->orderByDesc('id')->limit(8)->get(),
        ]);
    }

    public function index(): View
    {
        return view('admin.imports.index');
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $query = DB::table('imports')
            ->select('id', 'uploaded_by', 'file_name', 'sha256', 'row_count', 'created_at', 'updated_at')
            ->orderByDesc('id');

        if (! empty($data['search'])) {
            $query->where('file_name', 'like', '%'.$data['search'].'%');
        }

        return response()->json($query->paginate($data['per_page'] ?? 25));
    }

    public function apiAllData(Request $request): JsonResponse
    {
        $data = $request->validate([
            'import_id' => ['nullable', 'integer', Rule::exists('imports', 'id')],
            'location' => ['nullable', 'string', 'max:255'],
            'txn_id' => ['nullable', 'string', 'max:255'],
            'answered' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = DB::table('accounting_entries')
            ->join('imports', 'imports.id', '=', 'accounting_entries.import_id')
            ->leftJoin('users as answering_users', 'answering_users.id', '=', 'accounting_entries.answered_by')
            ->select(
                'accounting_entries.*',
                'imports.file_name as import_file_name',
                'imports.sha256 as import_sha256',
                'imports.uploaded_by as import_uploaded_by',
                'imports.created_at as imported_at',
                'answering_users.name as answered_by_name',
                'answering_users.email as answered_by_email',
            )
            ->orderByDesc('accounting_entries.id');

        foreach (['import_id', 'location', 'txn_id'] as $filter) {
            if (isset($data[$filter]) && $data[$filter] !== '') {
                $query->where('accounting_entries.'.$filter, $data[$filter]);
            }
        }

        if (array_key_exists('answered', $data)) {
            $data['answered']
                ? $query->whereNotNull('accounting_entries.answered_at')
                : $query->whereNull('accounting_entries.answered_at');
        }

        if (! empty($data['search'])) {
            $query->where(function ($query) use ($data): void {
                foreach (['txn_id', 'location', 'bank_name', 'check_no', 'paid_to_by', 'account', 'description', 'answer'] as $column) {
                    $query->orWhere('accounting_entries.'.$column, 'like', '%'.$data['search'].'%');
                }
                $query->orWhere('imports.file_name', 'like', '%'.$data['search'].'%');
            });
        }

        return response()->json($query->paginate($data['per_page'] ?? 25));
    }

    public function apiStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'import_key' => ['required', 'string', 'max:255'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'rows' => ['required', 'array', 'min:1', 'max:20000'],
            'rows.*.txn_id' => ['required', 'string', 'distinct', 'max:255'],
            'rows.*.location' => ['required', 'string', 'max:255'],
            'rows.*.bank_name' => ['required', 'string', 'max:255'],
            'rows.*.transaction_date' => ['required', 'string'],
            'rows.*.check_no' => ['nullable', 'string', 'max:255'],
            'rows.*.paid_to_by' => ['required', 'string', 'max:255'],
            'rows.*.account' => ['required', 'string', 'max:255'],
            'rows.*.credit' => ['nullable', 'numeric', 'min:0'],
            'rows.*.debit' => ['nullable', 'numeric', 'min:0'],
            'rows.*.description' => ['required', 'string', 'max:10000'],
            'rows.*.class_name' => ['required', 'string', 'max:255'],
            'rows.*.sheet_name' => ['nullable', 'string', 'max:255'],
        ]);
        $rows = [];

        foreach ($data['rows'] as $index => $row) {
            $label = 'Row '.($index + 1);
            $credit = $this->amount(isset($row['credit']) ? (string) $row['credit'] : '', $label, 'credit');
            $debit = $this->amount(isset($row['debit']) ? (string) $row['debit'] : '', $label, 'debit');

            if (($credit === null) === ($debit === null)) {
                throw ValidationException::withMessages(["rows.{$index}" => 'Enter either credit or debit.']);
            }

            $rows[] = [
                'sheet_name' => $row['sheet_name'] ?? 'API',
                'row_number' => $index + 1,
                'txn_id' => $row['txn_id'],
                'location' => $row['location'],
                'bank_name' => $row['bank_name'],
                'transaction_date' => $this->date($row['transaction_date'], $label),
                'check_no' => $row['check_no'] ?? null,
                'paid_to_by' => $row['paid_to_by'],
                'account' => $row['account'],
                'credit' => $credit,
                'debit' => $debit,
                'description' => $row['description'],
                'class_name' => $row['class_name'],
            ];
        }

        [$importId, $created] = $this->upsertImport(
            $request,
            $data['file_name'] ?? $data['import_key'],
            hash('sha256', 'api:'.$data['import_key']),
            $rows,
        );

        return response()->json([
            'data' => DB::table('imports')->find($importId),
            'created' => $created,
            'message' => count($rows).' rows imported.',
        ], $created ? 201 : 200);
    }

    public function apiShow(int $import): JsonResponse
    {
        $uploadedFile = DB::table('imports')->find($import);
        abort_if($uploadedFile === null, 404);

        return response()->json(['data' => $uploadedFile]);
    }

    public function apiEntries(Request $request, int $import): JsonResponse
    {
        abort_unless(DB::table('imports')->where('id', $import)->exists(), 404);
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'between:1,100']]);

        return response()->json(
            DB::table('accounting_entries')
                ->where('import_id', $import)
                ->orderBy('sheet_name')
                ->orderBy('row_number')
                ->paginate($data['per_page'] ?? 25),
        );
    }

    public function apiUpdate(Request $request, int $import): JsonResponse
    {
        $uploadedFile = DB::table('imports')->find($import);
        abort_if($uploadedFile === null, 404);
        $data = $request->validate(['file_name' => ['required', 'string', 'max:255']]);

        DB::table('imports')->where('id', $import)->update([
            'file_name' => $data['file_name'],
            'updated_at' => now(),
        ]);
        AuditLog::record($request, 'import.updated', 'Renamed an imported file.', 'import', $import, null, [
            'file_name' => $uploadedFile->file_name,
        ], [
            'file_name' => $data['file_name'],
        ]);

        return response()->json(['data' => DB::table('imports')->find($import)]);
    }

    public function apiDestroy(Request $request, int $import): JsonResponse
    {
        $uploadedFile = DB::table('imports')->find($import);
        abort_if($uploadedFile === null, 404);
        $detailFiles = DB::table('accounting_entries')
            ->where('import_id', $import)
            ->whereNotNull('detail_file_path')
            ->pluck('detail_file_path')
            ->all();

        DB::transaction(function () use ($import, $request, $uploadedFile): void {
            DB::table('imports')->where('id', $import)->delete();
            AuditLog::record($request, 'import.deleted', 'Deleted '.$uploadedFile->file_name.' and its imported rows.', 'import', $import, null, [
                'file_name' => $uploadedFile->file_name,
                'row_count' => $uploadedFile->row_count,
            ]);
        });
        Storage::disk('local')->delete($detailFiles);

        return response()->json(status: 204);
    }

    public function indexData(Request $request): JsonResponse
    {
        $parameters = $request->validate([
            'draw' => ['required', 'integer', 'min:0'],
            'start' => ['required', 'integer', 'min:0'],
            'length' => ['required', 'integer', 'in:10,25,50,100'],
            'search.value' => ['nullable', 'string', 'max:255'],
            'order.0.column' => ['nullable', 'integer', 'between:0,2'],
            'order.0.dir' => ['nullable', 'in:asc,desc'],
        ]);

        $query = DB::table('imports');
        $total = (clone $query)->count();
        $search = $parameters['search']['value'] ?? null;

        if ($search !== null && $search !== '') {
            $query->where('file_name', 'like', '%'.$search.'%');
        }

        $filtered = (clone $query)->count();
        $order = $parameters['order'][0] ?? ['column' => 2, 'dir' => 'desc'];
        $orderColumn = ['file_name', 'row_count', 'created_at'][$order['column']];

        return response()->json([
            'draw' => $parameters['draw'],
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $query->select('id', 'file_name', 'row_count', 'created_at')
                ->orderBy($orderColumn, $order['dir'])
                ->offset($parameters['start'])
                ->limit($parameters['length'])
                ->get(),
        ]);
    }

    public function show(int $import): View
    {
        $uploadedFile = DB::table('imports')->find($import);
        abort_if($uploadedFile === null, 404);

        return view('admin.imports.show', ['uploadedFile' => $uploadedFile]);
    }

    public function showData(Request $request, int $import): JsonResponse
    {
        abort_unless(DB::table('imports')->where('id', $import)->exists(), 404);

        $parameters = $request->validate([
            'draw' => ['required', 'integer', 'min:0'],
            'start' => ['required', 'integer', 'min:0'],
            'length' => ['required', 'integer', 'in:10,25,50,100'],
            'search.value' => ['nullable', 'string', 'max:255'],
            'order.0.column' => ['nullable', 'integer', 'between:0,7'],
            'order.0.dir' => ['nullable', 'in:asc,desc'],
        ]);

        $query = DB::table('accounting_entries')->where('import_id', $import);
        $total = (clone $query)->count();
        $search = $parameters['search']['value'] ?? null;

        if ($search !== null && $search !== '') {
            $query->where(function ($query) use ($search): void {
                foreach (['transaction_date', 'bank_name', 'check_no', 'paid_to_by', 'account', 'description'] as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        $filtered = (clone $query)->count();
        $order = $parameters['order'][0] ?? ['column' => 0, 'dir' => 'asc'];
        $orderColumn = ['transaction_date', 'bank_name', 'check_no', 'paid_to_by', 'account', 'credit', 'debit', 'description'][$order['column']];

        return response()->json([
            'draw' => $parameters['draw'],
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $query
                ->select('transaction_date', 'bank_name', 'check_no', 'paid_to_by', 'account', 'credit', 'debit', 'description')
                ->orderBy($orderColumn, $order['dir'])
                ->offset($parameters['start'])
                ->limit($parameters['length'])
                ->get(),
        ]);
    }

    public function export(Request $request, int $import): BinaryFileResponse
    {
        $uploadedFile = DB::table('imports')->find($import);
        abort_if($uploadedFile === null, 404);

        $extension = strtolower(pathinfo($uploadedFile->file_name, PATHINFO_EXTENSION));
        abort_unless(in_array($extension, ['csv', 'xlsx'], true), 422);

        $entries = DB::table('accounting_entries')
            ->leftJoin('users as answering_users', 'answering_users.id', '=', 'accounting_entries.answered_by')
            ->select('accounting_entries.*', 'answering_users.name as answered_by_name')
            ->where('import_id', $import)
            ->orderBy('sheet_name')
            ->orderBy('row_number')
            ->get();
        $spreadsheet = new Spreadsheet;
        $groups = $extension === 'xlsx' ? $entries->groupBy('sheet_name') : collect(['Export' => $entries]);

        foreach ($groups->values() as $index => $rows) {
            $sheet = $index === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle($extension === 'xlsx' ? $rows->first()->sheet_name : 'Export');
            $this->writeExport($sheet, $rows, $extension === 'csv');
        }

        $downloadName = pathinfo($uploadedFile->file_name, PATHINFO_FILENAME).'-export.'.$extension;

        $response = $this->downloadExport($spreadsheet, $extension, $downloadName);
        DB::table('accounting_entries')
            ->whereIn('id', $entries->whereNotNull('answered_at')->pluck('id'))
            ->update(['answer_exported_at' => now()]);
        AuditLog::record($request, 'import.exported', 'Exported '.$uploadedFile->file_name.' with '.$entries->count().' rows.', 'import', $import, null, null, [
            'file_name' => $downloadName,
            'row_count' => $entries->count(),
        ]);

        return $response;
    }

    public function exportAnswered(Request $request): BinaryFileResponse
    {
        $entries = DB::table('accounting_entries')
            ->leftJoin('users as answering_users', 'answering_users.id', '=', 'accounting_entries.answered_by')
            ->select('accounting_entries.*', 'answering_users.name as answered_by_name')
            ->whereNotNull('accounting_entries.answered_at')
            ->orderBy('accounting_entries.location')
            ->orderBy('accounting_entries.transaction_date')
            ->orderBy('accounting_entries.id')
            ->get();
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Answered');
        $this->writeExport($sheet, $entries, false);

        $response = $this->downloadExport($spreadsheet, 'xlsx', 'answered-questions.xlsx');
        DB::table('accounting_entries')
            ->whereIn('id', $entries->pluck('id'))
            ->update(['answer_exported_at' => now()]);
        AuditLog::record($request, 'question.answers_exported', 'Exported '.$entries->count().' answered questions.', 'question_export', null, null, null, [
            'file_name' => 'answered-questions.xlsx',
            'row_count' => $entries->count(),
        ]);

        return $response;
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv']]);

        $file = $request->file('file');
        $path = $file->getPathname();
        $hash = hash_file('sha256', $path);

        $isExcel = strtolower($file->getClientOriginalExtension()) === 'xlsx';

        if ($isExcel) {
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw ValidationException::withMessages(['file' => 'The Excel file is not valid.']);
            }

            if ($zip->numFiles > 200) {
                $zip->close();
                throw ValidationException::withMessages(['file' => 'The Excel file is too large to process.']);
            }

            $totalSize = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if ($entry === false) {
                    $zip->close();
                    throw ValidationException::withMessages(['file' => 'The Excel file is not valid.']);
                }
                $totalSize += $entry['size'];
                if ($totalSize > 25 * 1024 * 1024) {
                    $zip->close();
                    throw ValidationException::withMessages(['file' => 'The Excel file is too large to process.']);
                }
            }
            $zip->close();
        }

        $reader = $isExcel ? new Xlsx : new Csv;
        $reader->setReadDataOnly(true);
        if ($reader instanceof Csv) {
            $reader->setDelimiter(',');
        }

        try {
            $spreadsheet = $reader->load($path);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'The file could not be read as Excel or CSV.']);
        }

        $rows = [];

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $highestColumn = $sheet->getHighestDataColumn();
            $hasTxnId = $highestColumn === 'K';
            $columns = range('A', $hasTxnId ? 'K' : 'J');
            $headers = [];
            foreach ($columns as $column) {
                $headers[] = strtoupper(trim(ltrim((string) $sheet->getCell($column.'1')->getValue(), "\xEF\xBB\xBF")));
            }
            $validTxnIdHeader = ! $hasTxnId || in_array($headers[10], ['TXNID', 'TXN ID'], true);

            if (array_slice($headers, 0, 10) !== self::HEADERS || ! in_array($highestColumn, ['J', 'K'], true) || ! $validTxnIdHeader) {
                throw ValidationException::withMessages(['file' => "Sheet {$sheet->getTitle()} must contain the sample columns in order, with TXNID as the optional eleventh column."]);
            }

            for ($rowNumber = 2; $rowNumber <= $sheet->getHighestDataRow(); $rowNumber++) {
                $values = [];
                foreach ($columns as $column) {
                    $cell = $sheet->getCell($column.$rowNumber);
                    if ($cell->isFormula()) {
                        throw ValidationException::withMessages(['file' => "Sheet {$sheet->getTitle()}, row {$rowNumber}: formulas are not supported."]);
                    }
                    $values[] = trim((string) $cell->getValue());
                }

                if (count(array_filter($values, fn (string $value): bool => $value !== '')) === 0) {
                    continue;
                }

                $label = "Sheet {$sheet->getTitle()}, row {$rowNumber}";
                $credit = $this->amount($values[6], $label, 'credit');
                $debit = $this->amount($values[7], $label, 'debit');
                if (($credit === null) === ($debit === null)) {
                    throw ValidationException::withMessages(['file' => "{$label}: enter either credit or debit."]);
                }

                $rows[] = [
                    'sheet_name' => $sheet->getTitle(),
                    'row_number' => $rowNumber,
                    'txn_id' => ! $hasTxnId || $values[10] === '' ? null : $this->requiredText($values[10], $label, 'transaction ID'),
                    'location' => $this->requiredText($values[0], $label, 'location'),
                    'bank_name' => $this->requiredText($values[1], $label, 'bank name'),
                    'transaction_date' => $this->date($values[2], $label),
                    'check_no' => $values[3] === '' ? null : $this->requiredText($values[3], $label, 'check no'),
                    'paid_to_by' => $this->requiredText($values[4], $label, 'paid to/by'),
                    'account' => $this->requiredText($values[5], $label, 'account'),
                    'credit' => $credit,
                    'debit' => $debit,
                    'description' => $this->requiredText($values[8], $label, 'description', 10000),
                    'class_name' => $this->requiredText($values[9], $label, 'class'),
                ];

                if (count($rows) > 20000) {
                    throw ValidationException::withMessages(['file' => 'Import a maximum of 20,000 rows at a time.']);
                }
            }
        }
        $spreadsheet->disconnectWorksheets();

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The file contains no data rows.']);
        }

        [$importId, $created] = $this->upsertImport($request, $file->getClientOriginalName(), $hash, $rows);

        $message = count($rows).' rows imported.';

        $request->session()->flash('status', $message);

        return $request->expectsJson()
            ? response()->json(['redirect' => route('admin.imports.show', $importId), 'message' => $message])
            : redirect()->route('admin.imports.show', $importId)->with('status', $message);
    }

    /**
     * @param  list<array<string, int|string|null>>  $rows
     * @return array{0: int, 1: bool}
     */
    private function upsertImport(Request $request, string $fileName, string $hash, array $rows): array
    {
        $created = ! DB::table('imports')->where('sha256', $hash)->exists();
        $fileName = mb_substr($fileName, 0, 255);
        $importId = DB::transaction(function () use ($request, $fileName, $hash, $rows, $created): int {
            $now = now();
            DB::table('imports')->upsert([[
                'uploaded_by' => $request->user()->id,
                'file_name' => $fileName,
                'sha256' => $hash,
                'row_count' => count($rows),
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['sha256'], ['uploaded_by', 'file_name', 'row_count', 'updated_at']);
            $importId = (int) DB::table('imports')->where('sha256', $hash)->value('id');

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('accounting_entries')->upsert(array_map(
                    fn (array $row): array => $row + ['import_id' => $importId, 'created_at' => $now, 'updated_at' => $now],
                    $chunk,
                ), ['import_id', 'sheet_name', 'row_number'], [
                    'location',
                    'bank_name',
                    'transaction_date',
                    'check_no',
                    'paid_to_by',
                    'account',
                    'credit',
                    'debit',
                    'description',
                    'class_name',
                    'txn_id',
                    'updated_at',
                ]);
            }
            AuditLog::record($request, $created ? 'import.created' : 'import.upserted', ($created ? 'Imported ' : 'Re-imported ').$fileName.' with '.count($rows).' rows.', 'import', $importId, null, null, [
                'file_name' => $fileName,
                'row_count' => count($rows),
                'locations' => array_values(array_unique(array_column($rows, 'location'))),
            ]);

            return $importId;
        });

        return [$importId, $created];
    }

    private function requiredText(string $value, string $label, string $field, int $maxLength = 255): string
    {
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw ValidationException::withMessages(['file' => "{$label}: {$field} is missing or too long."]);
        }

        return $value;
    }

    private function date(string $value, string $label): string
    {
        if (is_numeric($value)) {
            if ((float) $value < 1 || (float) $value > 73415) {
                throw ValidationException::withMessages(['file' => "{$label}: date is out of range."]);
            }
            $date = Date::excelToDateTimeObject((float) $value);
        } else {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value)
                ?: DateTimeImmutable::createFromFormat('!m/d/Y', $value);
            if (DateTimeImmutable::getLastErrors() !== false) {
                $date = false;
            }
        }

        if ($date === false || $date === null || (int) $date->format('Y') < 1900 || (int) $date->format('Y') > 2100) {
            throw ValidationException::withMessages(['file' => "{$label}: date must be an Excel date, YYYY-MM-DD, or MM/DD/YYYY."]);
        }

        return $date->format('Y-m-d');
    }

    private function amount(string $value, string $label, string $field): ?string
    {
        if ($value === '') {
            return null;
        }

        $normalized = str_replace(['$', ','], '', $value);
        if (! preg_match('/^\d+(?:\.\d+)?$/D', $normalized)) {
            throw ValidationException::withMessages(['file' => "{$label}: {$field} must be a non-negative amount."]);
        }

        $amount = BigDecimal::of($normalized)->toScale(2, RoundingMode::HalfUp);
        if ($amount->compareTo('99999999999999.99') > 0) {
            throw ValidationException::withMessages(['file' => "{$label}: {$field} is out of range."]);
        }

        return (string) $amount;
    }

    /** @param iterable<int, object> $entries */
    private function writeExport(Worksheet $sheet, iterable $entries, bool $isCsv): void
    {
        $this->writeExportRow($sheet, 1, [...self::HEADERS, 'TXNID', 'ANSWER', 'ANSWERED BY', 'ANSWERED AT', 'ARCHIVED AT'], $isCsv);

        $row = 2;
        foreach ($entries as $entry) {
            $answer = $entry->answer === null ? null : preg_replace('/\R+/', "\n", $entry->answer);
            $this->writeExportRow($sheet, $row, [
                $entry->location,
                $entry->bank_name,
                $entry->transaction_date,
                $entry->check_no,
                $entry->paid_to_by,
                $entry->account,
                $entry->credit,
                $entry->debit,
                $entry->description,
                $entry->class_name,
                $entry->txn_id,
                $answer,
                $entry->answered_by_name,
                $entry->answered_at,
                $entry->archived_at,
            ], $isCsv);

            if (! $isCsv && $entry->answer !== null) {
                $sheet->getStyle('L'.$row)->getAlignment()->setWrapText(true);
                $sheet->getRowDimension($row)->setRowHeight(-1);
            }

            $row++;
        }
    }

    private function downloadExport(Spreadsheet $spreadsheet, string $extension, string $downloadName): BinaryFileResponse
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'questions-export-');
        abort_if($temporaryPath === false, 500);

        $writer = $extension === 'xlsx' ? new XlsxWriter($spreadsheet) : new CsvWriter($spreadsheet);
        $writer->save($temporaryPath);
        $spreadsheet->disconnectWorksheets();

        return response()->download($temporaryPath, $downloadName)->deleteFileAfterSend(true);
    }

    /** @param list<mixed> $values */
    private function writeExportRow(Worksheet $sheet, int $row, array $values, bool $isCsv): void
    {
        foreach ($values as $index => $value) {
            $value = (string) ($value ?? '');

            if ($isCsv && preg_match('/^[=+\-@]/', $value)) {
                $value = "'".$value;
            }

            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 1).$row, $value, DataType::TYPE_STRING);
        }
    }
}
