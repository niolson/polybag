{{--
    Enter in global search opens the result when there is exactly one.

    A scanner types a whole code and presses Enter within milliseconds, before
    the search's debounce has fired, and Filament's search does nothing on Enter.
    So Enter sends the search at once, waits for the results, and follows the
    only one. Scanning a pack slip's `S216` opens that Shipment (ADR-0007).

    Loaded on every panel page by a HEAD_END render hook in AppPanelProvider.
    Safe to include more than once.
--}}
<script>
    if (! window.polybagGlobalSearchEnter) {
        window.polybagGlobalSearchEnter = true;

        document.addEventListener('keydown', async (event) => {
            const input = event.target;

            if (event.key !== 'Enter' || event.isComposing || ! (input instanceof HTMLInputElement)) {
                return;
            }

            const root = input.closest('.fi-global-search-field')?.closest('[wire\\:id]');
            const search = input.value;

            if (! root || ! window.Livewire || search.trim() === '') {
                return;
            }

            event.preventDefault();

            // Resolves once the results for this search have been rendered.
            await window.Livewire.find(root.getAttribute('wire:id')).$set('search', search);
            await new Promise((resolve) => requestAnimationFrame(resolve));

            const links = root.querySelectorAll('.fi-global-search-result-link');

            // Typing on after Enter means the packer meant something else.
            if (links.length === 1 && input.value === search) {
                window.location.assign(links[0].href);
            }
        });
    }
</script>
