@if ($connected)
    <span class="font-medium text-success-600 dark:text-success-400">Connected via OAuth</span>

    @if ($time)
        &mdash; {{ $time }}
    @endif

    @if ($scopes)
        <br>
        <span class="text-xs text-gray-500">Scopes: {{ $scopes }}</span>
    @endif
@else
    <span class="text-gray-400">Not connected</span>
@endif
