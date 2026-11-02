<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ManagerController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard', [
            'stats' => DB::table('accounting_entries')
                ->whereNull('archived_at')
                ->selectRaw('COUNT(DISTINCT location) as total_locations')
                ->selectRaw('COUNT(*) as total_questions')
                ->selectRaw('SUM(CASE WHEN answered_at IS NOT NULL THEN 1 ELSE 0 END) as answered_questions')
                ->selectRaw('SUM(CASE WHEN answered_at IS NULL THEN 1 ELSE 0 END) as pending_questions')
                ->first(),
        ]);
    }

    public function dashboardData(Request $request): JsonResponse
    {
        $parameters = $request->validate([
            'draw' => ['required', 'integer', 'min:0'],
            'start' => ['required', 'integer', 'min:0'],
            'length' => ['required', 'integer', 'in:10,25,50,100'],
            'search.value' => ['nullable', 'string', 'max:255'],
            'order.0.column' => ['nullable', 'integer', 'between:1,4'],
            'order.0.dir' => ['nullable', 'in:asc,desc'],
        ]);

        $assignedLocations = $request->user()->role === 'manager'
            ? DB::table('manager_locations')->where('user_id', $request->user()->id)->pluck('location')
            : null;
        $totalQuery = DB::table('accounting_entries')->whereNull('archived_at');
        $query = DB::table('accounting_entries')
            ->select(
                'location',
                DB::raw('COUNT(*) as total_questions'),
                DB::raw('SUM(CASE WHEN answered_at IS NOT NULL THEN 1 ELSE 0 END) as answered_questions'),
                DB::raw('SUM(CASE WHEN answered_at IS NULL THEN 1 ELSE 0 END) as pending_questions'),
            )
            ->whereNull('archived_at')
            ->groupBy('location');

        if ($assignedLocations !== null) {
            $totalQuery->whereIn('location', $assignedLocations);
            $query->whereIn('location', $assignedLocations);
        }

        $total = $totalQuery->distinct()->count('location');
        $search = $parameters['search']['value'] ?? null;

        if ($search !== null && $search !== '') {
            $query->where('location', 'like', '%'.$search.'%');
        }

        $filtered = DB::query()->fromSub(clone $query, 'locations')->count();
        $order = $parameters['order'][0] ?? ['column' => 0, 'dir' => 'asc'];
        $orderColumn = [
            1 => 'location',
            2 => 'total_questions',
            3 => 'answered_questions',
            4 => 'pending_questions',
        ][$order['column']];

        return response()->json([
            'draw' => $parameters['draw'],
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $query
                ->orderBy($orderColumn, $order['dir'])
                ->offset($parameters['start'])
                ->limit($parameters['length'])
                ->get(),
        ]);
    }

    public function locations(Request $request): View
    {
        $data = $request->validate([
            'manager' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'manager')],
        ]);
        $selectedManager = isset($data['manager']) ? User::findOrFail($data['manager']) : null;

        return view('admin.manager-locations.index', [
            'managers' => User::query()->where('role', 'manager')->orderBy('name')->get(),
            'selectedManager' => $selectedManager,
            'locations' => DB::table('accounting_entries')->distinct()->orderBy('location')->pluck('location'),
            'assignedLocations' => $selectedManager === null
                ? collect()
                : DB::table('manager_locations')->where('user_id', $selectedManager->id)->pluck('location'),
        ]);
    }

    public function locationAssignmentsData(Request $request): JsonResponse
    {
        $parameters = $request->validate([
            'draw' => ['required', 'integer', 'min:0'],
            'start' => ['required', 'integer', 'min:0'],
            'length' => ['required', 'integer', 'in:10,25,50,100'],
            'search.value' => ['nullable', 'string', 'max:255'],
            'order.0.column' => ['nullable', 'integer', 'between:0,1'],
            'order.0.dir' => ['nullable', 'in:asc,desc'],
        ]);

        $query = DB::table('users')
            ->where('role', 'manager')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('manager_locations')
                    ->whereColumn('manager_locations.user_id', 'users.id');
            });
        $total = (clone $query)->count();
        $search = $parameters['search']['value'] ?? null;

        if ($search !== null && $search !== '') {
            $query->where(function ($query) use ($search): void {
                $query->where('users.name', 'like', '%'.$search.'%')
                    ->orWhere('users.email', 'like', '%'.$search.'%')
                    ->orWhereExists(function ($query) use ($search): void {
                        $query->selectRaw('1')
                            ->from('manager_locations')
                            ->whereColumn('manager_locations.user_id', 'users.id')
                            ->where('manager_locations.location', 'like', '%'.$search.'%');
                    });
            });
        }

        $filtered = (clone $query)->count();
        $order = $parameters['order'][0] ?? ['column' => 0, 'dir' => 'asc'];
        $orderColumn = ['users.name', 'users.email'][$order['column']];
        $managers = $query
            ->select('users.id', 'users.name', 'users.email')
            ->orderBy($orderColumn, $order['dir'])
            ->offset($parameters['start'])
            ->limit($parameters['length'])
            ->get();
        $locations = DB::table('manager_locations')
            ->whereIn('user_id', $managers->pluck('id'))
            ->orderBy('location')
            ->get()
            ->groupBy('user_id');

        $managers->each(function (object $manager) use ($locations): void {
            $manager->locations = $locations->get($manager->id, collect())->pluck('location')->values();
            unset($manager->id);
        });

        return response()->json([
            'draw' => $parameters['draw'],
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $managers,
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        unset($data['password_confirmation']);
        $manager = User::create($data);
        AuditLog::record($request, 'manager.created', 'Created manager '.$manager->name.'.', 'user', $manager->id, null, null, [
            'name' => $manager->name,
            'email' => $manager->email,
            'role' => $manager->role,
        ]);
        $redirect = route('admin.manager-locations.index', ['manager' => $manager->id]);

        if ($request->expectsJson()) {
            $request->session()->flash('status', 'Manager account created.');

            return response()->json(['redirect' => $redirect, 'message' => 'Manager account created.']);
        }

        return redirect($redirect)->with('status', 'Manager account created.');
    }

    public function updateLocations(Request $request, User $manager): RedirectResponse|JsonResponse
    {
        abort_unless($manager->role === 'manager', 404);

        $data = $request->validate([
            'locations' => ['nullable', 'array'],
            'locations.*' => ['string', 'distinct', 'max:255', Rule::exists('accounting_entries', 'location')],
        ]);
        $locations = $data['locations'] ?? [];
        $previousLocations = DB::table('manager_locations')->where('user_id', $manager->id)->orderBy('location')->pluck('location')->all();

        DB::transaction(function () use ($manager, $locations, $previousLocations, $request): void {
            DB::table('manager_locations')->where('user_id', $manager->id)->delete();
            DB::table('manager_locations')->insert(array_map(
                fn (string $location): array => [
                    'user_id' => $manager->id,
                    'location' => $location,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                $locations,
            ));
            AuditLog::record($request, 'manager.locations_updated', 'Updated locations for '.$manager->name.'.', 'user', $manager->id, null, ['locations' => $previousLocations], ['locations' => $locations]);
        });

        $message = 'Manager locations updated.';
        $redirect = route('admin.manager-locations.index', ['manager' => $manager->id]);

        return $request->expectsJson()
            ? response()->json(['redirect' => $redirect, 'message' => $message])
            : redirect($redirect)->with('status', $message);
    }

    public function dashboard(Request $request): View
    {
        $locations = DB::table('manager_locations')->where('user_id', $request->user()->id)->pluck('location');

        return view('admin.dashboard', [
            'stats' => DB::table('accounting_entries')
                ->whereIn('location', $locations)
                ->whereNull('archived_at')
                ->selectRaw('COUNT(DISTINCT location) as total_locations')
                ->selectRaw('COUNT(*) as total_questions')
                ->selectRaw('SUM(CASE WHEN answered_at IS NOT NULL THEN 1 ELSE 0 END) as answered_questions')
                ->selectRaw('SUM(CASE WHEN answered_at IS NULL THEN 1 ELSE 0 END) as pending_questions')
                ->first(),
        ]);
    }
}
