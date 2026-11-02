<?php

namespace App\Http\Controllers;

use App\Support\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use ZipArchive;

class QuestionOptionController extends Controller
{
    public function index(): View
    {
        return view('admin.question-options.index', [
            'locations' => DB::table('accounting_entries')->distinct()->orderBy('location')->pluck('location'),
            'options' => DB::table('location_question_options')->orderBy('location')->orderBy('type')->orderBy('value')->get()->groupBy('location'),
        ]);
    }

    public function apiIndex(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(['account', 'paid_to_by'])],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = DB::table('location_question_options')->orderBy('location')->orderBy('type')->orderBy('value');

        foreach (['location', 'type'] as $filter) {
            if (! empty($data[$filter])) {
                $query->where($filter, $data[$filter]);
            }
        }

        if (! empty($data['search'])) {
            $query->where('value', 'like', '%'.$data['search'].'%');
        }

        return response()->json($query->paginate($data['per_page'] ?? 25));
    }

    public function apiShow(int $option): JsonResponse
    {
        $questionOption = DB::table('location_question_options')->find($option);
        abort_if($questionOption === null, 404);

        return response()->json(['data' => $questionOption]);
    }

    public function apiStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location' => ['required', 'string', 'max:255', Rule::exists('accounting_entries', 'location')],
            'type' => ['required', Rule::in(['account', 'paid_to_by'])],
            'value' => ['required', 'string', 'max:255'],
        ]);
        $created = ! DB::table('location_question_options')->where($data)->exists();

        $optionId = DB::transaction(function () use ($data, $request, $created): int {
            $now = now();

            if ($data['type'] === 'account') {
                DB::table('accounts')->upsert(
                    [['account' => $data['value'], 'created_at' => $now, 'updated_at' => $now]],
                    ['account'],
                    ['updated_at'],
                );
            }

            DB::table('location_question_options')->upsert(
                [$data + ['created_at' => $now, 'updated_at' => $now]],
                ['location', 'type', 'value'],
                ['updated_at'],
            );
            $optionId = (int) DB::table('location_question_options')->where($data)->value('id');
            AuditLog::record($request, $created ? 'question_option.created' : 'question_option.upserted', ($created ? 'Created' : 'Upserted').' a '.str($data['type'])->replace('_', ' ')->value().' option.', 'question_option', $optionId, $data['location'], null, [
                'type' => $data['type'],
                'value' => $data['value'],
            ]);

            return $optionId;
        });

        return response()->json([
            'data' => DB::table('location_question_options')->find($optionId),
            'created' => $created,
        ], $created ? 201 : 200);
    }

    public function apiBulkStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locations' => ['required', 'array', 'min:1'],
            'locations.*' => ['string', 'distinct', 'max:255', Rule::exists('accounting_entries', 'location')],
            'accounts' => ['nullable', 'array', 'max:20000'],
            'accounts.*' => ['required', 'string', 'distinct', 'max:255'],
            'paid_to_by' => ['nullable', 'array', 'max:20000'],
            'paid_to_by.*' => ['required', 'string', 'distinct', 'max:255'],
        ]);
        $accounts = $data['accounts'] ?? [];
        $paidToBy = $data['paid_to_by'] ?? [];

        if ($accounts === [] && $paidToBy === []) {
            throw ValidationException::withMessages(['accounts' => 'Provide at least one account or paid to/by value.']);
        }

        if ((count($accounts) + count($paidToBy)) * count($data['locations']) > 50000) {
            throw ValidationException::withMessages(['accounts' => 'The request creates too many location assignments.']);
        }

        $this->upsertOptions($data['locations'], $accounts, $paidToBy);
        AuditLog::record($request, 'question_options.imported', 'Imported API question choices for '.count($data['locations']).' '.str('location')->plural(count($data['locations'])).'.', 'question_options', null, null, null, [
            'source' => 'api',
            'locations' => $data['locations'],
            'accounts' => count($accounts),
            'paid_to_by' => count($paidToBy),
        ]);

        return response()->json([
            'data' => [
                'locations' => $data['locations'],
                'accounts' => count($accounts),
                'paid_to_by' => count($paidToBy),
            ],
            'message' => 'Question options upserted.',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locations' => ['required', 'array', 'min:1'],
            'locations.*' => ['string', 'distinct', 'max:255', Rule::exists('accounting_entries', 'location')],
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv'],
        ]);
        $file = $request->file('file');
        $path = $file->getPathname();
        $isExcel = strtolower($file->getClientOriginalExtension()) === 'xlsx';

        if ($isExcel) {
            $this->validateWorkbookArchive($path);
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

        $values = ['account' => [], 'paid_to_by' => []];
        $rowCount = 0;

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $headers = [
                strtoupper(trim(ltrim((string) $sheet->getCell('A1')->getValue(), "\xEF\xBB\xBF"))),
                strtoupper(trim((string) $sheet->getCell('B1')->getValue())),
            ];

            if ($headers !== ['ACCOUNT', 'PAID TO/BY'] || $sheet->getHighestDataColumn() !== 'B') {
                throw ValidationException::withMessages(['file' => "Sheet {$sheet->getTitle()} must contain ACCOUNT and PAID TO/BY, in that order."]);
            }

            for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
                $accountCell = $sheet->getCell('A'.$row);
                $paidToByCell = $sheet->getCell('B'.$row);

                if ($accountCell->isFormula() || $paidToByCell->isFormula()) {
                    throw ValidationException::withMessages(['file' => "Sheet {$sheet->getTitle()}, row {$row}: formulas are not supported."]);
                }

                $account = trim((string) $accountCell->getValue());
                $paidToBy = trim((string) $paidToByCell->getValue());

                if (mb_strlen($account) > 255 || mb_strlen($paidToBy) > 255) {
                    throw ValidationException::withMessages(['file' => "Sheet {$sheet->getTitle()}, row {$row}: values must not exceed 255 characters."]);
                }

                if ($account !== '') {
                    $values['account'][$account] = true;
                }

                if ($paidToBy !== '') {
                    $values['paid_to_by'][$paidToBy] = true;
                }

                if ($account !== '' || $paidToBy !== '') {
                    $rowCount++;
                }

                if ($rowCount > 20000) {
                    throw ValidationException::withMessages(['file' => 'Import a maximum of 20,000 rows at a time.']);
                }
            }
        }

        $spreadsheet->disconnectWorksheets();
        $accounts = array_keys($values['account']);
        $paidToBy = array_keys($values['paid_to_by']);

        if ($accounts === [] && $paidToBy === []) {
            throw ValidationException::withMessages(['file' => 'The file contains no account or paid to/by values.']);
        }

        if ((count($accounts) + count($paidToBy)) * count($data['locations']) > 50000) {
            throw ValidationException::withMessages(['file' => 'The file creates too many location assignments. Select fewer locations or upload fewer values.']);
        }

        $this->upsertOptions($data['locations'], $accounts, $paidToBy);
        AuditLog::record($request, 'question_options.imported', 'Imported question choices for '.count($data['locations']).' '.str('location')->plural(count($data['locations'])).'.', 'question_options', null, null, null, [
            'file_name' => $file->getClientOriginalName(),
            'locations' => $data['locations'],
            'accounts' => count($accounts),
            'paid_to_by' => count($paidToBy),
        ]);

        $message = count($accounts).' '.str('account')->plural(count($accounts)).' and '.count($paidToBy).' paid to/by '.str('value')->plural(count($paidToBy)).' associated with '.count($data['locations']).' '.str('location')->plural(count($data['locations'])).'.';

        $request->session()->flash('status', $message);

        return response()->json([
            'redirect' => route('admin.question-options.index'),
            'message' => $message,
        ]);
    }

    public function update(Request $request, int $option): JsonResponse
    {
        $questionOption = DB::table('location_question_options')->find($option);
        abort_if($questionOption === null, 404);
        $data = $request->validate([
            'value' => [
                'required',
                'string',
                'max:255',
                Rule::unique('location_question_options', 'value')
                    ->ignore($questionOption->id)
                    ->where('location', $questionOption->location)
                    ->where('type', $questionOption->type),
            ],
        ]);

        DB::transaction(function () use ($data, $questionOption, $request): void {
            $now = now();

            if ($questionOption->type === 'account') {
                DB::table('accounts')->upsert(
                    [['account' => $data['value'], 'created_at' => $now, 'updated_at' => $now]],
                    ['account'],
                    ['updated_at'],
                );
            }

            DB::table('location_question_options')->where('id', $questionOption->id)->update([
                'value' => $data['value'],
                'updated_at' => $now,
            ]);
            AuditLog::record($request, 'question_option.updated', 'Updated a '.str($questionOption->type)->replace('_', ' ')->value().' option.', 'question_option', $questionOption->id, $questionOption->location, [
                'type' => $questionOption->type,
                'value' => $questionOption->value,
            ], [
                'type' => $questionOption->type,
                'value' => $data['value'],
            ]);
        });

        $message = ($questionOption->type === 'account' ? 'Account' : 'Paid to/by').' updated.';

        if ($request->is('api/*')) {
            return response()->json(['data' => DB::table('location_question_options')->find($option)]);
        }

        $request->session()->flash('status', $message);

        return response()->json(['redirect' => route('admin.question-options.index'), 'message' => $message]);
    }

    public function destroy(Request $request, int $option): JsonResponse
    {
        $questionOption = DB::table('location_question_options')->find($option);
        abort_if($questionOption === null, 404);
        DB::transaction(function () use ($option, $questionOption, $request): void {
            DB::table('location_question_options')->where('id', $option)->delete();
            AuditLog::record($request, 'question_option.deleted', 'Deleted a '.str($questionOption->type)->replace('_', ' ')->value().' option.', 'question_option', $option, $questionOption->location, [
                'type' => $questionOption->type,
                'value' => $questionOption->value,
            ]);
        });
        $message = ($questionOption->type === 'account' ? 'Account' : 'Paid to/by').' deleted from '.$questionOption->location.'.';

        if ($request->is('api/*')) {
            return response()->json(status: 204);
        }

        $request->session()->flash('status', $message);

        return response()->json(['redirect' => route('admin.question-options.index'), 'message' => $message]);
    }

    private function validateWorkbookArchive(string $path): void
    {
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

    /**
     * @param  list<string>  $locations
     * @param  list<string>  $accounts
     * @param  list<string>  $paidToBy
     */
    private function upsertOptions(array $locations, array $accounts, array $paidToBy): void
    {
        DB::transaction(function () use ($locations, $accounts, $paidToBy): void {
            $now = now();

            if ($accounts !== []) {
                DB::table('accounts')->upsert(
                    array_map(fn (string $account): array => ['account' => $account, 'created_at' => $now, 'updated_at' => $now], $accounts),
                    ['account'],
                    ['updated_at'],
                );
            }

            $rows = [];

            foreach ($locations as $location) {
                foreach (['account' => $accounts, 'paid_to_by' => $paidToBy] as $type => $typeValues) {
                    foreach ($typeValues as $value) {
                        $rows[] = ['location' => $location, 'type' => $type, 'value' => $value, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('location_question_options')->upsert(
                    $chunk,
                    ['location', 'type', 'value'],
                    ['updated_at'],
                );
            }
        });
    }
}
