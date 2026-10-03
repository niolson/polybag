<?php

namespace App\Services;

use App\Enums\PostageSource;
use App\Http\Integrations\Shopify\Requests\GraphQL;
use App\Http\Integrations\Shopify\ShopifyConnector;
use App\Models\DataSource;
use App\Models\PackageLabel;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recovers what a Shopify Shipping label cost from the order timeline.
 *
 * The purchase mutation reports no price, and the public `ShippingLabel` node
 * carries none. The one place the Admin API exposes it is the order's
 * `shipping_label_created_success` event, as a sentence: *"PolyBag purchased a
 * shipping label for $5.97."* That event names the label by ID in
 * `arguments[0]`, so the match is identity rather than timing, and it is
 * written before the purchase returns (`shopify-shipping-carrier/05`).
 *
 * A wrong cost is worse than none, because client billing invoices it. So the
 * sentence is read in pinned English, a bare amount is taken as the shop's
 * currency only when it renders exactly as the shop's money format would, and
 * only USD is recorded — every cost column is USD by convention. Anything
 * else leaves the cost null, which every report already discloses.
 */
class ShopifyLabelCostRecorder
{
    private const LABEL_EVENTS_QUERY = <<<'GRAPHQL'
        query ShopifyLabelCostEvents($id: ID!) {
          shop {
            currencyCode
            currencyFormats { moneyFormat }
          }
          order(id: $id) {
            events(first: 250, query: "action:shipping_label_created_success") {
              nodes {
                action
                message
                ... on BasicEvent { arguments additionalContent }
              }
            }
          }
        }
        GRAPHQL;

    /**
     * The event prose is rendered in the request's language, not the shop's:
     * `fr` reads "2,24 £ GBP", `ja` rewrites the sentence. Pinned so the one
     * pattern below is the only one there is.
     */
    private const EVENT_LANGUAGE = 'en';

    private const SHIPPING_LABEL_GID_PREFIX = 'gid://shopify/ShippingLabel/';

    /**
     * Price every recent Shopify label that has none.
     *
     * @return array{checked: int, costed: int, failed: int, ship_dates: array<int, string>}
     */
    public function sync(?int $limit = null, ?int $days = null): array
    {
        $labels = $this->candidates($limit, $days);
        $costed = 0;
        $failed = 0;
        $shipDates = [];

        $byOrder = $labels->groupBy(fn (PackageLabel $label): string => $label->postage_data_source_id.'|'
            .($label->package?->shipment?->metadata['shopify_order_id'] ?? ''));

        foreach ($byOrder as $group) {
            /** @var PackageLabel $first */
            $first = $group->first();
            $orderId = $first->package?->shipment?->metadata['shopify_order_id'] ?? null;

            if (blank($orderId) || $first->postageDataSource === null) {
                continue;
            }

            try {
                $prices = $this->pricesForOrder($first->postageDataSource, (string) $orderId);
            } catch (\Exception $e) {
                $failed += $group->count();

                logger()->warning('Shopify label cost lookup failed', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($group as $label) {
                $cost = $this->costFor($label, $prices);

                if ($cost === null) {
                    continue;
                }

                $projected = $this->write($label, $cost);
                $costed++;

                if ($projected && $label->ship_date !== null) {
                    $shipDates[] = $label->ship_date->format('Y-m-d');
                }
            }
        }

        return [
            'checked' => $labels->count(),
            'costed' => $costed,
            'failed' => $failed,
            'ship_dates' => array_values(array_unique($shipDates)),
        ];
    }

    /**
     * Shopify-bought labels with no cost, recent enough to be worth asking about.
     *
     * Voided labels are included: a voided label's cost is the refund to expect.
     * The window is short because the event exists from the moment of purchase;
     * a label it has not priced in a week never will be, and asking hourly for
     * a GBP label nobody will record would otherwise go on indefinitely.
     *
     * @return Collection<int, PackageLabel>
     */
    public function candidates(?int $limit = null, ?int $days = null): Collection
    {
        $days ??= (int) config('services.shopify.label_cost_check_days', 7);

        return PackageLabel::query()
            ->where('postage_source', PostageSource::PostageDataSource)
            ->whereHas(
                'postageDataSource',
                // A disabled connection's credentials are not used, for this
                // as for anything else.
                fn (Builder $query): Builder => $query
                    ->where('source_type', ShopifySource::class)
                    ->where('active', true),
            )
            ->whereNull('cost')
            ->where('source_label_reference', 'like', self::SHIPPING_LABEL_GID_PREFIX.'%')
            ->where('purchased_at', '>=', now()->subDays($days))
            ->with(['package.shipment', 'postageDataSource'])
            ->orderBy('id')
            ->when($limit !== null, fn (Builder $query): Builder => $query->limit($limit))
            ->get();
    }

    /**
     * Parse the amount and currency out of one purchase event's sentence.
     *
     * A coded amount ("£2.24 GBP") names its own currency. A bare one ("$6.23")
     * is in the shop's, and is accepted only when the shop's money format
     * renders that amount to exactly the same string — the guard against a
     * glyph that means something other than it seems.
     *
     * @return array{amount: string, currency: string}|null
     */
    public function priceFrom(string $message, string $shopCurrency, string $shopMoneyFormat): ?array
    {
        if (! preg_match('/ purchased a shipping label for (?<money>.+)\.$/u', $message, $sentence)) {
            return null;
        }

        $money = $sentence['money'];
        $coded = preg_match('/^(?<formatted>.+) (?<code>[A-Z]{3})$/u', $money, $parts) === 1;
        $formatted = $coded ? $parts['formatted'] : $money;

        if (! preg_match('/\d{1,3}(?:,\d{3})*\.\d{2}/', $formatted, $number)) {
            return null;
        }

        // The number must be the whole of the figure, glyph aside.
        if (preg_match('/\d/', str_replace($number[0], '', $formatted))) {
            return null;
        }

        if (! $coded && str_replace('{{amount}}', $number[0], $shopMoneyFormat) !== $formatted) {
            return null;
        }

        return [
            'amount' => str_replace(',', '', $number[0]),
            'currency' => $coded ? $parts['code'] : $shopCurrency,
        ];
    }

    /**
     * Each purchase event on the order, keyed by the numeric ShippingLabel ID
     * it names.
     *
     * @return array<string, array<int, array{price: array{amount: string, currency: string}|null, tracking: string|null}>>
     */
    private function pricesForOrder(DataSource $dataSource, string $orderId): array
    {
        $connector = ShopifyConnector::fromSettings(
            array_merge($dataSource->settings ?? [], $dataSource->secret_settings ?? [])
        );

        $request = new GraphQL(self::LABEL_EVENTS_QUERY, ['id' => $orderId]);
        $request->headers()->add('Accept-Language', self::EVENT_LANGUAGE);

        $json = $connector->send($request)->json();

        if (! empty($json['errors'])) {
            throw new \RuntimeException('Shopify GraphQL error: '.json_encode($json['errors']));
        }

        $shopCurrency = (string) ($json['data']['shop']['currencyCode'] ?? '');
        $moneyFormat = (string) ($json['data']['shop']['currencyFormats']['moneyFormat'] ?? '');
        $events = $json['data']['order']['events']['nodes'] ?? [];
        $prices = [];

        foreach ($events as $event) {
            $labelId = $event['arguments'][0] ?? null;

            if (($event['action'] ?? null) !== 'shipping_label_created_success' || $labelId === null) {
                continue;
            }

            $prices[(string) $labelId][] = [
                'price' => $this->priceFrom((string) ($event['message'] ?? ''), $shopCurrency, $moneyFormat),
                'tracking' => $this->trackingNumberIn($event['additionalContent'] ?? null),
            ];
        }

        return $prices;
    }

    /**
     * The USD cost of one label, or null when the events do not say it exactly.
     *
     * @param  array<string, array<int, array{price: array{amount: string, currency: string}|null, tracking: string|null}>>  $prices
     */
    private function costFor(PackageLabel $label, array $prices): ?string
    {
        $labelId = substr((string) $label->source_label_reference, strlen(self::SHIPPING_LABEL_GID_PREFIX));
        $events = $prices[$labelId] ?? [];

        // One purchase per label. Two events for one ID is not a state that
        // has been seen, so it is not one to guess through.
        if (count($events) !== 1) {
            return null;
        }

        ['price' => $price, 'tracking' => $tracking] = $events[0];

        // A second, independent check on the identity: the event names the
        // tracking number too, and a disagreement means something is not what
        // it looks like.
        if ($tracking !== null && $label->tracking_number !== null && $tracking !== $label->tracking_number) {
            logger()->warning('Shopify label event names a different tracking number', [
                'package_label_id' => $label->id,
            ]);

            return null;
        }

        if ($price === null || $price['currency'] !== 'USD') {
            return null;
        }

        return $price['amount'];
    }

    /**
     * The tracking number from the event's key/value detail block.
     */
    private function trackingNumberIn(mixed $additionalContent): ?string
    {
        $content = is_string($additionalContent) ? json_decode($additionalContent, true) : $additionalContent;

        if (! is_array($content)) {
            return null;
        }

        $found = null;

        $walk = function (array $node) use (&$walk, &$found): void {
            if (($node['type'] ?? null) === 'key_value_pair'
                && collect($node['key'] ?? [])->pluck('content')->implode('') === 'Tracking number') {
                $found = collect($node['value'] ?? [])->pluck('content')->implode('');

                return;
            }

            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };

        $walk($content);

        return filled($found) ? $found : null;
    }

    /**
     * Record the cost on the label and, while it is the active one, on the
     * package that projects it. Returns whether the package changed.
     */
    private function write(PackageLabel $label, string $cost): bool
    {
        return DB::transaction(function () use ($label, $cost): bool {
            $now = now();

            // Package before label, the order clearShipping() and the print
            // acknowledgments take them in; the reverse can deadlock against a
            // void running at the same moment.
            DB::table('packages')->where('id', $label->package_id)->lockForUpdate()->first();

            $updated = DB::table('package_labels')
                ->where('id', $label->id)
                ->whereNull('cost')
                ->update(['cost' => $cost, 'updated_at' => $now]);

            if ($updated === 0) {
                return false;
            }

            // Re-checked here rather than trusted from the loaded model: a void
            // landing since the candidates were read must not project a dead
            // label's cost onto a package that is unshipped or re-shipped.
            return DB::table('packages')
                ->where('id', $label->package_id)
                ->whereNull('cost')
                ->whereExists(fn (QueryBuilder $query): QueryBuilder => $query
                    ->from('package_labels')
                    ->where('id', $label->id)
                    ->whereNull('voided_at'))
                ->update(['cost' => $cost, 'updated_at' => $now]) > 0;
        });
    }
}
