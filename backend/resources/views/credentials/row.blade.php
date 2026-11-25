@php
    /** @var \App\Credentials\VisitorCredential|null $credential */
    /** @var \App\Models\Visitor $visitor */
    /** @var \App\Credentials\CredentialType $type */
    /** @var string $state */
    [$stateLabel, $stateClass] = $states[$state] ?? [$state, 'badge-ghost'];
@endphp
<div class="grid gap-3 border-b border-base-300 p-4 last:border-b-0 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]" wire:key="{{ $key }}">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-lg font-semibold">{{ trim($visitor->first_name.' '.$visitor->name) }}</span>
            <span class="badge {{ $stateClass }}">{{ $stateLabel }}</span>
        </div>
        <div class="text-sm text-base-content/70">
            {{ $type->name }}
            @if ($visitor->email) · {{ $visitor->email }} @endif
            @if ($visitor->phone) · {{ $visitor->phone }} @endif
        </div>
        @isset($since)
            <div class="text-sm text-base-content/70">{{ __('Angemeldet seit :time', ['time' => $since->format('H:i')]) }}</div>
        @endisset
        @if ($credential?->checked_at)
            <div class="text-sm text-base-content/70">
                {{ __('Zuletzt geprüft :date von :name', ['date' => $credential->checked_at->format('d/m/Y'), 'name' => $credential->checkedBy?->full_name ?? trim(($credential->checkedBy?->first_name ?? '').' '.($credential->checkedBy?->name ?? '')) ?: '—']) }}
                @if ($credential->check_note) · {{ $credential->check_note }} @endif
            </div>
        @endif
    </div>

    @if ($credential)
        <div class="grid gap-2" x-data="{ copy(text) { navigator.clipboard?.writeText(text) } }">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span>{{ __('Nummer') }}:</span>
                <code class="rounded bg-base-200 px-2 py-1 text-base font-semibold">{{ $credential->number }}</code>
                <button type="button" class="btn btn-ghost btn-xs" x-on:click="copy(@js($credential->number))">{{ __('Kopieren') }}</button>
                <span>{{ __('Nachname') }}:</span>
                <code class="rounded bg-base-200 px-2 py-1 text-base font-semibold">{{ $visitor->name }}</code>
                <button type="button" class="btn btn-ghost btn-xs" x-on:click="copy(@js((string) $visitor->name))">{{ __('Kopieren') }}</button>
                @if ($credential->expires_on)
                    <span>{{ __('Läuft ab') }}: <strong>{{ $credential->expires_on->format('d/m/Y') }}</strong></span>
                @endif
            </div>
            <div class="flex flex-wrap items-end gap-2">
                @if ($type->verification_url)
                    <a href="{{ $type->verification_url }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm">{{ __('Bei der ausstellenden Stelle prüfen') }} ↗</a>
                @endif
                <label class="flex flex-col text-xs">
                    <span>{{ __('Ablaufdatum') }}</span>
                    <input type="date" class="input input-bordered input-sm" wire:model="expires.{{ $credential->id }}">
                </label>
                <label class="flex min-w-40 flex-1 flex-col text-xs">
                    <span>{{ __('Notiz (optional)') }}</span>
                    <input type="text" maxlength="250" class="input input-bordered input-sm" wire:model="notes.{{ $credential->id }}">
                </label>
                <button type="button" class="btn btn-success btn-sm" wire:click="markValid({{ $credential->id }})">{{ __('Gültig') }}</button>
                <button type="button" class="btn btn-error btn-sm" wire:click="markInvalid({{ $credential->id }})">{{ __('Ungültig') }}</button>
            </div>
            @error('expires.'.$credential->id)<p class="text-sm text-error">{{ $message }}</p>@enderror
        </div>
    @else
        <p class="text-sm text-base-content/70">{{ __('Keine Nummer gespeichert. Bitte die Person nach ihrem Nachweis fragen.') }}</p>
    @endif
</div>
