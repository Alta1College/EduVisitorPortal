<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Credentials\Livewire;

use App\Credentials\CredentialImportFile;
use App\Credentials\CredentialRegister;
use App\Credentials\CredentialType;
use App\Credentials\VisitorCredential;
use App\Models\Site;
use App\Models\User;
use App\Support\ForkPermissions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Reception's credentials page: who on site needs a check, records needing attention, search by
 * name, email or number, recording a manual check with the issuing body, and (admins) import.
 */
class CredentialsPage extends Component
{
    use WithFileUploads;

    public ?int $siteId = null;

    public ?int $typeId = null;

    public string $query = '';

    /** @var array<int|string, ?string> credential id => expiry date (Y-m-d) entered when checking */
    public array $expires = [];

    /** @var array<int|string, ?string> credential id => note entered when checking */
    public array $notes = [];

    /** @var TemporaryUploadedFile|null */
    public $importFile = null;

    public ?int $importTypeId = null;

    /** @var array{imported: int, created_people: int, skipped: list<string>}|null */
    public ?array $importResult = null;

    public function mount(): void
    {
        abort_unless($this->user()->can(ForkPermissions::CHECK_CREDENTIALS), 403);

        $sites = $this->sites();
        $own = $this->user()->site_id;
        $this->siteId = $sites->contains('id', $own) ? (int) $own : $sites->first()?->getKey();
        $this->importTypeId = $this->types()->first()?->getKey();
    }

    public function markValid(int $credentialId): void
    {
        $this->check($credentialId, true);
    }

    public function markInvalid(int $credentialId): void
    {
        $this->check($credentialId, false);
    }

    public function import(): void
    {
        abort_unless($this->user()->can(ForkPermissions::IMPORT_CREDENTIALS), 403);

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'importTypeId' => ['required', 'integer', 'exists:credential_types,id'],
        ]);

        $rows = CredentialImportFile::rows((string) $this->importFile->getRealPath());

        if ($rows === null) {
            $this->addError('importFile', __('Die Datei braucht die Spalten Vorname, Nachname und Nummer (E-Mail, Mobilnummer und Ablaufdatum sind optional).'));

            return;
        }

        $type = CredentialType::query()->findOrFail($this->importTypeId);
        $this->importResult = app(CredentialRegister::class)->import($type, $rows, $this->user());
        $this->importFile = null;
    }

    public function render(): View
    {
        $site = $this->siteId !== null ? $this->sites()->firstWhere('id', $this->siteId) : null;
        $register = app(CredentialRegister::class);
        $results = mb_strlen(trim($this->query)) >= CredentialRegister::MIN_SEARCH_LENGTH
            ? $register->search($this->query, $this->user(), $this->typeId)
            : null;

        $onSite = $site !== null ? $register->onSiteAttention($site) : [];

        // Credentials already listed under "On site now" are not repeated in the waiting list.
        $shownOnSite = collect($onSite)->pluck('credential')->filter()->map->getKey()->all();
        $attention = $results === null
            ? $register->needingAttention($this->typeId, 50)
                ->reject(fn ($credential): bool => in_array($credential->getKey(), $shownOnSite, true))
                ->values()
            : collect();

        return view('credentials.page', [
            'sites' => $this->sites(),
            'site' => $site,
            'types' => $this->types(),
            'onSite' => $onSite,
            'attention' => $attention,
            'results' => $results,
            'canImport' => $this->user()->can(ForkPermissions::IMPORT_CREDENTIALS),
            'states' => self::stateLabels(),
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: string}> state => [label, badge class]
     */
    public static function stateLabels(): array
    {
        return [
            VisitorCredential::VALID => [__('Geprüft, gültig'), 'badge-success'],
            VisitorCredential::EXPIRING => [__('Läuft bald ab'), 'badge-warning'],
            VisitorCredential::RECHECK_DUE => [__('Erneute Prüfung fällig'), 'badge-warning'],
            VisitorCredential::UNCHECKED => [__('Nicht geprüft'), 'badge-warning'],
            VisitorCredential::EXPIRED => [__('Abgelaufen'), 'badge-error'],
            VisitorCredential::INVALID => [__('Ungültig'), 'badge-error'],
            'missing' => [__('Keine Nummer angegeben'), 'badge-error'],
        ];
    }

    private function check(int $credentialId, bool $valid): void
    {
        $this->validate([
            "expires.{$credentialId}" => ['nullable', 'date', 'after:2000-01-01', 'before:2100-01-01'],
            "notes.{$credentialId}" => ['nullable', 'string', 'max:250'],
        ]);

        $credential = VisitorCredential::query()->findOrFail($credentialId);
        $expires = filled($this->expires[$credentialId] ?? null) ? Carbon::parse($this->expires[$credentialId]) : null;

        app(CredentialRegister::class)->markChecked($credential, $this->user(), $valid, $expires, $this->notes[$credentialId] ?? null);
        unset($this->expires[$credentialId], $this->notes[$credentialId]);

        session()->flash('status', $valid ? __('Als gültig gespeichert.') : __('Als ungültig gespeichert.'));
    }

    /**
     * @return Collection<int, Site>
     */
    private function sites(): Collection
    {
        $query = Site::query()->where('is_active', true)->orderBy('name');

        if (! $this->user()->can('ViewAny:Visit')) {
            $query->whereIn('id', $this->user()->assignedSiteIds()->all());
        }

        return $query->get();
    }

    /**
     * @return Collection<int, CredentialType>
     */
    private function types(): Collection
    {
        return CredentialType::query()->where('is_active', true)->orderBy('name')->get();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->can(ForkPermissions::CHECK_CREDENTIALS), 403);

        return $user;
    }
}
