@props(['titulo'])

<div class="flex flex-col items-center justify-center gap-3 py-16 text-center">
    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-sky-50 text-sky-600">
        <svg aria-hidden="true" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 3" />
        </svg>
    </div>
    <h3 class="text-lg font-semibold text-slate-900">{{ $titulo }}</h3>
    <p class="max-w-sm text-sm text-slate-500">Este submódulo todavía está en construcción. Muy pronto vas a poder gestionar esta parte del proceso desde aquí.</p>
</div>
