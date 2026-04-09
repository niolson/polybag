@php
    $trackingDetails = $package->tracking_details ?? [];
    $events = $trackingDetails['events'] ?? [];
@endphp

<div class="space-y-6 text-sm text-gray-700 dark:text-gray-300">
    <div class="grid gap-4 md:grid-cols-2">
        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Carrier</div>
            <div class="mt-1">{{ $package->carrier ?? '—' }}</div>
        </div>
        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Tracking Number</div>
            <div class="mt-1 font-mono">{{ $package->tracking_number ?? '—' }}</div>
        </div>
        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Current Status</div>
            <div class="mt-1">{{ $package->tracking_status?->getLabel() ?? 'Unavailable' }}</div>
        </div>
        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Estimated Delivery</div>
            <div class="mt-1">{{ isset($trackingDetails['estimated_delivery_at']) ? \Carbon\Carbon::parse($trackingDetails['estimated_delivery_at'])->format('M j, Y') : '—' }}</div>
        </div>
        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Last Updated</div>
            <div class="mt-1">{{ $package->tracking_updated_at?->format('M j, Y g:i A') ?? '—' }}</div>
        </div>
        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Provider Message</div>
            <div class="mt-1">{{ $trackingDetails['message'] ?? '—' }}</div>
        </div>
    </div>

    @if (! empty($events))
        <div>
            <div class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Tracking Events</div>
            <div class="space-y-3">
                @foreach ($events as $event)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ $event['description'] ?? 'Tracking update' }}</div>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ isset($event['timestamp']) ? \Carbon\Carbon::parse($event['timestamp'])->format('M j, Y g:i A') : 'Unknown time' }}
                            @if (! empty($event['location']))
                                · {{ $event['location'] }}
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
            No scan events are available yet.
        </div>
    @endif
</div>
