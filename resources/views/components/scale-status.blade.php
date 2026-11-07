{{--
    What the scale is saying about Weight, on one line that always keeps its height, so a
    status coming or going never moves the page. Reads the Alpine state of the page around
    it: scaleConnected, scaleStable, scaleUnderZero, minimumWeight and usesMinimumWeight().
--}}
<span class="block min-h-5 text-sm" data-testid="scale-status">
    <span x-show="!scaleConnected" x-cloak class="text-gray-500 dark:text-gray-400">
        No scale connected
    </span>
    <span x-show="scaleConnected && scaleUnderZero" x-cloak class="text-danger-600 dark:text-danger-400">
        Negative weight — zero the scale
    </span>
    <span x-show="scaleConnected && !scaleStable && !scaleUnderZero" x-cloak class="text-warning-500">
        Scale in motion...
    </span>
    <span x-show="usesMinimumWeight() && scaleStable && !scaleUnderZero" x-cloak class="text-gray-500 dark:text-gray-400">
        Too light for the scale to read. Ships as <span x-text="minimumWeight"></span> lbs.
    </span>
</span>
