{{-- One-time "Enable emergency sound" prompt (inside partials.alerts). Browsers keep a page silent until the person taps
     it, so the alarm can't sound without this. resources/js/alerts shows it when this device hasn't chosen yet and
     remembers the answer in localStorage. --}}
<section data-sound-prompt hidden aria-labelledby="sound-prompt-title"
    class="fixed inset-x-3 bottom-[max(.75rem,env(safe-area-inset-bottom))] z-[60] mx-auto max-w-sm rounded-2xl border border-border bg-surface p-4 text-text-primary shadow-[0_12px_40px_-12px_rgba(61,15,24,.45)] sm:inset-x-auto sm:right-6 sm:mx-0">
    <div class="flex gap-3">
        <span class="grid size-10 shrink-0 place-items-center rounded-full bg-alarm/10 text-alarm" aria-hidden="true">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
                <path d="M11 5 6 9H3v6h3l5 4zM15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" />
            </svg>
        </span>
        <div>
            <h2 id="sound-prompt-title" class="text-sm font-semibold">Hear the alarm if there's an emergency</h2>
            <p class="mt-0.5 text-sm text-text-muted">Your browser keeps CNAS silent until you allow sound.</p>
        </div>
    </div>
    <div class="mt-3 flex justify-end gap-2">
        <button type="button" data-sound-choice="off"
            class="min-h-10 rounded-lg px-3 text-sm font-semibold text-text-muted transition hover:text-text-primary">Not now</button>
        <button type="button" data-sound-choice="on"
            class="min-h-10 rounded-lg bg-primary px-4 text-sm font-semibold text-white transition hover:bg-primary-hover">Enable emergency sound</button>
    </div>
</section>
