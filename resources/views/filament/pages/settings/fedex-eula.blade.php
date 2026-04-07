<div x-data="{ scrolled: false }">
    <div
        class="h-72 overflow-y-auto rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm leading-relaxed text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
        x-on:scroll="scrolled = ($el.scrollTop + $el.clientHeight >= $el.scrollHeight - 4)"
        x-init="$nextTick(() => { scrolled = ($el.scrollTop + $el.clientHeight >= $el.scrollHeight - 4) })"
    >
        <pre class="whitespace-pre-wrap font-sans">{{ $eulaText }}</pre>
    </div>

    <p
        x-show="!scrolled"
        x-transition
        class="mt-2 text-xs text-warning-600 dark:text-warning-400"
    >
        Please scroll to the bottom to enable acceptance.
    </p>

    <label
        class="mt-3 flex cursor-pointer items-start gap-3"
        x-bind:class="{ 'opacity-40 pointer-events-none': !scrolled }"
    >
        <input
            type="checkbox"
            x-bind:disabled="!scrolled"
            x-on:change="$wire.set('fedexEulaAccepted', $event.target.checked)"
            class="mt-0.5 rounded border-gray-300 text-primary-600 shadow-sm dark:border-gray-600"
        >
        <span class="text-sm text-gray-700 dark:text-gray-300">
            I have read and agree to the terms of the FedEx End User License Agreement
        </span>
    </label>
</div>
