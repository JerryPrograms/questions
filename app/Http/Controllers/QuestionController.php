<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuestionController extends Controller
{
    public function index(Request $request): View
    {
        $locations = $this->selectedLocations($request);

        return view('admin.questions.index', [
            'locations' => $locations,
            'allLocations' => $request->boolean('all'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $locations = $this->selectedLocations($request);
        $parameters = $request->validate([
            'draw' => ['required', 'integer', 'min:0'],
            'start' => ['required', 'integer', 'min:0'],
            'length' => ['required', 'integer', 'in:10,25,50,100'],
            'status' => ['nullable', 'in:all,answered,pending,archived'],
            'location_filter' => ['nullable', 'string', 'max:255', Rule::exists('accounting_entries', 'location')],
            'search.value' => ['nullable', 'string', 'max:255'],
            'order.0.column' => ['nullable', 'integer', Rule::in([1, 2, 3, 4, 5, 7])],
            'order.0.dir' => ['nullable', 'in:asc,desc'],
        ]);

        $query = DB::table('accounting_entries')
            ->leftJoin('users as answering_users', 'answering_users.id', '=', 'accounting_entries.answered_by')
            ->whereIn('accounting_entries.location', $locations);
        $total = (clone $query)->count();
        $status = $parameters['status'] ?? 'all';

        if ($status === 'archived') {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');

            if ($status === 'answered') {
                $query->whereNotNull('answered_at');
            } elseif ($status === 'pending') {
                $query->whereNull('answered_at');
            }
        }

        if (! empty($parameters['location_filter'])) {
            $query->where('location', $parameters['location_filter']);
        }

        $search = $parameters['search']['value'] ?? null;

        if ($search !== null && $search !== '') {
            $query->where(function ($query) use ($search): void {
                foreach (['transaction_date', 'location', 'bank_name', 'check_no', 'paid_to_by', 'account', 'credit', 'debit', 'description', 'answer'] as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        $filtered = (clone $query)->count();
        $order = $parameters['order'][0] ?? ['column' => 0, 'dir' => 'asc'];
        $orderColumn = [
            1 => 'location',
            2 => 'amount',
            3 => 'credit_debit',
            4 => 'paid_to_by',
            5 => 'account',
            7 => 'answered_at',
        ][$order['column']];
        $dataQuery = $query
            ->select('accounting_entries.id', 'location', 'transaction_date', 'bank_name', 'check_no', 'paid_to_by', 'account', DB::raw('COALESCE(credit, debit) as amount'), DB::raw("CASE WHEN credit IS NOT NULL THEN 'Credit' ELSE 'Debit' END as credit_debit"), 'description', 'answer', 'answered_at', 'answering_users.name as answered_by_name', 'detail_file_path', 'archived_at')
            ->orderBy('location', $orderColumn === 'location' ? $order['dir'] : 'asc');

        if ($orderColumn !== 'location') {
            $dataQuery->orderBy($orderColumn, $order['dir']);
        }

        $questions = $dataQuery
            ->offset($parameters['start'])
            ->limit($parameters['length'])
            ->get();
        $answers = DB::table('accounting_entry_answers')
            ->leftJoin('users as answering_users', 'answering_users.id', '=', 'accounting_entry_answers.answered_by')
            ->whereIn('accounting_entry_id', $questions->pluck('id'))
            ->select('accounting_entry_id', 'accounting_entry_answers.answer', 'accounting_entry_answers.answered_at', 'answering_users.name as answered_by_name')
            ->orderBy('accounting_entry_answers.id')
            ->get()
            ->groupBy('accounting_entry_id');
        $questions->each(function (object $question) use ($answers): void {
            $question->answers = $answers->get($question->id, collect())->values();
        });

        return response()->json([
            'draw' => $parameters['draw'],
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $questions,
        ]);
    }

    public function answer(Request $request, int $entry): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'answer' => ['required', 'string', 'max:10000'],
        ]);
        $location = DB::transaction(function () use ($entry, $data, $request): string {
            $question = DB::table('accounting_entries')->where('id', $entry)->lockForUpdate()->first();
            abort_if($question === null, 404);
            $this->authorizeLocation($request, $question->location);

            if ($question->archived_at !== null) {
                throw ValidationException::withMessages(['answer' => 'Archived questions cannot be changed.']);
            }

            $this->saveAnswer($question, $request->user()->id, $data['answer']);

            return $question->location;
        });

        $message = 'Answer saved.';

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : redirect()->route('admin.questions.index', ['location' => $location])->with('status', $message);
    }

    public function bulkAnswer(Request $request): JsonResponse
    {
        $locations = $this->selectedLocations($request);
        $data = $request->validate([
            'scope' => ['required', 'in:selected,all_pending'],
            'answer' => ['required', 'string', 'max:10000'],
            'entries' => ['required_if:scope,selected', 'array', 'min:1'],
            'entries.*' => ['integer', 'distinct'],
        ]);

        $query = DB::table('accounting_entries')->whereIn('location', $locations)->whereNull('archived_at');

        if ($data['scope'] === 'selected') {
            $query->whereIn('id', $data['entries']);
        } else {
            $query->whereNull('answered_at');
        }

        $updated = DB::transaction(function () use ($query, $data, $request): int {
            $questions = $query->lockForUpdate()->get(['id', 'answer']);

            foreach ($questions as $question) {
                $this->saveAnswer($question, $request->user()->id, $data['answer']);
            }

            return $questions->count();
        });

        return response()->json([
            'message' => $updated.' '.str('question')->plural($updated).' answered.',
            'updated' => $updated,
        ]);
    }

    public function uploadDetail(Request $request, int $entry): JsonResponse
    {
        $question = DB::table('accounting_entries')->find($entry);
        abort_if($question === null, 404);

        $request->validate([
            'detail' => ['required', 'file', 'max:10240', 'mimetypes:application/pdf,image/*'],
        ]);

        $file = $request->file('detail');
        $extension = strtolower($file->extension());

        if ($extension === '') {
            throw ValidationException::withMessages(['detail' => 'The file type could not be determined.']);
        }

        $path = $file->storeAs('question-details', $entry.'.'.$extension, 'local');

        if ($path === false) {
            throw ValidationException::withMessages(['detail' => 'The file could not be saved.']);
        }

        DB::table('accounting_entries')->where('id', $entry)->update([
            'detail_file_path' => $path,
            'updated_at' => now(),
        ]);

        if ($question->detail_file_path !== null && $question->detail_file_path !== $path) {
            Storage::disk('local')->delete($question->detail_file_path);
        }

        return response()->json([
            'message' => 'Detail file uploaded.',
            'file_name' => basename($path),
        ]);
    }

    public function viewDetail(Request $request, int $entry): StreamedResponse
    {
        $question = DB::table('accounting_entries')->find($entry);
        abort_if($question === null, 404);
        $this->authorizeLocation($request, $question->location);
        abort_if($question->detail_file_path === null || ! Storage::disk('local')->exists($question->detail_file_path), 404);

        return Storage::disk('local')->response($question->detail_file_path, basename($question->detail_file_path), [], 'inline');
    }

    public function archive(Request $request, int $entry): JsonResponse
    {
        $question = DB::table('accounting_entries')->find($entry);
        abort_if($question === null, 404);

        if ($question->answered_at === null) {
            throw ValidationException::withMessages(['question' => 'Only answered questions can be archived.']);
        }

        DB::table('accounting_entries')->where('id', $entry)->whereNull('archived_at')->update([
            'archived_by' => $request->user()->id,
            'archived_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Question archived.']);
    }

    public function updateStatus(Request $request, int $entry): JsonResponse
    {
        $question = DB::table('accounting_entries')->find($entry);
        abort_if($question === null, 404);

        if ($question->archived_at === null) {
            throw ValidationException::withMessages(['question' => 'Only archived questions can be restored.']);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'answered'])],
        ]);
        $updates = [
            'archived_by' => null,
            'archived_at' => null,
            'updated_at' => now(),
        ];

        if ($data['status'] === 'pending') {
            $updates['answered_by'] = null;
            $updates['answered_at'] = null;
        }

        DB::table('accounting_entries')->where('id', $entry)->whereNotNull('archived_at')->update($updates);

        return response()->json(['message' => 'Question moved to '.ucfirst($data['status']).'.']);
    }

    public function bulkArchive(Request $request): JsonResponse
    {
        $locations = $this->selectedLocations($request);
        $data = $request->validate([
            'scope' => ['required', 'in:selected,all_answered'],
            'entries' => ['required_if:scope,selected', 'array', 'min:1'],
            'entries.*' => ['integer', 'distinct'],
        ]);

        $query = DB::table('accounting_entries')
            ->whereIn('location', $locations)
            ->whereNotNull('answered_at')
            ->whereNull('archived_at');

        if ($data['scope'] === 'selected') {
            $query->whereIn('id', $data['entries']);
        }

        $updated = $query->update([
            'archived_by' => $request->user()->id,
            'archived_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => $updated.' '.str('question')->plural($updated).' archived.',
            'updated' => $updated,
        ]);
    }

    /** @return list<string> */
    private function selectedLocations(Request $request): array
    {
        $data = $request->validate([
            'all' => ['nullable', 'boolean'],
            'location' => ['nullable', 'string', 'max:255', Rule::exists('accounting_entries', 'location')],
            'locations' => ['nullable', 'array'],
            'locations.*' => ['string', 'distinct', 'max:255', Rule::exists('accounting_entries', 'location')],
        ]);

        if ($request->user()->role === 'manager') {
            $assignedLocations = DB::table('manager_locations')
                ->where('user_id', $request->user()->id)
                ->orderBy('location')
                ->pluck('location')
                ->all();
            $locations = $data['locations'] ?? (isset($data['location']) ? [$data['location']] : $assignedLocations);
            abort_if(array_diff($locations, $assignedLocations) !== [], 403);

            return array_values($locations);
        }

        if ($request->boolean('all')) {
            return DB::table('accounting_entries')->distinct()->orderBy('location')->pluck('location')->all();
        }

        $locations = $data['locations'] ?? (isset($data['location']) ? [$data['location']] : []);

        if ($locations === []) {
            throw ValidationException::withMessages(['locations' => 'Select at least one location.']);
        }

        return array_values($locations);
    }

    private function authorizeLocation(Request $request, string $location): void
    {
        if ($request->user()->role === 'admin') {
            return;
        }

        abort_unless(
            $request->user()->role === 'manager'
                && DB::table('manager_locations')->where('user_id', $request->user()->id)->where('location', $location)->exists(),
            403,
        );
    }

    private function appendAnswer(?string $existingAnswer, string $newAnswer): string
    {
        $existingAnswer = $existingAnswer === null ? null : (preg_replace('/\R+/', "\n", $existingAnswer) ?? $existingAnswer);
        $newAnswer = preg_replace('/\R+/', "\n", $newAnswer) ?? $newAnswer;
        $answer = $existingAnswer === null ? $newAnswer : $existingAnswer."\n".$newAnswer;

        if (strlen($answer) > 65535) {
            throw ValidationException::withMessages(['answer' => 'The answer history is too long to add another answer.']);
        }

        return $answer;
    }

    private function saveAnswer(object $question, int $userId, string $answer): void
    {
        $answeredAt = now();

        DB::table('accounting_entries')->where('id', $question->id)->update([
            'answer' => $this->appendAnswer($question->answer, $answer),
            'answered_by' => $userId,
            'answered_at' => $answeredAt,
            'updated_at' => $answeredAt,
        ]);
        DB::table('accounting_entry_answers')->insert([
            'accounting_entry_id' => $question->id,
            'answer' => $answer,
            'answered_by' => $userId,
            'answered_at' => $answeredAt,
        ]);
    }
}
