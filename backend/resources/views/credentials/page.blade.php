<div class="grid min-w-0 gap-5">
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <section class="flex flex-wrap items-end gap-4 rounded-box border border-base-300 bg-base-100 p-5">
        <label class="flex min-w-64 flex-1 flex-col gap-1">
            <span class="text-sm font-medium text-base-content/70">{{ __('Suche nach Name, E-Mail oder Nummer') }}</span>
            <input type="search" class="input input-bordered" wire:model.live.debounce.400ms="query" autocomplete="off">
        </label>
        @if ($types->count() > 1)
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium text-base-content/70">{{ __('Nachweis') }}</span>
                <select class="select select-bordered" wire:model.live="typeId">
                    <option value="">{{ __('Alle') }}</option>
                    @foreach ($types as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if ($sites->count() > 1)
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium text-base-content/70">{{ __('Standort') }}</span>
                <select class="select select-bordered" wire:model.live="siteId">
                    @foreach ($sites as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </section>

    @if ($results !== null)
        <section class="rounded-box border border-base-300 bg-base-100">
            <h2 class="border-b border-base-300 px-4 py-3 text-xl font-bold">{{ trans_choice(':count Treffer|:count Treffer', $results->count(), ['count' => $results->count()]) }}</h2>
            @forelse ($results as $credential)
                @include('credentials.row', ['key' => 'r-'.$credential->id, 'credential' => $credential, 'visitor' => $credential->visitor, 'type' => $credential->type, 'state' => $credential->state()])
            @empty
                <p class="p-4 text-base-content/70">{{ __('Nichts gefunden. Nummern werden genau verglichen (Leerzeichen egal).') }}</p>
            @endforelse
        </section>
    @else
        <section class="rounded-box border border-base-300 bg-base-100" wire:poll.30s>
            <h2 class="border-b border-base-300 px-4 py-3 text-xl font-bold">
                {{ __('Jetzt auf dem Gelände, Prüfung nötig') }}@if ($site) <span class="font-normal text-base-content/70">· {{ $site->name }}</span>@endif
            </h2>
            @forelse ($onSite as $person)
                @include('credentials.row', ['key' => 'o-'.$person['visitor']->id.'-'.$person['type']->id, 'credential' => $person['credential'], 'visitor' => $person['visitor'], 'type' => $person['type'], 'state' => $person['state'], 'since' => $person['checked_in_at']])
            @empty
                <p class="p-4 text-base-content/70">{{ __('Alle angemeldeten Personen haben einen geprüften Nachweis.') }}</p>
            @endforelse
        </section>

        <section class="rounded-box border border-base-300 bg-base-100">
            <h2 class="border-b border-base-300 px-4 py-3 text-xl font-bold">{{ __('Weitere Nachweise mit offener Prüfung') }}</h2>
            @forelse ($attention as $credential)
                @include('credentials.row', ['key' => 'a-'.$credential->id, 'credential' => $credential, 'visitor' => $credential->visitor, 'type' => $credential->type, 'state' => $credential->state()])
            @empty
                <p class="p-4 text-base-content/70">{{ __('Keine offenen Prüfungen.') }}</p>
            @endforelse
        </section>
    @endif

    @if ($canImport && $types->isNotEmpty())
        <section class="rounded-box border border-base-300 bg-base-100 p-5">
            <h2 class="text-xl font-bold">{{ __('Nachweise importieren') }}</h2>
            <p class="mt-1 text-sm text-base-content/70">{{ __('CSV-Datei, z. B. ein Export aus der Personalverwaltung. Spalten: Vorname, Nachname, Nummer; optional E-Mail, Mobilnummer, Ablaufdatum (TT/MM/JJJJ). Personen werden über E-Mail oder Mobilnummer zugeordnet, sonst neu angelegt. Importierte Nummern gelten als ungeprüft.') }}</p>
            <form class="mt-3 flex flex-wrap items-end gap-3" wire:submit="import">
                <label class="flex flex-col gap-1">
                    <span class="text-sm">{{ __('Nachweis') }}</span>
                    <select class="select select-bordered" wire:model="importTypeId">
                        @foreach ($types as $option)
                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                </label>
                <input type="file" accept=".csv,text/csv" class="file-input file-input-bordered" wire:model="importFile">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('Importieren') }}</button>
            </form>
            @error('importFile')<p class="mt-2 text-sm text-error">{{ $message }}</p>@enderror
            @if ($importResult)
                <div class="alert mt-3">
                    <div>
                        <p>{{ __(':imported importiert, :created Personen neu angelegt, :skipped übersprungen.', ['imported' => $importResult['imported'], 'created' => $importResult['created_people'], 'skipped' => count($importResult['skipped'])]) }}</p>
                        @foreach (array_slice($importResult['skipped'], 0, 20) as $line)
                            <p class="text-sm">{{ $line }}</p>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>
    @endif
</div>
