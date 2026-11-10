@extends('layouts.app')
@section('title', 'Activity history')
@section('content')
<section class="hero-row"><div><h1>Activity history</h1><p class="muted">PDF changes, account changes and sign-in attempts. Times shown in Asia/Singapore (UTC+8).</p></div></section>
<section class="panel toolbar">
    <form method="GET" action="{{ route('activity.index') }}" class="filters">
        <label class="search-field"><span class="sr-only">Search by person or PDF filename</span><input name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Search person, username or PDF filename"></label>
        <label><span class="sr-only">Activity type</span><select name="action"><option value="">All activities</option>@foreach ($actions as $action => $label)<option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $label }}</option>@endforeach</select></label>
        <button class="button primary" type="submit">Filter history</button>
        @if (! empty($filters['q']) || ! empty($filters['action']))<a class="clear-filters" href="{{ route('activity.index') }}">Clear filters</a>@endif
    </form>
</section>
<section class="panel table-panel">
    <div class="panel-heading"><div><h2>Recorded activities</h2><p class="muted">{{ $activities->total() }} {{ $activities->total() === 1 ? 'activity' : 'activities' }}. Newest first.</p></div></div>
    <div class="table-scroll"><table class="account-table"><thead><tr><th scope="col">Time</th><th scope="col">Person and activity</th><th scope="col">PDF or account</th><th scope="col">Changes</th><th scope="col">IP address</th></tr></thead><tbody>
    @forelse ($activities as $activity)
        <tr class="account-record">
            <td data-label="Time"><time datetime="{{ $activity->created_at->toIso8601String() }}">{{ $activity->created_at->timezone('Asia/Singapore')->format('d M Y, H:i:s') }}</time></td>
            <td data-label="Person and activity"><strong>{{ $actions[$activity->action] ?? $activity->action }}</strong><div>{{ $activity->actor_label }}</div></td>
            <td data-label="PDF or account">{{ $activity->subject_label }}<div class="muted">{{ ucfirst($activity->subject_type) }} @if ($activity->subject_id)#{{ $activity->subject_id }}@endif</div></td>
            <td data-label="Changes">
                @if ($activity->before || $activity->after)
                    <details><summary>View details</summary><dl>
                    @foreach (array_unique(array_merge(array_keys($activity->before ?? []), array_keys($activity->after ?? []))) as $field)
                        <dt><strong>{{ ucfirst(str_replace('_', ' ', $field)) }}</strong></dt>
                        <dd>
                            @if (array_key_exists($field, $activity->before ?? []))<span>Before: {{ is_bool($activity->before[$field]) ? ($activity->before[$field] ? 'Yes' : 'No') : ($activity->before[$field] ?? 'Not set') }}</span><br>@endif
                            @if (array_key_exists($field, $activity->after ?? []))<span>After: {{ is_bool($activity->after[$field]) ? ($activity->after[$field] ? 'Yes' : 'No') : ($activity->after[$field] ?? 'Not set') }}</span>@endif
                        </dd>
                    @endforeach
                    </dl></details>
                @else
                    <span class="muted">Event recorded</span>
                @endif
            </td>
            <td data-label="IP address">{{ $activity->ip_address ?? 'Not applicable' }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="empty-state"><strong>No activities found</strong><span>Activity is recorded from the time history was enabled.</span>@if (! empty($filters['q']) || ! empty($filters['action']))<a class="button secondary" href="{{ route('activity.index') }}">Clear filters</a>@endif</td></tr>
    @endforelse
    </tbody></table></div>
    <nav class="voucher-pagination" aria-label="Activity history pages"><p>Page <strong>{{ $activities->currentPage() }}</strong> of <strong>{{ $activities->lastPage() }}</strong></p>
        @if ($activities->hasPages())<div class="voucher-page-controls">
            @if ($activities->previousPageUrl())<a class="button secondary" href="{{ $activities->previousPageUrl() }}">Previous</a>@endif
            @foreach (collect([1, $activities->currentPage() - 1, $activities->currentPage(), $activities->currentPage() + 1, $activities->lastPage()])->filter(fn ($page) => $page >= 1 && $page <= $activities->lastPage())->unique()->sort() as $page)
                <a class="button secondary voucher-page-number" href="{{ $activities->url($page) }}" @if ($page === $activities->currentPage()) aria-current="page" @endif>{{ $page }}</a>
            @endforeach
            @if ($activities->nextPageUrl())<a class="button secondary" href="{{ $activities->nextPageUrl() }}">Next</a>@endif
        </div>@endif
    </nav>
</section>
@endsection
