<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('manage-accounts');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', Rule::in(array_keys(ActivityLog::ACTIONS))],
        ]);
        $activities = ActivityLog::query()
            ->when($filters['action'] ?? null, fn (Builder $query, string $action): Builder => $query->where('action', $action))
            ->when(($filters['q'] ?? '') !== '', function (Builder $query) use ($filters): void {
                $term = $filters['q'];
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('actor_label', 'like', '%'.$term.'%')
                        ->orWhere('subject_label', 'like', '%'.$term.'%');
                });
            })
            ->orderByDesc('id')->paginate(30)->withQueryString();

        return view('activity.index', ['activities' => $activities, 'filters' => $filters, 'actions' => ActivityLog::ACTIONS]);
    }
}
