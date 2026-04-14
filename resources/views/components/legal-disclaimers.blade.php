@props(['show' => []])

@php
    $disclaimers = [
        'fedex' => 'FedEx service marks are owned by Federal Express Corporation and are used by permission.',
    ];

    $active = collect($show)
        ->filter(fn ($key) => isset($disclaimers[$key]))
        ->map(fn ($key) => $disclaimers[$key])
        ->values();
@endphp

@if ($active->isNotEmpty())
    <div class="mt-4 border-t border-gray-200 pt-3 dark:border-gray-700">
        @foreach ($active as $text)
            <p class="text-xs text-gray-400 dark:text-gray-500">{{ $text }}</p>
        @endforeach
    </div>
@endif
