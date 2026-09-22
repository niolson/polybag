<?php

namespace App\Services\PostageSources;

use App\DataTransferObjects\PostageSources\OffAmazonShippingCheckResult;
use App\DataTransferObjects\Shipping\AddressData;
use App\Enums\OffAmazonShippingStatus;
use App\Enums\SourceEnvironment;
use App\Http\Integrations\Amazon\Requests\GetShippingRates;
use App\Models\DataSource;
use App\Models\Location;
use App\Services\AmazonBuyShippingService;
use Illuminate\Database\Eloquent\Collection;
use Saloon\Http\Response;
use Throwable;

/**
 * Asks Amazon whether a connection's account can sell Amazon Shipping for
 * orders from other channels, and records the answer on the connection.
 *
 * No API reports whether a seller has signed up for Amazon Shipping (`01`), so
 * this sends a production `EXTERNAL` `getRates`, which is free and buys
 * nothing. `200` means enabled and `403 A-101` means not set up. Anything else
 * leaves the answer unknown. The body has to be valid, because Amazon validates
 * it before it checks access and a `400` would hide the answer.
 *
 * A-101 is an account-level answer, so one check per connection is enough. A
 * site Amazon will not serve shows up when quoting from it, which is not
 * enablement.
 */
class OffAmazonShippingCheck
{
    /**
     * A fixed, known-valid US destination. Amazon needs a real address to
     * validate; nothing is shipped to it.
     */
    public const SHIP_TO = [
        'company' => 'Amazon Shipping check',
        'streetAddress' => '410 Terry Ave N',
        'city' => 'Seattle',
        'stateOrProvince' => 'WA',
        'postalCode' => '98109',
        'country' => 'US',
    ];

    /** The check runs while the operator waits for the form to save. */
    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const REQUEST_TIMEOUT_SECONDS = 10;

    /** The code Amazon puts in the 403 for an account that is not set up. */
    private const NOT_SET_UP_CODE = 'A-101';

    public function __construct(
        private readonly AmazonBuyShippingService $amazon,
    ) {}

    public function check(DataSource $source): OffAmazonShippingCheckResult
    {
        $result = $this->ask($source);

        $source->forceFill([
            'off_amazon_shipping_status' => $result->status,
            'off_amazon_shipping_checked_at' => now(),
        ])->save();

        return $result;
    }

    private function ask(DataSource $source): OffAmazonShippingCheckResult
    {
        // The sandbox quotes every account, and `sandbox_mode` is one setting
        // shared by every carrier, so the check cannot reach production from here.
        if (SourceEnvironment::current() === SourceEnvironment::Sandbox) {
            return $this->unknown('Not checked in sandbox mode, where Amazon quotes every account. Turn sandbox mode off and use Check again.');
        }

        $location = $this->shipFromLocation($source);

        if (! $location) {
            return $this->unknown('Not checked: no location has a complete address to quote from. Add one, then use Check again.');
        }

        $request = new GetShippingRates(
            $this->amazon->buildExternalRatePayload(
                AddressData::fromLocation($location),
                self::shipTo(),
                self::package(),
            ),
            AmazonBuyShippingService::BUSINESS_ID,
        );
        $request->tries = 1;
        $request->config()->merge([
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::REQUEST_TIMEOUT_SECONDS,
        ]);

        try {
            $response = $this->amazon->connectorFor($source)->send($request);
        } catch (Throwable $e) {
            logger()->warning('Could not check an Amazon connection for off-Amazon shipping', [
                'data_source_id' => $source->id,
                'error' => $e->getMessage(),
            ]);

            return $this->unknown('Could not reach Amazon to check. Use Check again later.');
        }

        if ($response->successful()) {
            return new OffAmazonShippingCheckResult(
                OffAmazonShippingStatus::Enabled,
                'Amazon Shipping is enabled on this account for orders from other channels.',
            );
        }

        if ($this->isNotSetUp($response)) {
            return new OffAmazonShippingCheckResult(
                OffAmazonShippingStatus::NotSetUp,
                'Amazon refused this account for orders from other channels ('.self::NOT_SET_UP_CODE.'). The seller has to finish Amazon Shipping sign-up in Seller Central first.',
            );
        }

        logger()->warning('Amazon gave no answer to an off-Amazon shipping check', [
            'data_source_id' => $source->id,
            'status' => $response->status(),
            'errors' => $response->json('errors'),
        ]);

        return $this->unknown("Amazon did not say whether this account can ship orders from other channels (HTTP {$response->status()}). Use Check again later.");
    }

    /**
     * A location the connection serves: one its scopes name, or else any
     * active location, default first. It must have a complete address, since
     * Amazon validates `shipFrom` before it checks access.
     */
    private function shipFromLocation(DataSource $source): ?Location
    {
        $scoped = Location::query()
            ->whereIn('id', $source->offAmazonShippingScopes()->whereNotNull('location_id')->select('location_id'))
            ->orderBy('id')
            ->get();

        /** @var Collection<int, Location> $candidates */
        $candidates = $scoped->concat(
            Location::active()->orderByDesc('is_default')->orderBy('id')->get()
        );

        return $candidates->first(fn (Location $location): bool => filled($location->address1)
            && filled($location->city)
            && filled($location->postal_code)
            && filled($location->country));
    }

    private function isNotSetUp(Response $response): bool
    {
        if ($response->status() !== 403) {
            return false;
        }

        return collect($response->json('errors', []))
            ->contains(fn (mixed $error): bool => is_array($error)
                && str_contains(($error['details'] ?? '').' '.($error['message'] ?? ''), self::NOT_SET_UP_CODE));
    }

    private function unknown(string $message): OffAmazonShippingCheckResult
    {
        return new OffAmazonShippingCheckResult(OffAmazonShippingStatus::Unknown, $message);
    }

    public static function shipTo(): AddressData
    {
        return new AddressData(
            firstName: '',
            lastName: '',
            streetAddress: self::SHIP_TO['streetAddress'],
            city: self::SHIP_TO['city'],
            stateOrProvince: self::SHIP_TO['stateOrProvince'],
            postalCode: self::SHIP_TO['postalCode'],
            country: self::SHIP_TO['country'],
            company: self::SHIP_TO['company'],
        );
    }

    /**
     * One small parcel with one item lighter than it, the shape `01` found
     * Amazon accepts for `EXTERNAL`.
     *
     * @return array<string, mixed>
     */
    public static function package(): array
    {
        return [
            'dimensions' => ['length' => 10, 'width' => 8, 'height' => 4, 'unit' => 'INCH'],
            'weight' => ['unit' => 'POUND', 'value' => 1],
            'insuredValue' => ['unit' => 'USD', 'value' => 0],
            'packageClientReferenceId' => 'off-amazon-shipping-check',
            'items' => [[
                'description' => 'Amazon Shipping check',
                'quantity' => 1,
                'weight' => ['unit' => 'POUND', 'value' => 0.5],
            ]],
        ];
    }
}
