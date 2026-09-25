<?php

namespace App\DataTransferObjects\PostageSources;

use Illuminate\Support\Collection;

/**
 * Who to ask for this package's postage: the source instances rating builds
 * its tasks from (ADR-0006 decision 4).
 */
readonly class PostageSourceResolution
{
    /**
     * @param  Collection<int, PostageSourceCandidate>  $candidates  channel, then off-Amazon, then one per direct carrier
     */
    public function __construct(
        public Collection $candidates,
    ) {}

    /**
     * The channel source bound to this package, of which there is at most one:
     * a purchase is keyed to an order that lives in exactly one account.
     */
    public function channel(): ?PostageSourceCandidate
    {
        return $this->candidates->first(
            fn (PostageSourceCandidate $candidate): bool => $candidate->isChannel()
        );
    }

    /**
     * @return Collection<int, PostageSourceCandidate>
     */
    public function forCarrier(string $carrier): Collection
    {
        return $this->candidates
            ->filter(fn (PostageSourceCandidate $candidate): bool => $candidate->carrier === $carrier)
            ->values();
    }
}
