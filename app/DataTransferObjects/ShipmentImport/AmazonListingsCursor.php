<?php

namespace App\DataTransferObjects\ShipmentImport;

use App\Exceptions\CatalogReadMustStartOver;
use App\Services\ProductCatalog\AmazonListingCatalog;

/**
 * Where an Amazon listings read stands (`product-catalog-sync/04`), as the
 * cursor string a catalog sync run carries between its short jobs.
 *
 * Listings are read in creation-date windows, oldest first
 * ({@see AmazonListingCatalog}). A window that is split becomes two that
 * share their boundary, `[a, m]` and `[m, b]`, so the windows still to read
 * always tile the rest of the account's history and are stored as their
 * boundaries alone: a few dozen at most, however many times windows split.
 *
 * Amazon's date filters include both ends, so a listing created exactly on a
 * boundary is returned by both windows. The SKUs created in the latest second
 * a window returned (its tail) are carried into the next window, which skips
 * them, so a listing is synced and counted once. That is the only memory of
 * what was read: at most one second's listings.
 */
final readonly class AmazonListingsCursor
{
    /**
     * @param  list<int>  $bounds  window boundaries in Unix seconds, ascending: the window in hand is `[bounds[0], bounds[1]]`, and each later pair is a window still to read
     * @param  ?string  $pageToken  the next page of the window in hand, or null for its first page
     * @param  int  $fetched  listings fetched from the window in hand so far
     * @param  list<string>  $skip  SKUs already synced from the window's first second, which the previous window also returned
     * @param  ?int  $tailSecond  the latest creation second the window in hand has returned
     * @param  list<string>  $tailSkus  the SKUs created in that second
     * @param  bool  $restarted  whether the window in hand was read again from its start after its page token expired
     */
    public function __construct(
        public array $bounds,
        public ?string $pageToken = null,
        public int $fetched = 0,
        public array $skip = [],
        public ?int $tailSecond = null,
        public array $tailSkus = [],
        public bool $restarted = false,
    ) {}

    /** One window over the whole of `[$from, $to]`. */
    public static function whole(int $from, int $to): self
    {
        return new self([$from, $to]);
    }

    /**
     * @throws CatalogReadMustStartOver when the string is not a cursor this class wrote
     */
    public static function fromString(string $cursor): self
    {
        $data = json_decode($cursor, true);
        $bounds = is_array($data) ? ($data['b'] ?? null) : null;

        if (! is_array($bounds) || count($bounds) < 2 || ! array_is_list($bounds) || array_filter($bounds, is_int(...)) !== $bounds) {
            throw new CatalogReadMustStartOver('The saved position in the Amazon listings could not be read. The next sync starts from the beginning.');
        }

        return new self(
            bounds: $bounds,
            pageToken: is_string($data['t'] ?? null) ? $data['t'] : null,
            fetched: (int) ($data['n'] ?? 0),
            skip: self::skus($data['s'] ?? []),
            tailSecond: is_int($data['ls'] ?? null) ? $data['ls'] : null,
            tailSkus: self::skus($data['lk'] ?? []),
            restarted: ($data['r'] ?? false) === true,
        );
    }

    public function toString(): string
    {
        return (string) json_encode(array_filter([
            'b' => $this->bounds,
            't' => $this->pageToken,
            'n' => $this->fetched,
            's' => $this->skip,
            'ls' => $this->tailSecond,
            'lk' => $this->tailSkus,
            'r' => $this->restarted,
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== 0 && $value !== false), JSON_UNESCAPED_SLASHES);
    }

    /** The start of the window in hand. */
    public function from(): int
    {
        return $this->bounds[0];
    }

    /** The end of the window in hand. */
    public function to(): int
    {
        return $this->bounds[1];
    }

    /** Whether the window in hand spans more than one second, so it can be halved. */
    public function isSplittable(): bool
    {
        return $this->to() - $this->from() > 1;
    }

    /**
     * The window in hand in two halves that share their middle second, the
     * first half next. Nothing from the window was synced.
     */
    public function split(): self
    {
        $middle = intdiv($this->from() + $this->to(), 2);

        return new self([$this->from(), $middle, ...array_slice($this->bounds, 1)], skip: $this->skip);
    }

    /**
     * @param  list<string>  $tailSkus
     */
    public function nextPage(string $pageToken, int $fetched, ?int $tailSecond, array $tailSkus): self
    {
        return new self($this->bounds, $pageToken, $fetched, $this->skip, $tailSecond, $tailSkus, $this->restarted);
    }

    /**
     * The window in hand from its first page again, keeping what it skips
     * from the previous window: for a page token Amazon calls expired.
     */
    public function restartWindow(): self
    {
        return new self($this->bounds, skip: $this->skip, restarted: true);
    }

    /**
     * The next window, skipping the SKUs this one returned from the second
     * they share; null when no window is left.
     *
     * @param  list<string>  $tailSkus
     */
    public function nextWindow(?int $tailSecond, array $tailSkus): ?self
    {
        $bounds = array_slice($this->bounds, 1);

        if (count($bounds) < 2) {
            return null;
        }

        return new self($bounds, skip: $tailSecond === $this->to() ? $tailSkus : []);
    }

    /**
     * The window in hand again, from `$second` on, skipping `$skip`: for a
     * window read up to the cap without knowing its size.
     *
     * @param  list<string>  $skip
     */
    public function startWindowAt(int $second, array $skip): self
    {
        return new self([$second, ...array_slice($this->bounds, 1)], skip: $skip);
    }

    /**
     * @return list<string>
     */
    private static function skus(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
