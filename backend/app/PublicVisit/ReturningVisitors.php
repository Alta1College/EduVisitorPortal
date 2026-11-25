<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit;

use App\Credentials\VisitorCredential;
use App\Models\Visitor;
use App\Support\RequestTempFiles;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\VisitResponse;
use App\VisitorWorkflow\WorkflowDefinition;
use App\VisitorWorkflow\WorkflowRecorder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Recognition of returning visitors (brief §15). Every sign-in keeps the visitor record, and the
 * answers to questions marked "Remember for returning visitors", so the next visit can be
 * pre-filled.
 *
 * - Matching needs an exact email address or mobile number: no search, no partial match.
 * - The visitor must confirm ("That's me") before saved details are used.
 * - On every screen (kiosk and phone) only a masked summary is shown, and saved values are
 *   merged in on the server at submit, so knowing someone's email address or mobile number
 *   never reveals their details or photo.
 * - Saved details and saved answers cannot be changed until the visitor proves the record is
 *   theirs with a one-time code sent to the saved email address (ReturningVisitorCodes). Only
 *   then are the saved values shown and editable. A new photo may always be taken, so the
 *   badge shows the person who is actually there.
 */
class ReturningVisitors
{
    public const PURPOSE_CONFIRMED = 'confirmed';

    /** Confirmed and proved with the emailed code: saved values may be shown and changed. */
    public const PURPOSE_VERIFIED = 'verified';

    public const CONFIRMED_TTL_MINUTES = 30;

    public const PHOTO_TTL_MINUTES = 30;

    /** Detail fields that can be filled from a saved record. */
    public const SAVED_FIELDS = ['first_name', 'name', 'email', 'phone', 'company'];

    /**
     * Whether a lookup value is an email address or a mobile number (anything else, such as a
     * name, is refused rather than searched).
     */
    public static function validIdentifier(string $identifier, bool $email = true, bool $phone = true): bool
    {
        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            return $email && filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
        }

        return $phone && preg_match('/^[+]?[0-9 ().-]{8,25}$/', $identifier) === 1 && strlen(self::digits($identifier)) >= 8;
    }

    public function find(string $identifier): ?Visitor
    {
        $identifier = trim($identifier);

        if ($identifier === '' || mb_strlen($identifier) > 191 || ! self::validIdentifier($identifier)) {
            return null;
        }

        if (str_contains($identifier, '@')) {
            // The column collation compares case-insensitively, so the index is used.
            return Visitor::query()->where('email', $identifier)->latest('updated_at')->first();
        }

        $digits = self::digits($identifier);

        // Indexed on the last nine digits, then compared exactly.
        return Visitor::query()->where('phone_tail', self::phoneTail($digits))
            ->latest('updated_at')->limit(50)->get()
            ->first(fn (Visitor $visitor): bool => self::samePhone($digits, self::digits((string) $visitor->phone)));
    }

    /**
     * Last nine digits of a phone number (the indexed lookup key), or null without eight digits.
     */
    public static function phoneTail(?string $phone): ?string
    {
        $digits = self::digits((string) $phone);

        return strlen($digits) >= 8 ? substr($digits, -9) : null;
    }

    public function token(Visitor $visitor, string $purpose = self::PURPOSE_CONFIRMED): string
    {
        $expires = now()->addMinutes(self::CONFIRMED_TTL_MINUTES);

        return Crypt::encryptString(json_encode(['p' => $visitor->getKey(), 'u' => $purpose, 'e' => $expires->timestamp]));
    }

    public function resolve(?string $token): ?Visitor
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($payload) || ($payload['e'] ?? 0) < now()->timestamp
            || ! in_array($payload['u'] ?? null, [self::PURPOSE_CONFIRMED, self::PURPOSE_VERIFIED], true)) {
            return null;
        }

        return Visitor::query()->find($payload['p'] ?? 0);
    }

    /**
     * Whether a token belongs to a visitor who proved the record with the emailed code.
     */
    public function isVerified(?string $token): bool
    {
        if ($token === null || $token === '' || $this->resolve($token) === null) {
            return false;
        }

        $payload = json_decode(Crypt::decryptString($token), true);

        return is_array($payload) && ($payload['u'] ?? null) === self::PURPOSE_VERIFIED;
    }

    /**
     * What the screen may show to confirm "is this you?": the saved values on a kiosk, a masked
     * summary on a phone.
     *
     * @return array{first_name: string, last_name: string, email: ?string, phone: ?string, company: ?string, masked: bool}
     */
    public function summary(Visitor $visitor, bool $full): array
    {
        return [
            'first_name' => (string) $visitor->first_name,
            'last_name' => $full ? (string) $visitor->name : mb_substr((string) $visitor->name, 0, 1).'.',
            'email' => $full ? $visitor->email : self::maskEmail($visitor->email),
            'phone' => $full ? $visitor->phone : self::maskPhone($visitor->phone),
            'company' => $full ? $visitor->company : (filled($visitor->company) ? __('Firma gespeichert') : null),
            'masked' => ! $full,
        ];
    }

    /**
     * Which detail fields hold a saved value (so the screen can say "saved" instead of asking).
     *
     * @return list<string>
     */
    public function savedFields(Visitor $visitor): array
    {
        return array_values(array_filter(self::SAVED_FIELDS, fn (string $field): bool => filled($visitor->{$field})));
    }

    /**
     * Details for validation: what the visitor typed, with saved values for anything left empty.
     * Until the visitor is verified, a saved value always wins: typing cannot change it.
     *
     * @param  array<string, mixed>  $typed
     * @return array<string, mixed>
     */
    public function merge(Visitor $visitor, array $typed, bool $verified = false): array
    {
        $merged = $typed;

        foreach (self::SAVED_FIELDS as $field) {
            $value = $typed[$field] ?? null;

            if (! is_string($value) || trim($value) === '' || (! $verified && filled($visitor->{$field}))) {
                $merged[$field] = $visitor->{$field};
            }
        }

        return $merged;
    }

    /**
     * The visitor's latest answer to each remembered question in this workflow. File answers
     * count only while the evidence file still exists (it is purged after the retention period).
     *
     * @return array<string, VisitResponse> field key => response
     */
    public function savedAnswers(Visitor $visitor, WorkflowDefinition $definition): array
    {
        $saved = [];

        foreach ($definition->fields as $resolved) {
            $field = $resolved->field;

            if (! $field->remembersAnswer() && $field->credential_type_id === null) {
                continue;
            }

            $response = VisitResponse::query()
                ->where('visitor_id', $visitor->getKey())
                ->where('form_field_id', $field->getKey())
                ->latest('id')
                ->first();

            // A credential question also fills from the person's credential record (e.g. an
            // HR import), when that is newer than their last answer.
            $response = $this->fromCredential($visitor, $field, $response) ?? $response;

            if ($response === null) {
                continue;
            }

            $usable = in_array($field->type, FormField::FILE_TYPES, true)
                ? $response->file_path !== null && Storage::disk(WorkflowRecorder::EVIDENCE_DISK)->exists($response->file_path)
                : filled($response->value);

            if ($usable) {
                $saved[$field->key] = $response;
            }
        }

        return $saved;
    }

    /**
     * The credential record's number as an (unsaved) answer, if it is newer than the last answer.
     */
    private function fromCredential(Visitor $visitor, FormField $field, ?VisitResponse $response): ?VisitResponse
    {
        if ($field->credential_type_id === null) {
            return null;
        }

        $credential = VisitorCredential::query()
            ->where('visitor_id', $visitor->getKey())
            ->where('credential_type_id', $field->credential_type_id)
            ->whereHas('type', fn ($q) => $q->where('is_active', true))
            ->first();

        if ($credential === null || ($response !== null && $response->created_at !== null && $response->created_at->gte($credential->updated_at))) {
            return null;
        }

        return new VisitResponse([
            'visitor_id' => $visitor->getKey(),
            'form_field_id' => $field->getKey(),
            'field_key' => $field->key,
            'field_type' => $field->type,
            'is_sensitive' => true,
            'value' => VisitResponse::storableValue($credential->number, true),
        ]);
    }

    /**
     * Answers for validation: what the visitor gave, with saved answers for remembered questions
     * left empty. A saved file is copied, so this visit keeps its own evidence file. Until the
     * visitor is verified, a saved text answer (e.g. a card number) cannot be replaced; a new
     * photo or file can.
     *
     * @param  array<string, mixed>  $typed
     * @return array<string, mixed>
     */
    public function mergeAnswers(Visitor $visitor, WorkflowDefinition $definition, array $typed, bool $verified = false): array
    {
        $merged = $typed;

        foreach ($this->savedAnswers($visitor, $definition) as $key => $response) {
            $value = $typed[$key] ?? null;
            $locked = ! $verified && $response->file_path === null;

            if ($value !== null && $value !== '' && ! $locked) {
                continue;
            }

            $merged[$key] = $response->file_path !== null ? $this->fileCopy($response) : $response->plainValue();
        }

        return $merged;
    }

    /**
     * The visitor's latest remembered photo, for the kiosk's "is this you?" card.
     */
    public function latestPhoto(Visitor $visitor): ?string
    {
        $disk = Storage::disk(WorkflowRecorder::EVIDENCE_DISK);

        return VisitResponse::query()
            ->where('visitor_id', $visitor->getKey())
            ->where('field_type', FormField::TYPE_PHOTO)
            ->whereNotNull('file_path')
            ->whereHas('field', fn ($q) => $q->where('remember_for_returning', true))
            ->latest('id')
            ->limit(5)
            ->pluck('file_path')
            ->first(fn (string $path): bool => $disk->exists($path));
    }

    /**
     * Short-lived, site-bound token for showing a saved photo on that site's kiosks.
     */
    public function photoToken(string $path, int $siteId): string
    {
        return Crypt::encryptString(json_encode(['f' => $path, 's' => $siteId, 'e' => now()->addMinutes(self::PHOTO_TTL_MINUTES)->timestamp]));
    }

    public function resolvePhotoToken(string $token, int $siteId): ?string
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        $path = is_array($payload) ? ($payload['f'] ?? null) : null;

        if (! is_string($path) || ($payload['s'] ?? null) !== $siteId || ($payload['e'] ?? 0) < now()->timestamp
            || ! str_starts_with($path, WorkflowRecorder::EVIDENCE_DIRECTORY.'/') || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }

    public static function maskEmail(?string $email): ?string
    {
        if (blank($email) || ! str_contains((string) $email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', (string) $email, 2);
        $dot = strrpos($domain, '.');
        $tld = $dot !== false ? substr($domain, $dot) : '';

        return mb_substr($local, 0, 1).'•••@'.mb_substr($domain, 0, 1).'•••'.$tld;
    }

    public static function maskPhone(?string $phone): ?string
    {
        $digits = self::digits((string) $phone);

        return strlen($digits) >= 6 ? '•••• '.substr($digits, -3) : null;
    }

    private function fileCopy(VisitResponse $response): ?UploadedFile
    {
        $disk = Storage::disk(WorkflowRecorder::EVIDENCE_DISK);
        $contents = $disk->get($response->file_path);

        if ($contents === null) {
            return null;
        }

        $path = RequestTempFiles::write('saved', $contents); // deleted when the request ends

        return new UploadedFile($path, basename($response->file_path), $disk->mimeType($response->file_path) ?: null, null, true);
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }

    /**
     * Same number written differently: "0412 345 678", "+61 412 345 678" and "61412345678"
     * compare equal by their last nine digits; both must have at least eight.
     */
    private static function samePhone(string $a, string $b): bool
    {
        return strlen($a) >= 8 && strlen($b) >= 8 && substr($a, -9) === substr($b, -9);
    }
}
