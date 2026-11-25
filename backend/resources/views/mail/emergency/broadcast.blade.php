{{-- SPDX-FileCopyrightText: 2026 Simon Grant --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
@php($site = \App\Support\MarkdownText::escape($siteName))
<x-mail.message_component :message="$message">
# {{ $drill ? __('Übung in :site', ['site' => $site]) : __('Notfall in :site', ['site' => $site]) }}

**{{ $drill
    ? __('ÜBUNG – kein echter Notfall. :site: Bitte das Gelände jetzt verlassen und der Notfallbeschilderung zum Sammelplatz folgen.', ['site' => $site])
    : __('NOTFALL in :site: Bitte das Gelände jetzt verlassen und der Notfallbeschilderung zum Sammelplatz folgen. Erst nach Entwarnung zurückkehren.', ['site' => $site]) }}**
</x-mail.message_component>
