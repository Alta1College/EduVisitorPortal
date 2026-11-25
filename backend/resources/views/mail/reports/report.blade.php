{{-- SPDX-FileCopyrightText: 2026 Simon Grant --}}
{{-- SPDX-License-Identifier: GPL-3.0-or-later --}}
<x-mail.message_component :message="$message">
# {{ \App\Support\MarkdownText::escape($title) }}

{{ \App\Support\MarkdownText::escape($period) }}

@foreach ($lines as $line)
- {{ \App\Support\MarkdownText::escape($line) }}
@endforeach

@if ($pdfMissing)
{{ __('Das PDF konnte diesmal nicht erstellt werden; die Zahlen stehen in der CSV-Datei im Anhang.') }}
@else
{{ __('Der vollständige Bericht ist als PDF angehängt, die Zahlen zusätzlich als CSV-Datei.') }}
@endif

{{ __('Diese E-Mail wurde automatisch versandt. Die Empfänger werden in den Organisationseinstellungen unter „Berichte“ festgelegt.') }}
</x-mail.message_component>
