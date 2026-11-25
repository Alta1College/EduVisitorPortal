{{-- SPDX-FileCopyrightText: 2026 Simon Grant --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
<x-mail.message_component :message="$message">
# {{ __('Ihr Bestätigungscode') }}

{{ __('Geben Sie diesen Code bei der Anmeldung in :site ein, um Ihre gespeicherten Angaben zu ändern:', ['site' => \App\Support\MarkdownText::escape($siteName)]) }}

## {{ $code }}

{{ __('Der Code gilt :minutes Minuten.', ['minutes' => $minutes]) }}

{{ __('Wenn Sie das nicht waren, können Sie diese E-Mail ignorieren. Ihre Angaben bleiben unverändert.') }}
</x-mail.message_component>
