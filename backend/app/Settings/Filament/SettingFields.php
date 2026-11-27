<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Settings\Filament;

use App\Models\User;
use App\Reports\ReportRecipients;
use App\Rules\SafeRasterImage;
use App\Settings\SettingDefinition;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsRepository;
use App\Support\RasterImageUpload;
use App\Support\UserPreferences;
use App\Teams\TeamsBotMessenger;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * Builds Filament form fields from setting definitions, shared by the organisation and site
 * settings pages so labels, validation and upload handling stay identical.
 */
final class SettingFields
{
    /**
     * German source strings, translated through lang/*.json like the rest of the app.
     *
     * @return array<string, array{0: string, 1?: string}> key => [label, helper text]
     */
    public static function texts(): array
    {
        return [
            'branding.name' => ['Name der Organisation'],
            'organisation.contact_email' => ['Kontakt-E-Mail'],
            'organisation.contact_phone' => ['Kontakttelefon'],
            'organisation.address' => ['Anschrift'],
            'branding.logo_light' => ['Logo (helles Design)', 'PNG, JPEG oder WebP.'],
            'branding.logo_dark' => ['Logo (dunkles Design)', 'PNG, JPEG oder WebP.'],
            'branding.mail_logo' => ['Logo in E-Mails'],
            'branding.badge_logo' => ['Logo auf Besucherausweisen'],
            'organisation.colors.primary' => ['Primärfarbe', 'Für Schaltflächen, Links und Auswahlfelder im Portal und im Verwaltungsbereich. Im dunklen Design wird sie bei Bedarf aufgehellt.'],
            'organisation.colors.secondary' => ['Sekundärfarbe'],
            'organisation.colors.accent' => ['Akzentfarbe'],
            'organisation.colors.foreground' => ['Vordergrundfarbe', 'Textfarbe im hellen Design.'],
            'organisation.colors.background' => ['Hintergrundfarbe', 'Seitenhintergrund im hellen Design. Wird nur angewendet, wenn Vorder- und Hintergrundfarbe gut lesbar sind.'],
            'branding.badge_accent_color' => ['Akzentfarbe des Besucherausweises'],
            'organisation.public_url' => ['Öffentliche Adresse', 'Adresse, unter der Besucher das Portal von ihrem Handy erreichen, z. B. https://besuch.example.org. Wird für QR-Codes verwendet. Leer: die Adresse, unter der die Seite gerade geöffnet ist.'],
            'organisation.visitor_experience.welcome_heading' => ['Begrüßungsüberschrift', 'Am Kiosk unter dem tageszeitabhängigen Gruß („Guten Morgen!“). Leer: „Gäste, bitte hier beginnen.“'],
            'organisation.visitor_experience.welcome_message' => ['Begrüßungstext'],
            'organisation.visitor_experience.visitor_type_question' => ['Frage nach dem Besuchertyp', 'Steht am Kiosk über den Besuchertypen. Leer: „Was führt Sie zu uns?“'],
            'organisation.visitor_experience.show_greeting' => ['Tageszeitabhängigen Gruß anzeigen', '„Guten Morgen!“, „Guten Tag!“ oder „Guten Abend!“ über der Begrüßungsüberschrift am Kiosk. Standard: Ja.'],
            'organisation.visitor_experience.heading_details' => ['Überschrift: Angaben', 'Leer: „Ihre Angaben“'],
            'organisation.visitor_experience.heading_host' => ['Überschrift: Gastgeber', 'Leer: „Wen besuchen Sie?“'],
            'organisation.visitor_experience.heading_questions' => ['Überschrift: Fragen', 'Leer: „Ein paar Fragen“'],
            'organisation.visitor_experience.heading_policies' => ['Überschrift: Richtlinien', 'Leer: „Bitte lesen und bestätigen“'],
            'organisation.visitor_experience.heading_confirm' => ['Überschrift: Bestätigen', 'Leer: „Alles richtig?“'],
            'organisation.visitor_experience.heading_done' => ['Überschrift nach der Anmeldung', 'Leer: „Danke, :name!“. :name wird durch den Vornamen ersetzt.'],
            'organisation.visitor_experience.returning_visitors' => ['Wiederkehrende Besucher erkennen', 'Besucher können gespeicherte Angaben mit exakter E-Mail-Adresse oder Handynummer und Nachname wiederverwenden; auf dem eigenen Handy auf Wunsch gemerkt. Standard: Ja.'],
            'organisation.visitor_experience.email_sign_out_link' => ['Abmeldelink per E-Mail senden', 'Besucher mit E-Mail-Adresse erhalten nach der Anmeldung einen einmaligen Link zum Abmelden. Standard: Ja.'],
            'organisation.visitor_experience.sign_in_label' => ['Beschriftung „Anmelden“'],
            'organisation.visitor_experience.sign_out_label' => ['Beschriftung „Abmelden“'],
            'organisation.visitor_experience.visitor_instructions' => ['Hinweise für Besucher'],
            'organisation.visitor_experience.sign_in_confirmation' => ['Bestätigung nach der Anmeldung'],
            'organisation.visitor_experience.sign_out_confirmation' => ['Bestätigung nach der Abmeldung'],
            'organisation.visitor_experience.badge_footer' => ['Hinweis unten auf dem Ausweis', 'Z. B. „Bitte sichtbar tragen und beim Gehen abgeben.“ Leer lassen für den Standardtext.'],
            'organisation.visitor_experience.auto_sign_out_time' => ['Besucher automatisch abmelden um', 'Uhrzeit (24 h, z. B. 18:00) in der Zeitzone des Standorts; wer dann noch angemeldet ist, wird abgemeldet. Pro Standort änderbar, „off“ schaltet es dort ab. Während einer Anwesenheitskontrolle wird niemand automatisch abgemeldet.'],
            'organisation.visitor_experience.kiosk_camera_zoom' => ['Kamera-Zoom am Kiosk (%)', 'Vergrößert das Kamerabild am Kiosk, damit Kopf und Schultern statt der ganzen Person im Foto sind. 100 zeigt das ganze Kamerabild. Pro Standort änderbar. Standard: 160.'],
            'organisation.visitor_experience.kiosk_hours' => ['Öffnungszeiten der Kiosks', 'Montag bis Freitag in der Zeitzone des Standorts, z. B. 07:00-18:00. Die Startseite des Verwaltungsbereichs meldet Kiosks, die in dieser Zeit 15 Minuten lang nicht erreichbar waren. Pro Standort änderbar, „off“ schaltet die Meldung dort ab. Standard: 07:00-18:00.'],
            'branding.monitor_fallback_heading' => ['Überschrift der Willkommensanzeige'],
            'branding.monitor_fallback_subheading' => ['Unterzeile der Willkommensanzeige'],
            'organisation.reception_host_user_id' => ['Empfangskontakt', 'Wird als Gastgeber eingetragen, wenn ein Besuchertyp keinen Gastgeber braucht oder der Besucher keinen auswählt, z. B. bei Lieferungen.'],
            'organisation.entra_host_group_ids' => ['Entra-Gastgebergruppen', 'Objekt-IDs von Microsoft-Entra-Gruppen (durch Komma getrennt). Ihre Mitglieder werden als Gastgeber an diesem Standort synchronisiert. Pro Standort änderbar; leer lassen, um Gastgeber nur lokal zu verwalten.'],
            'organisation.host_notifications.email' => ['Gastgeber per E-Mail benachrichtigen', 'Standard für Gastgeber, die in ihrem Profil nichts anderes gewählt haben. Standard: Ja.'],
            'organisation.host_notifications.teams' => ['Gastgeber in Microsoft Teams benachrichtigen', 'Standard für Gastgeber, die in ihrem Profil nichts anderes gewählt haben. Erfordert die Einrichtung der Teams-App (docs/fork/teams-notifications.md). Standard: Nein.'],
            'organisation.reports.weekly_recipients' => ['Empfänger Wochenbericht', 'E-Mail-Adressen, durch Komma getrennt. Jeden Montag um 7 Uhr: Anmeldungen nach Standort und Besuchertyp, Stoßzeiten, vergessene Abmeldungen, Nachweise mit Handlungsbedarf und Räumungsübungen der Vorwoche – nur Zahlen, keine Namen. Pro Standort änderbar; leer: kein Bericht.'],
            'organisation.reports.monthly_recipients' => ['Empfänger Monatsbericht Nachweise und Sicherheit', 'E-Mail-Adressen, durch Komma getrennt. Am 1. des Monats um 7 Uhr: Nachweise (mit Namen der Personen mit Handlungsbedarf), Räumungsübungen und ihre Ergebnisse, Richtlinienversionen und Löschläufe des Vormonats. Pro Standort änderbar; leer: kein Bericht.'],
            'organisation.reports.roll_call_recipients' => ['Empfänger Protokoll Räumungsübung und Notfall', 'E-Mail-Adressen, durch Komma getrennt. Nach dem Beenden jeder Anwesenheitskontrolle: wer auf der Liste stand, wer anwesend war oder das Gelände verlassen hatte, und die Zustellung der Notfallnachrichten (ohne Sicherheitshinweise). Pro Standort änderbar; leer: kein Protokoll.'],
            'organisation.emergency.drill_warning_days' => ['Warnen, wenn so viele Tage keine Übung stattfand', 'Die Startseite des Verwaltungsbereichs hebt Standorte hervor, deren letzte Räumungsübung länger zurückliegt. Pro Standort änderbar. Standard: 90.'],
            'app.locale' => ['Standardsprache', 'Gilt, wenn weder Benutzer noch Browser eine unterstützte Sprache vorgeben.'],
            'privacy.visit_retention_days' => ['Aufbewahrung von Besuchen (Tage)', 'Ältere Besuche werden automatisch gelöscht, sofern keine Aufbewahrungssperre besteht.'],
            'privacy.notification_retention_days' => ['Aufbewahrung von Benachrichtigungen (Tage)'],
            'privacy.run_log_retention_days' => ['Aufbewahrung der Löschprotokolle (Tage)'],
            'organisation.audit_retention_days' => ['Aufbewahrung des Audit-Protokolls (Tage)', 'Mindestens 30 Tage.'],
            'organisation.evidence_retention_days' => ['Aufbewahrung von Nachweisdateien (Tage)', 'Fotos, Unterschriften und hochgeladene Nachweise werden danach gelöscht. Die Antworten selbst bleiben bis zur Löschung des Besuchs erhalten.'],
        ];
    }

    /**
     * Which Teams delivery the server is configured for (TEAMS_DELIVERY), shown with the setting.
     */
    private static function teamsDelivery(): string
    {
        return TeamsBotMessenger::chatDelivery()
            ? __('Zustellung: Chatnachricht des Teams-Bots (TEAMS_DELIVERY=chat).')
            : __('Zustellung: Benachrichtigung im Teams-Aktivitätsfeed (TEAMS_DELIVERY=activity).');
    }

    public static function label(string $key): string
    {
        return __(self::texts()[$key][0] ?? $key);
    }

    /**
     * @param  string|null  $inheritedHint  Shown on site pages: the value used when the field is left empty.
     */
    public static function make(SettingDefinition $definition, bool $required, ?string $inheritedHint = null): Field
    {
        $field = match ($definition->type) {
            SettingDefinition::TYPE_TEXT => Textarea::make($definition->field())->rows(3),
            SettingDefinition::TYPE_EMAIL => TextInput::make($definition->field())->email(),
            SettingDefinition::TYPE_COLOR => ColorPicker::make($definition->field())->hex()->regex(SettingsRegistry::COLOR_PATTERN),
            SettingDefinition::TYPE_INTEGER => TextInput::make($definition->field())->integer(),
            SettingDefinition::TYPE_LOCALE => Select::make($definition->field())->options(self::localeOptions())->native(false),
            SettingDefinition::TYPE_IMAGE => self::imageUpload($definition),
            SettingDefinition::TYPE_YES_NO => Select::make($definition->field())->options(['1' => __('Ja'), '0' => __('Nein')])->native(false),
            SettingDefinition::TYPE_USER => Select::make($definition->field())
                ->options(fn (): array => User::query()->where('is_active', true)->orderBy('first_name')->orderBy('name')
                    ->get(['id', 'first_name', 'name'])
                    ->mapWithKeys(fn ($user) => [$user->id => trim($user->first_name.' '.$user->name)])->all())
                ->searchable()
                ->native(false),
            default => TextInput::make($definition->field()),
        };

        if ($definition->type !== SettingDefinition::TYPE_IMAGE) {
            $field->rules(array_values(array_filter(
                $definition->rules,
                // The colour picker already applies its pattern; avoid a duplicate message. Every
                // other pattern (times, recipient lists, group IDs) must be checked here.
                fn ($rule): bool => ! ($definition->type === SettingDefinition::TYPE_COLOR && is_string($rule) && str_starts_with($rule, 'regex:')),
            )));
        }

        $helper = self::texts()[$definition->key][1] ?? null;
        $helperText = trim(implode(' ', array_filter([
            $helper !== null ? __($helper) : null,
            $definition->key === 'organisation.host_notifications.teams' ? self::teamsDelivery() : null,
            $inheritedHint !== null && in_array($definition->key, [ReportRecipients::WEEKLY, ReportRecipients::MONTHLY, ReportRecipients::ROLL_CALL], true)
                ? __('Für diesen Standort: leer übernimmt die Adressen der Organisation; „none“ eingeben, damit dieser Standort keinen Bericht erhält.')
                : null,
            $inheritedHint,
        ])));

        return $field
            ->label(self::label($definition->key))
            ->required($required)
            ->helperText($helperText !== '' ? $helperText : null);
    }

    /**
     * @return array<string, string>
     */
    public static function localeOptions(): array
    {
        $names = ['de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'cs' => 'Čeština'];
        $options = [];

        foreach (UserPreferences::supportedLocales() as $locale) {
            $options[$locale] = $names[$locale] ?? $locale;
        }

        return $options;
    }

    private static function imageUpload(SettingDefinition $definition): FileUpload
    {
        return FileUpload::make($definition->field())
            ->image()
            ->disk(SettingsRepository::IMAGE_DISK)
            ->directory(SettingsRepository::IMAGE_DIRECTORY)
            ->visibility('public')
            // Raster only (no SVG). SafeRasterImage re-checks the real content and strips metadata.
            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
            ->maxSize(RasterImageUpload::maxSizeKilobytes())
            ->rule(new SafeRasterImage);
    }
}
