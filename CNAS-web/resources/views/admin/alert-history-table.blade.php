{{-- History on Admin > Alerts: filters (search, type, level, real or drill, month), then one line per alert, newest first,
     each opening its report (admin.alerts.show). A table on wide screens, two-line items on phones. Needs $history
     (paginated, with help_count), $filters, $filtering, $types, $severities. --}}
@use('App\Enums\AlertResolution')
@php
    $campusDate = fn ($time) => $time?->timezone('Asia/Manila')->format('M j, Y');
    $campusClock = fn ($time) => $time?->timezone('Asia/Manila')->format('g:i A');
    $lasted = fn ($alert) => $alert->lastedLabel();
@endphp
<section aria-labelledby="history-heading" class="min-w-0">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 id="history-heading" class="text-xl font-semibold tracking-tight text-primary">History</h2>
            <p class="mt-1 text-sm leading-relaxed text-text-muted">Open an alert to see its report: who needed help, who responded and everything that happened.</p>
        </div>
        <p class="text-xs text-text-muted">Times in campus time (Asia/Manila)</p>
    </div>

    <form method="GET" action="{{ route('admin.alerts.index') }}#history-heading" role="search" aria-label="Filter the history"
        class="mb-4 grid grid-cols-2 gap-3 rounded-2xl border border-border bg-surface p-4 lg:grid-cols-[minmax(12rem,1.6fr)_repeat(4,minmax(8rem,1fr))_auto]">
        <div class="col-span-2 lg:col-span-1">
            <label for="history-q" class="mb-1 block text-xs font-semibold">Search</label>
            <input id="history-q" name="q" type="search" value="{{ $filters['q'] }}" maxlength="100" placeholder="Title or message" class="login-input py-2.5">
        </div>
        <div>
            <label for="history-type" class="mb-1 block text-xs font-semibold">Type</label>
            <select id="history-type" name="type" class="login-input py-2.5">
                <option value="">All types</option>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected($filters['type'] === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="history-severity" class="mb-1 block text-xs font-semibold">Level</label>
            <select id="history-severity" name="severity" class="login-input py-2.5">
                <option value="">All levels</option>
                @foreach ($severities as $severity)
                    <option value="{{ $severity->value }}" @selected($filters['severity'] === $severity->value)>{{ $severity->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="history-kind" class="mb-1 block text-xs font-semibold">Real or drill</label>
            <select id="history-kind" name="kind" class="login-input py-2.5">
                <option value="">Both</option>
                <option value="real" @selected($filters['kind'] === 'real')>Real alerts</option>
                <option value="drill" @selected($filters['kind'] === 'drill')>Drills</option>
            </select>
        </div>
        <div>
            <label for="history-month" class="mb-1 block text-xs font-semibold">Month</label>
            <input id="history-month" name="month" type="month" value="{{ $filters['month'] }}" class="login-input py-2" max="{{ now()->timezone('Asia/Manila')->format('Y-m') }}">
        </div>
        <div class="col-span-2 flex items-end gap-2 lg:col-span-1">
            <button type="submit" class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl bg-primary px-5 text-sm font-semibold text-white transition hover:bg-primary-hover lg:flex-none">Filter</button>
            @if ($filtering)
                <a href="{{ route('admin.alerts.index') }}#history-heading" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-border px-4 text-sm font-semibold text-primary transition hover:bg-bg-light">Clear</a>
            @endif
        </div>
    </form>

    <p class="mb-2 text-sm text-text-muted" role="status">
        {{ $history->total() }} {{ Str::plural('alert', $history->total()) }}{{ $filtering ? ' match the filters' : ' sent' }}
    </p>

    <div class="overflow-hidden rounded-2xl border border-border bg-surface">
        @if ($history->isEmpty())
            <div class="px-6 py-10 text-center">
                @if ($filtering)
                    <p class="font-semibold text-primary">No alerts match these filters</p>
                    <p class="mt-2 text-sm text-text-muted">Try another month or type, or <a href="{{ route('admin.alerts.index') }}#history-heading" class="font-semibold text-primary underline underline-offset-4">clear the filters</a>.</p>
                @else
                    <p class="font-semibold text-primary">No alerts have been sent yet</p>
                    <p class="mt-2 text-sm text-text-muted">Sent alerts and their reports will appear here.</p>
                @endif
            </div>
        @else
            <table class="w-full text-left text-sm">
                <thead class="hidden border-b border-border bg-bg-light/60 text-xs text-text-muted md:table-header-group">
                    <tr>
                        <th scope="col" class="px-5 py-3 font-semibold">Sent</th>
                        <th scope="col" class="px-3 py-3 font-semibold">Alert</th>
                        <th scope="col" class="px-3 py-3 font-semibold">Level</th>
                        <th scope="col" class="px-3 py-3 font-semibold">Outcome</th>
                        <th scope="col" class="px-3 py-3 text-right font-semibold">Asked for help</th>
                        <th scope="col" class="px-5 py-3 text-right font-semibold">Lasted</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($history as $alert)
                        <tr class="relative grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 px-5 py-3.5 transition-colors hover:bg-bg-light/70 focus-within:bg-bg-light/70 md:table-row md:p-0">
                            <td class="col-span-2 text-xs text-text-muted md:w-36 md:px-5 md:py-3.5 md:align-top md:text-sm md:text-text-primary">
                                <time datetime="{{ $alert->sent_at?->toIso8601String() }}">
                                    <span class="md:block">{{ $campusDate($alert->sent_at) }}</span>
                                    <span class="md:block md:text-xs md:text-text-muted">{{ $campusClock($alert->sent_at) }}</span>
                                </time>
                            </td>
                            <td class="min-w-0 md:px-3 md:py-3.5 md:align-top">
                                <a href="{{ route('admin.alerts.show', $alert) }}" class="font-semibold leading-snug text-text-primary wrap-anywhere after:absolute after:inset-0 hover:text-primary focus-visible:outline-none">{{ $alert->title }}</a>
                                <span class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-text-muted">
                                    <svg class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $alert->type->icon() }}" /></svg>
                                    {{ $alert->type->label() }}
                                    @if ($alert->is_drill)
                                        <span class="rounded bg-hazard/25 px-1.5 py-px text-[11px] font-bold tracking-wide text-stone-800">Drill</span>
                                    @endif
                                </span>
                            </td>
                            <td class="self-start md:px-3 md:py-3.5 md:align-top">
                                @include('admin.severity-badge', ['level' => $alert->severity])
                            </td>
                            <td class="col-span-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs md:table-cell md:px-3 md:py-3.5 md:align-top">
                                @if ($alert->isActive())
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-alarm px-2.5 py-0.5 font-semibold text-white">
                                        <span class="size-1.5 rounded-full bg-white" aria-hidden="true"></span>Active now
                                    </span>
                                @else
                                    <span @class([
                                        'inline-flex rounded-full px-2.5 py-0.5 font-semibold',
                                        'bg-safe-tint text-safe' => $alert->resolution === AlertResolution::AllClear,
                                        'bg-bg-light text-text-primary' => $alert->resolution !== AlertResolution::AllClear,
                                    ])>{{ $alert->resolution?->label() ?? 'Ended' }}</span>
                                @endif
                                <span class="text-text-muted md:hidden">
                                    @if ($alert->help_count > 0) {{ $alert->help_count }} asked for help, @endif
                                    {{ $lasted($alert) ? 'lasted '.Str::lcfirst($lasted($alert)) : 'ongoing' }}
                                </span>
                            </td>
                            <td class="hidden text-right tabular-nums md:table-cell md:px-3 md:py-3.5 md:align-top">
                                @if ($alert->help_count > 0)
                                    <span class="font-semibold text-alarm">{{ $alert->help_count }}</span>
                                @else
                                    <span class="text-text-muted">None</span>
                                @endif
                            </td>
                            <td class="hidden text-right text-text-muted tabular-nums md:table-cell md:px-5 md:py-3.5 md:align-top">{{ $lasted($alert) ?? 'Ongoing' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($history->hasPages())
                <div class="border-t border-border bg-bg-light/50 px-5 py-4 sm:px-6">{{ $history->fragment('history-heading')->links() }}</div>
            @endif
        @endif
    </div>
</section>
