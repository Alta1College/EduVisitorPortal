<x-mail.message_component :message="$message">

# {{__('Besuch eingetroffen')}}

{{ $greeting }}

{{__('soeben ist folgender Besucher bei uns eingetroffen')}}:

{{-- Fork (Phase 11): visitor-typed text is Markdown-escaped (no injected links or table breaks). --}}
<x-mail::table>
    | {{__('Vorname')}}       | {{__('Nachname')}}      | {{__('Titel')}} |  {{__('Firma')}}         |
    | :-----------: | :-----------: | :----------:  | :----------:  |
    |{{ \App\Support\MarkdownText::escape($visitor->first_name) }} | {{ \App\Support\MarkdownText::escape($visitor->name) }} | {{ \App\Support\MarkdownText::escape($visitor->title) }} | {{ \App\Support\MarkdownText::escape($visitor->company) }} |
</x-mail::table>

<x-mail::button :url='route("overview")'>
    {{__('Weitere Informationen')}}
</x-mail::button>

</x-mail.message_component>
