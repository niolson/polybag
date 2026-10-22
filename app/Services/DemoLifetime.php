<?php

namespace App\Services;

use App\Enums\AppMode;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * When a sales demo ends (`live-store-demo/04`).
 *
 * A live-store demo holds a prospect's real orders, so its tenant is torn
 * down when the demo is over. `demo.expires_at` says when that is. Past it,
 * nothing imports and nobody can sign in, so a teardown that runs late leaves
 * no window for new orders to arrive. Those guards are not the deletion; the
 * teardown is.
 *
 * Only Demo has an end. A stray `DEMO_EXPIRES_AT` on any other install is
 * ignored rather than allowed to lock it out.
 */
class DemoLifetime
{
    /**
     * When the demo ends, or null when it has no limit.
     */
    public function endsAt(): ?CarbonImmutable
    {
        if (AppMode::current() !== AppMode::Demo) {
            return null;
        }

        $configured = config('demo.expires_at');

        if (blank($configured)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $configured, 'UTC');
        } catch (Throwable) {
            // Unreadable counts as ended: a typo must not leave a prospect's
            // orders importing with no end.
            return CarbonImmutable::createFromTimestamp(0);
        }
    }

    public function hasEnded(): bool
    {
        $endsAt = $this->endsAt();

        return $endsAt !== null && ! $endsAt->isFuture();
    }
}
