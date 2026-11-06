<?php

namespace App\Http\Controllers;

use App\Support\AuditLog;
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
        $options = DB::table('location_question_options')
            ->whereIn('location', $locations)
            ->orderBy('value')
            ->get();

        return view('admin.questions.index', [
            'locations' => $locations,
            'allLocations' => $request->boolean('all'),
            'questionOptions' => $options->groupBy('type')->map(
                fn ($typeOptions) => $typeOptions->groupBy('location')->map(
                    fn ($locationOptions) => $locationOptions->pluck('value')->values(),
                ),
            ),
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
        } elseif ($status !== 'all') {
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
            ->select('accounting_entries.id', 'location', 'transaction_date', 'bank_name', 'check_no', 'paid_to_by', 'account', DB::raw('COALESCE(credit, debit) as amount'), DB::raw("CASE WHEN credit IS NOT NULL THEN 'Credit' ELSE 'Debit' END as credit_debit"), 'description', 'answer', 'answered_at', 'answer_exported_at', 'answering_users.name as answered_by_name', 'detail_file_path', 'archived_at')
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
            ->orderByDesc('accounting_entry_answers.id')
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
            'action' => ['sometimes', 'in:save,answer'],
        ]);
        $action = $data['action'] ?? 'save';
        $location = DB::transaction(function () use ($entry, $data, $request): string {
            $question = DB::table('accounting_entries')->where('id', $entry)->lockForUpdate()->first();
            abort_if($question === null, 404);
            $this->authorizeLocation($request, $question->location);

            if ($question->archived_at !== null) {
                throw ValidationException::withMessages(['answer' => 'Archived questions cannot be changed.']);
            }

            if (($data['action'] ?? 'save') === 'answer') {
                $this->markAsAnswered($question, $request, $data['answer']);
            } else {
                $this->saveAnswerOnly($question, $request, $data['answer']);
            }

            return $question->location;
        });

        $message = $action === 'answer' ? 'Question marked as answered.' : 'Answer saved.';

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : redirect()->route('admin.questions.index', ['location' => $location])->with('status', $message);
    }

    public function updateAccount(Request $request, int $entry): JsonResponse
    {
        return $this->updateQuestionOption($request, $entry, 'account', 'account', 'Account');
    }

    public function updatePaidToBy(Request $request, int $entry): JsonResponse
    {
        return $this->updateQuestionOption($request, $entry, 'paid_to_by', 'paid_to_by', 'Paid to/by');
    }

    private function updateQuestionOption(Request $request, int $entry, string $field, string $type, string $label): JsonResponse
    {
        return DB::transaction(function () use ($entry, $field, $label, $request, $type): JsonResponse {
            $question = DB::table('accounting_entries')->where('id', $entry)->lockForUpdate()->first();
            abort_if($question === null, 404);
            $this->authorizeLocation($request, $question->location);
            $data = $request->validate([
                $field => [
                    'required',
                    'string',
                    'max:255',
                    Rule::exists('location_question_options', 'value')
                        ->where('location', $question->location)
                        ->where('type', $type),
                ],
            ]);

            if ($question->archived_at !== null) {
                throw ValidationException::withMessages([$field => 'Archived questions cannot be changed.']);
            }

            DB::table('accounting_entries')->where('id', $entry)->update([
                $field => $data[$field],
                'answer_exported_at' => null,
                'updated_at' => now(),
            ]);
            AuditLog::record(
                $request,
                'question.'.$field.'_updated',
                $label.' changed for question #'.$entry.'.',
                'question',
                $entry,
                $question->location,
                [$field => $question->{$field}],
                [$field => $data[$field]],
            );

            return response()->json(['message' => $label.' saved.']);
        });
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
            $questions = $query->lockForUpdate()->get(['id', 'answer', 'location']);

            foreach ($questions as $question) {
                $this->saveAnswer($question, $request, $data['answer']);
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
        AuditLog::record($request, 'question.detail_uploaded', 'Uploaded a detail file for question #'.$entry.'.', 'question', $entry, $question->location, [
            'file_name' => $question->detail_file_path === null ? null : basename($question->detail_file_path),
        ], [
            'file_name' => basename($path),
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

        if ($question->answer_exported_at === null && ! $request->boolean('confirm_unexported')) {
            return response()->json([
                'message' => 'This answer has not been exported yet. Archive it anyway?',
                'warning' => 'unexported_answer',
            ], 409);
        }

        $updated = DB::table('accounting_entries')->where('id', $entry)->whereNull('archived_at')->update([
            'archived_by' => $request->user()->id,
            'archived_at' => now(),
            'updated_at' => now(),
        ]);

        if ($updated === 1) {
            AuditLog::record($request, 'question.archived', 'Archived question #'.$entry.'.', 'question', $entry, $question->location, ['status' => 'answered'], ['status' => 'archived']);
        }

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
        AuditLog::record($request, 'question.restored', 'Restored question #'.$entry.' to '.$data['status'].'.', 'question', $entry, $question->location, ['status' => 'archived'], ['status' => $data['status']]);

        return response()->json(['message' => 'Question moved to '.ucfirst($data['status']).'.']);
    }

    public function bulkArchive(Request $request): JsonResponse
    {
        $locations = $this->selectedLocations($request);
        $data = $request->validate([
            'scope' => ['required', 'in:selected,all_answered'],
            'entries' => ['required_if:scope,selected', 'array', 'min:1'],
            'entries.*' => ['integer', 'distinct'],
            'confirm_unexported' => ['nullable', 'boolean'],
        ]);

        $query = DB::table('accounting_entries')
            ->whereIn('location', $locations)
            ->whereNotNull('answered_at')
            ->whereNull('archived_at');

        if ($data['scope'] === 'selected') {
            $query->whereIn('id', $data['entries']);
        }

        $unexported = (clone $query)->whereNull('answer_exported_at')->count();

        if ($unexported > 0 && ! $request->boolean('confirm_unexported')) {
            return response()->json([
                'message' => $unexported.' '.str('answer')->plural($unexported).' '.($unexported === 1 ? 'has' : 'have').' not been exported yet. Archive anyway?',
                'warning' => 'unexported_answers',
                'unexported' => $unexported,
            ], 409);
        }

        $questions = $query->get(['id', 'location']);
        $updated = DB::transaction(function () use ($query, $questions, $request): int {
            $updated = $query->update([
                'archived_by' => $request->user()->id,
                'archived_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($questions as $question) {
                AuditLog::record($request, 'question.archived', 'Archived question #'.$question->id.'.', 'question', $question->id, $question->location, ['status' => 'answered'], ['status' => 'archived']);
            }

            return $updated;
        });

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
        if ($request->user()->can('admin')) {
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

    private function saveAnswerOnly(object $question, Request $request, string $answer): void
    {
        if ($question->answered_at !== null) {
            $this->saveAnswer($question, $request, $answer);

            return;
        }

        $answer = preg_replace('/\R+/', "\n", $answer) ?? $answer;
        DB::table('accounting_entries')->where('id', $question->id)->update([
            'answer' => $answer,
            'answer_exported_at' => null,
            'updated_at' => now(),
        ]);
        AuditLog::record($request, 'question.answer_saved', 'Saved an answer for question #'.$question->id.'.', 'question', $question->id, $question->location, ['answer' => $question->answer], ['answer' => $answer]);
    }

    private function markAsAnswered(object $question, Request $request, string $answer): void
    {
        $answer = preg_replace('/\R+/', "\n", $answer) ?? $answer;
        $answeredAt = now();
        $userId = $request->user()->id;

        DB::table('accounting_entries')->where('id', $question->id)->update([
            'answer' => $answer,
            'answered_by' => $userId,
            'answered_at' => $answeredAt,
            'answer_exported_at' => null,
            'updated_at' => $answeredAt,
        ]);
        DB::table('accounting_entry_answers')->insert([
            'accounting_entry_id' => $question->id,
            'answer' => $answer,
            'answered_by' => $userId,
            'answered_at' => $answeredAt,
        ]);
        AuditLog::record($request, 'question.answered', 'Marked question #'.$question->id.' as answered.', 'question', $question->id, $question->location, null, ['answer' => $answer]);
    }

    private function saveAnswer(object $question, Request $request, string $answer): void
    {
        $answeredAt = now();
        $userId = $request->user()->id;

        DB::table('accounting_entries')->where('id', $question->id)->update([
            'answer' => $this->appendAnswer($question->answer, $answer),
            'answered_by' => $userId,
            'answered_at' => $answeredAt,
            'answer_exported_at' => null,
            'updated_at' => $answeredAt,
        ]);
        DB::table('accounting_entry_answers')->insert([
            'accounting_entry_id' => $question->id,
            'answer' => $answer,
            'answered_by' => $userId,
            'answered_at' => $answeredAt,
        ]);
        AuditLog::record($request, 'question.answered', 'Added an answer to question #'.$question->id.'.', 'question', $question->id, $question->location, null, ['answer' => $answer]);
    }
}
