<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', 'max:255', Rule::exists('audit_logs', 'action')],
            'actor' => ['nullable', 'integer', Rule::exists('audit_logs', 'actor_id')],
            'role' => ['nullable', Rule::in(['admin', 'manager'])],
            'location' => ['nullable', 'string', 'max:255', Rule::exists('audit_logs', 'location')],
            'subject_type' => ['nullable', 'string', 'max:255', Rule::exists('audit_logs', 'subject_type')],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $query = DB::table('audit_logs');

        foreach (['action', 'location', 'subject_type'] as $filter) {
            if (! empty($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }

        if (! empty($filters['actor'])) {
            $query->where('actor_id', $filters['actor']);
        }

        if (! empty($filters['role'])) {
            $query->where('actor_role', $filters['role']);
        }

        if (! empty($filters['subject_id'])) {
            $query->where('subject_id', $filters['subject_id']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($query) use ($search): void {
                foreach (['summary', 'action', 'actor_name', 'actor_email', 'actor_role', 'location', 'subject_type', 'old_values', 'new_values', 'ip_address'] as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        return view('admin.audit-logs.index', [
            'logs' => $query->orderByDesc('id')->paginate(50)->withQueryString(),
            'actions' => DB::table('audit_logs')->distinct()->orderBy('action')->pluck('action'),
            'actors' => DB::table('audit_logs')->whereNotNull('actor_id')->select('actor_id', 'actor_name')->distinct()->orderBy('actor_name')->get(),
            'locations' => DB::table('audit_logs')->whereNotNull('location')->distinct()->orderBy('location')->pluck('location'),
            'subjectTypes' => DB::table('audit_logs')->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ]);
    }
}
