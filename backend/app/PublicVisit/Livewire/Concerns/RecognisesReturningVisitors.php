<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit\Livewire\Concerns;

use App\Models\Visitor;
use App\PublicVisit\ReturningVisitorCodes;
use App\PublicVisit\ReturningVisitors;
use App\PublicVisit\VisitorDetails;
use App\Settings\SettingsRepository;
use App\VisitorWorkflow\Models\FormField;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;

/**
 * Returning visitors on the sign-in screens: the visitor starts with their email address or
 * mobile number; an exact match offers the saved record ("That's me"). Saved details and answers
 * stay masked and are merged in on the server; to see or change them the visitor enters a code
 * sent to the saved email address. See ReturningVisitors for the privacy rules.
 */
trait RecognisesReturningVisitors
{
    /** Confirmed by the visitor: RegisterArrival reuses this visitor record. */
    #[Locked]
    public ?string $returningToken = null;

    /** Found by email or mobile number but not yet confirmed. */
    #[Locked]
    public ?string $pendingReturningToken = null;

    /** @var array<string, mixed> summary for "is this you?" (masked on phones) */
    #[Locked]
    public array $returningSummary = [];

    /** @var list<string> detail fields with a saved value that is not on screen (phones) */
    #[Locked]
    public array $savedFields = [];

    /** @var list<string> remembered answers that are not on screen (files, sensitive, phones) */
    #[Locked]
    public array $savedAnswerKeys = [];

    public string $lookupIdentifier = '';

    /** The visitor proved the record with the emailed code (the token says so too). */
    #[Locked]
    public bool $returningVerified = false;

    /** Open code challenge (ReturningVisitorCodes) while the visitor enters the code. */
    #[Locked]
    public ?string $codeChallenge = null;

    /** Masked address the code was sent to, for the screen. */
    #[Locked]
    public ?string $codeSentTo = null;

    public string $verificationCode = '';

    /**
     * "Email or mobile number": an exact match offers the saved record, anything else carries
     * the value into the details form.
     */
    public function lookupReturning(): void
    {
        abort_unless($this->returningEnabled() && $this->typeSlug !== null, 404);
        $this->resetErrorBag();

        $identifier = trim($this->lookupIdentifier);

        if ($identifier === '') {
            $this->step = 'details';

            return;
        }

        $fields = array_values(array_intersect(['email', 'phone'], VisitorDetails::visible($this->visitorType())));
        $email = $fields === [] || in_array('email', $fields, true);
        $phone = $fields === [] || in_array('phone', $fields, true);

        if (! ReturningVisitors::validIdentifier($identifier, $email, $phone)) {
            $this->addError('lookup', match (true) {
                $email && $phone => __('Bitte eine E-Mail-Adresse oder Handynummer eingeben.'),
                $email => __('Bitte eine gültige E-Mail-Adresse eingeben.'),
                default => __('Bitte eine gültige Handynummer eingeben.'),
            });

            return;
        }

        if (! RateLimiter::attempt('public-returning|'.$this->throttleScope(), 10, fn () => true, 60)) {
            $this->addError('lookup', __('Bitte einen Moment warten und erneut versuchen.'));

            return;
        }

        $visitor = app(ReturningVisitors::class)->find($identifier);
        $this->lookupIdentifier = '';

        if ($visitor !== null) {
            $this->offerReturning($visitor);
        } else {
            $this->clearReturning();
            $field = str_contains($identifier, '@') ? 'email' : 'phone';

            if (in_array($field, VisitorDetails::visible($this->visitorType()), true)) {
                $this->details[$field] = mb_substr($identifier, 0, 191);
            }
        }

        $this->step = 'details';
    }

    public function confirmReturning(): void
    {
        $returning = app(ReturningVisitors::class);
        $visitor = $returning->resolve($this->pendingReturningToken);

        if ($visitor === null) {
            $this->clearReturning();

            return;
        }

        $this->returningToken = $returning->token($visitor);
        $this->pendingReturningToken = null;
        $this->returningVerified = false;
        $this->fillFromSaved($visitor);
    }

    /**
     * "Change my details": email a one-time code to the saved address.
     */
    public function sendReturningCode(): void
    {
        $visitor = $this->returningVisitor();

        if ($visitor === null || $this->returningVerified) {
            return;
        }

        $this->resetErrorBag('verificationCode');

        if (! RateLimiter::attempt('public-returning-code|'.$this->throttleScope(), 5, fn () => true, 300)) {
            $this->addError('verificationCode', __('Bitte einen Moment warten und erneut versuchen.'));

            return;
        }

        $result = app(ReturningVisitorCodes::class)->send($visitor, $this->site());

        if ($result['status'] === ReturningVisitorCodes::SENT) {
            $this->codeChallenge = $result['challenge'];
            $this->codeSentTo = ReturningVisitors::maskEmail($visitor->email);
            $this->verificationCode = '';

            return;
        }

        $this->codeChallenge = null;
        $this->addError('verificationCode', match ($result['status']) {
            ReturningVisitorCodes::NO_EMAIL => __('Für Ihre gespeicherten Angaben ist keine E-Mail-Adresse hinterlegt. Bitte wenden Sie sich an den Empfang, um sie zu ändern.'),
            ReturningVisitorCodes::TOO_MANY => __('Es wurden bereits mehrere Codes gesendet. Bitte versuchen Sie es später erneut oder wenden Sie sich an den Empfang.'),
            default => __('Der Code konnte nicht gesendet werden. Bitte wenden Sie sich an den Empfang.'),
        });
    }

    /**
     * Check the emailed code; on success the saved values are shown and can be changed.
     */
    public function verifyReturningCode(): void
    {
        $visitor = $this->returningVisitor();
        $this->resetErrorBag('verificationCode');

        if ($visitor === null || $this->codeChallenge === null) {
            return;
        }

        $codes = app(ReturningVisitorCodes::class);
        $proved = $codes->verify($this->codeChallenge, $this->verificationCode, $this->site());
        $this->verificationCode = '';

        if ($proved === null || ! $proved->is($visitor)) {
            if (! $codes->isOpen($this->codeChallenge)) {
                $this->codeChallenge = null;
                $this->addError('verificationCode', __('Der Code ist abgelaufen oder wurde zu oft falsch eingegeben. Bitte fordern Sie einen neuen an.'));
            } else {
                $this->addError('verificationCode', __('Der Code stimmt nicht. Bitte erneut versuchen.'));
            }

            return;
        }

        $returning = app(ReturningVisitors::class);
        $this->returningToken = $returning->token($visitor, ReturningVisitors::PURPOSE_VERIFIED);
        $this->returningVerified = true;
        $this->codeChallenge = null;
        $this->codeSentTo = null;
        $this->returningSummary = $returning->summary($visitor, true);
        $this->fillFromSaved($visitor);
    }

    /**
     * Details and answers for a confirmed returning visitor: the saved values when verified,
     * otherwise empty fields marked "saved" (merged in on the server).
     */
    protected function fillFromSaved(Visitor $visitor): void
    {
        $returning = app(ReturningVisitors::class);
        $this->answers = [];
        $this->savedAnswerKeys = [];

        if ($this->showsSavedValues()) {
            $this->details = array_map(fn ($value) => $value === null ? null : (string) $value, $visitor->only(ReturningVisitors::SAVED_FIELDS));
            $this->savedFields = [];
        } else {
            $this->details = ['first_name' => null, 'name' => null, 'email' => null, 'phone' => null, 'company' => null];
            $this->savedFields = $returning->savedFields($visitor);
        }

        $definition = $this->definition();

        foreach ($returning->savedAnswers($visitor, $definition) as $key => $response) {
            $field = $definition->field($key)->field;

            if ($this->showsSavedValues() && $response->file_path === null && ! $field->is_sensitive) {
                $this->answers[$key] = $response->plainValue();
            } else {
                $this->savedAnswerKeys[] = $key;
            }
        }
    }

    public function declineReturning(): void
    {
        $this->clearReturning();
        $this->details = ['first_name' => null, 'name' => null, 'email' => null, 'phone' => null, 'company' => null];
        $this->answers = [];

        // Someone else (e.g. on a borrowed phone): let them look up their own record.
        if ($this->stepApplies('identify')) {
            $this->step = 'identify';
        }
    }

    protected function offerReturning(Visitor $visitor): void
    {
        $returning = app(ReturningVisitors::class);

        $this->pendingReturningToken = $returning->token($visitor);
        $this->returningSummary = $returning->summary($visitor, false);
        $this->returningToken = null;
        $this->returningVerified = false;
        $this->codeChallenge = null;
        $this->codeSentTo = null;
        $this->savedFields = [];
        $this->savedAnswerKeys = [];
    }

    protected function clearReturning(): void
    {
        $this->returningToken = null;
        $this->pendingReturningToken = null;
        $this->returningSummary = [];
        $this->returningVerified = false;
        $this->codeChallenge = null;
        $this->codeSentTo = null;
        $this->verificationCode = '';
        $this->savedFields = [];
        $this->savedAnswerKeys = [];
    }

    protected function returningVisitor(): ?Visitor
    {
        return app(ReturningVisitors::class)->resolve($this->returningToken);
    }

    /**
     * The saved photo of the visitor being offered or confirmed, as a kiosk-only image URL.
     */
    protected function returningPhotoUrl(): ?string
    {
        if (! $this->showsSavedValues() || ($this->returningToken === null && $this->pendingReturningToken === null)) {
            return null;
        }

        $returning = app(ReturningVisitors::class);
        $visitor = $returning->resolve($this->returningToken ?? $this->pendingReturningToken);
        $path = $visitor !== null ? $returning->latestPhoto($visitor) : null;

        return $path !== null ? route('kiosk.returning-photo', ['token' => $returning->photoToken($path, $this->site()->getKey())]) : null;
    }

    /**
     * Saved photos for remembered photo questions (kiosk only), keyed by field key.
     *
     * @return array<string, string>
     */
    protected function savedAnswerPhotos(): array
    {
        $visitor = $this->showsSavedValues() ? $this->returningVisitor() : null;

        if ($visitor === null || $this->typeSlug === null) {
            return [];
        }

        $returning = app(ReturningVisitors::class);
        $photos = [];

        foreach ($returning->savedAnswers($visitor, $this->definition()) as $key => $response) {
            if ($response->field_type === FormField::TYPE_PHOTO && $response->file_path !== null) {
                $photos[$key] = route('kiosk.returning-photo', ['token' => $returning->photoToken($response->file_path, $this->site()->getKey())]);
            }
        }

        return $photos;
    }

    /**
     * What the details step validates: typed values, with saved ones for empty fields.
     *
     * @return array<string, mixed>
     */
    protected function detailsForValidation(): array
    {
        $visitor = $this->returningVisitor();

        return $visitor !== null ? app(ReturningVisitors::class)->merge($visitor, $this->details, $this->showsSavedValues()) : $this->details;
    }

    /**
     * Answers with remembered ones for questions left empty.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    protected function answersForValidation(array $answers): array
    {
        $visitor = $this->returningVisitor();

        return $visitor !== null ? app(ReturningVisitors::class)->mergeAnswers($visitor, $this->definition(), $answers, $this->showsSavedValues()) : $answers;
    }

    /**
     * Saved values (and the saved photo) are shown only after the visitor proved the record with
     * the emailed code; until then every screen shows a masked summary.
     */
    protected function showsSavedValues(): bool
    {
        return $this->returningVerified && app(ReturningVisitors::class)->isVerified($this->returningToken);
    }

    protected function returningEnabled(): bool
    {
        return (string) app(SettingsRepository::class)->forSite($this->site())
            ->get('organisation.visitor_experience.returning_visitors') !== '0';
    }
}
