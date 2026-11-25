{{--
    SPDX-FileCopyrightText: 2026 Simon Grant
    SPDX-License-Identifier: GPL-3.0-or-later

    Fork: saves the recovery codes already shown on the page as a .txt file. The file is built in
    the browser, so the codes are not sent or stored anywhere again.
--}}
@props(['codes' => []])

@php
    $codes = collect($codes)->filter(fn ($code) => is_string($code) && $code !== '')->values();
    $brandName = (string) config('branding.name', config('app.name', 'VisitorPortal'));
    $user = auth()->user();
    $lines = array_merge(
        [
            $brandName.' – '.__('Recovery Codes'),
            $user?->email ? (string) $user->email : null,
            now()->format('Y-m-d H:i'),
            '',
        ],
        $codes->all(),
        ['', __('Jeder Code kann nur einmal verwendet werden.')],
    );
    $text = implode("\r\n", array_filter($lines, fn ($line) => $line !== null))."\r\n";
    $fileName = \Illuminate\Support\Str::slug($brandName).'-recovery-codes.txt';
@endphp

@if ($codes->isNotEmpty())
    <button
        type="button"
        {{ $attributes->merge(['class' => 'btn btn-outline rounded-2xl']) }}
        data-recovery-codes-download
        data-file-name="{{ $fileName }}"
        data-content="{{ $text }}"
    >
        {{ __('Als Textdatei herunterladen') }}
    </button>

    @once
        <script>
            document.addEventListener('click', function (event) {
                const button = event.target.closest('[data-recovery-codes-download]');

                if (! button) {
                    return;
                }

                const blob = new Blob([button.dataset.content], { type: 'text/plain;charset=utf-8' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = button.dataset.fileName;
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
            });
        </script>
    @endonce
@endif
