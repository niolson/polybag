<?php

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Http\Integrations\Fedex\Requests\CreateShipment as FedexCreateShipment;
use App\Http\Integrations\Ups\Requests\CreateShipment as UpsCreateShipment;
use App\Http\Integrations\Ups\Requests\LabelRecovery;
use App\Http\Integrations\USPS\Requests\Label;
use App\Http\Integrations\USPS\Requests\LabelReprint;
use App\Http\Integrations\USPS\Requests\PaymentAuthorization;
use App\Models\Package;
use App\Models\ShippingOffer;
use App\Services\Carriers\CarrierRegistry;
use App\Services\Carriers\FedexAdapter;
use App\Services\Carriers\UspsAdapter;
use App\Services\PostageSources\OfferStore;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;

/**
 * ADR-0002 decision 4's fifth property for the direct carriers —
 * `postage-source-split/18`. A purchase whose reply never arrives leaves the
 * offer unresolved; the next attempt asks the carrier by the handle the
 * purchase carried, and ships on the label it finds, buys afresh when the
 * carrier is certain nothing was bought, and stays blocked when nobody can say.
 */
beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
    createUspsAccount();
    createUpsAccount();
    createFedexAccount();

    $this->package = Package::factory()->create([
        'status' => PackageStatus::Unshipped,
        'weight' => 2.0,
        'length' => 10,
        'width' => 8,
        'height' => 4,
    ]);
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

function noAnswer(): Closure
{
    return fn (PendingRequest $pending): MockResponse => MockResponse::make()
        ->throw(new FatalRequestException(new RuntimeException('Connection timed out'), $pending));
}

function upsAuthFake(): array
{
    return ['*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600])];
}

function uspsAuthFakes(): array
{
    return [
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        PaymentAuthorization::class => MockResponse::make(['paymentAuthorizationToken' => 'test_payment_token']),
    ];
}

function uspsMultipart(string $trackingNumber): MockResponse
{
    return MockResponse::make(
        body: "--b\r\nContent-Type: application/json\r\n\r\n{\"trackingNumber\":\"{$trackingNumber}\",\"postage\":8.40}\r\n--b\r\nContent-Type: application/pdf\r\n\r\nJVBERi0xLjQ=\r\n--b\r\nContent-Type: application/json\r\n\r\n{\"reprintNumber\":1,\"reprintLimit\":3}\r\n--b--",
        headers: ['Content-Type' => 'multipart/form-data; boundary=b'],
    );
}

function uspsKeyNotFound(): MockResponse
{
    return MockResponse::make(['apiVersion' => '/labels/v3/', 'error' => ['code' => '400', 'message' => 'Bad Request', 'errors' => [
        ['title' => 'Bad Request', 'detail' => 'Idempotency-Key not found for a mailing date within the last 7 days', 'code' => '160412', 'source' => ['parameter' => 'Header: X-Idempotency-Key']],
    ]]], 400);
}

function uspsGroundAdvantage(Package $package): RateResponse
{
    return quotedDirectly($package, new RateResponse('USPS', 'USPS_GROUND_ADVANTAGE', 'USPS Ground Advantage', 8.40, metadata: [
        'mailClass' => 'USPS_GROUND_ADVANTAGE',
        'processingCategory' => 'MACHINABLE',
        'rateIndicator' => 'SP',
        'destinationEntryFacilityType' => 'NONE',
    ]));
}

function upsGround(Package $package): RateResponse
{
    return quotedDirectly($package, new RateResponse('UPS', '03', 'UPS Ground', 16.96, metadata: ['serviceCode' => '03']));
}

function upsShipped(string $trackingNumber): MockResponse
{
    return MockResponse::make(['ShipmentResponse' => ['ShipmentResults' => [
        'ShipmentIdentificationNumber' => $trackingNumber,
        'ShipmentCharges' => ['TotalCharges' => ['MonetaryValue' => '39.13']],
        'PackageResults' => ['TrackingNumber' => $trackingNumber, 'ShippingLabel' => ['GraphicImage' => 'R0lGODlhAQABAAAAACw=']],
    ]]]);
}

function upsRecovered(string $trackingNumber): MockResponse
{
    return MockResponse::make(['LabelRecoveryResponse' => [
        'Response' => ['ResponseStatus' => ['Code' => '1', 'Description' => 'Success']],
        'ShipmentIdentificationNumber' => $trackingNumber,
        'LabelResults' => [['TrackingNumber' => $trackingNumber, 'LabelImage' => ['LabelImageFormat' => ['Code' => 'gif'], 'GraphicImage' => 'R0lGODlhAQABAAAAACw=']]],
    ]]);
}

function upsNotFound(): MockResponse
{
    return MockResponse::make(['response' => ['errors' => [['code' => '9801031', 'message' => 'The shipment for the requested tracking number or the combination of reference number plus shipper number could not be found.']]]], 400);
}

/**
 * A purchase whose reply never arrives, leaving its offer spent and unresolved.
 */
function purchaseWithNoAnswer(Package $package, RateResponse $rate, array $fakes): ShippingOffer
{
    Saloon::fake($fakes);

    $result = app(PackageShippingWorkflow::class)->ship($package, new PackageShippingRequest(selectedRate: $rate));

    $offer = ShippingOffer::where('public_id', $rate->offerId)->firstOrFail();

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Carrier Timeout')
        ->and($offer->isAwaitingPurchaseConfirmation())->toBeTrue()
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped);

    return $offer;
}

// --- USPS --------------------------------------------------------------------

it('leaves a USPS purchase that got no answer unresolved, with the key to ask by', function (): void {
    $offer = purchaseWithNoAnswer($this->package, uspsGroundAdvantage($this->package), [...uspsAuthFakes(), Label::class => noAnswer()]);

    expect($offer->purchase_failed_at)->toBeNull()
        ->and($offer->purchase_context[UspsAdapter::PURCHASE_CONTEXT_KEY] ?? null)->toBeString();
});

it('ships a USPS package on the reprinted label rather than buying a second one', function (): void {
    $stalled = purchaseWithNoAnswer($this->package, uspsGroundAdvantage($this->package), [...uspsAuthFakes(), Label::class => noAnswer()]);
    $key = $stalled->purchase_context[UspsAdapter::PURCHASE_CONTEXT_KEY];

    Saloon::fake([...uspsAuthFakes(), LabelReprint::class => uspsMultipart('9200190414219000000011')]);
    $retry = uspsGroundAdvantage($this->package);

    $result = app(PackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(selectedRate: $retry));

    expect($result->success)->toBeTrue()
        ->and($this->package->fresh()->tracking_number)->toBe('9200190414219000000011')
        ->and($this->package->fresh()->status)->toBe(PackageStatus::Shipped)
        ->and($stalled->fresh()->purchase_reference)->toBe('9200190414219000000011')
        // The retry's own offer was never spent: the package shipped on the
        // earlier purchase.
        ->and(ShippingOffer::where('public_id', $retry->offerId)->value('consumed_at'))->toBeNull();

    Saloon::assertSent(fn ($request): bool => $request instanceof LabelReprint && $request->headers()->get('X-Idempotency-Key') === $key);
    Saloon::assertNotSent(Label::class);
});

it('buys a USPS label afresh once USPS is certain the earlier attempt bought nothing', function (): void {
    $stalled = purchaseWithNoAnswer($this->package, uspsGroundAdvantage($this->package), [...uspsAuthFakes(), Label::class => noAnswer()]);

    Saloon::fake([...uspsAuthFakes(), LabelReprint::class => uspsKeyNotFound(), Label::class => uspsMultipart('9400111899223456789012')]);
    $retry = uspsGroundAdvantage($this->package);

    $result = app(PackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(selectedRate: $retry));

    expect($result->success)->toBeTrue()
        ->and($this->package->fresh()->tracking_number)->toBe('9400111899223456789012')
        ->and($stalled->fresh()->purchase_failed_at)->not->toBeNull()
        ->and($stalled->fresh()->purchase_failure_reason)->toContain('nothing was bought')
        ->and(ShippingOffer::where('public_id', $retry->offerId)->value('purchase_reference'))->toBe('9400111899223456789012');

    Saloon::assertSent(LabelReprint::class);
    Saloon::assertSent(Label::class);
});

it('keeps a USPS package blocked when the reprint itself gets no answer', function (): void {
    $stalled = purchaseWithNoAnswer($this->package, uspsGroundAdvantage($this->package), [...uspsAuthFakes(), Label::class => noAnswer()]);

    Saloon::fake([...uspsAuthFakes(), LabelReprint::class => noAnswer()]);

    $result = app(PackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(selectedRate: uspsGroundAdvantage($this->package)));

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Earlier Purchase Unresolved')
        ->and($stalled->fresh()->isAwaitingPurchaseConfirmation())->toBeTrue()
        // Asked and unanswered: the real unknown the purge command reports.
        ->and($stalled->fresh()->recovery_unanswered_at)->not->toBeNull()
        ->and($this->package->fresh()->status)->toBe(PackageStatus::Unshipped);

    Saloon::assertNotSent(Label::class);
});

it('treats a 5xx on a direct purchase as no answer, not a decline', function (string $carrier): void {
    // The carrier may have created the label before the server error, so
    // the offer stays unresolved and the next attempt asks.
    [$rate, $fakes] = $carrier === 'USPS'
        ? [uspsGroundAdvantage($this->package), [...uspsAuthFakes(), Label::class => MockResponse::make('<html>503</html>', 503, ['Content-Type' => 'text/html'])]]
        : [upsGround($this->package), [...upsAuthFake(), UpsCreateShipment::class => MockResponse::make(['response' => ['errors' => [['code' => '10429', 'message' => 'Service Unavailable']]]], 503)]];

    $offer = purchaseWithNoAnswer($this->package, $rate, $fakes);

    expect($offer->purchase_failed_at)->toBeNull();
})->with(['USPS', 'UPS']);

// --- UPS ---------------------------------------------------------------------

it('ships a UPS package on the recovered label rather than buying a second one', function (): void {
    $stalled = purchaseWithNoAnswer($this->package, upsGround($this->package), [...upsAuthFake(), UpsCreateShipment::class => noAnswer()]);

    Saloon::fake([...upsAuthFake(), LabelRecovery::class => upsRecovered('1Z14A6G90303889622')]);
    $retry = upsGround($this->package);

    $result = app(PackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(selectedRate: $retry));

    expect($result->success)->toBeTrue()
        ->and($this->package->fresh()->tracking_number)->toBe('1Z14A6G90303889622')
        ->and($stalled->fresh()->purchase_reference)->toBe('1Z14A6G90303889622')
        ->and(ShippingOffer::where('public_id', $retry->offerId)->value('consumed_at'))->toBeNull();

    Saloon::assertSent(fn ($request): bool => $request instanceof LabelRecovery
        && $request->body()->all()['LabelRecoveryRequest']['ReferenceValues']['ReferenceNumber']['Value'] === $stalled->public_id);
    Saloon::assertNotSent(UpsCreateShipment::class);
});

it('buys a UPS label afresh once UPS is certain the earlier attempt created nothing', function (): void {
    $stalled = purchaseWithNoAnswer($this->package, upsGround($this->package), [...upsAuthFake(), UpsCreateShipment::class => noAnswer()]);

    Saloon::fake([...upsAuthFake(), LabelRecovery::class => upsNotFound(), UpsCreateShipment::class => upsShipped('1Z9999999999999999')]);

    $result = app(PackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(selectedRate: upsGround($this->package)));

    expect($result->success)->toBeTrue()
        ->and($this->package->fresh()->tracking_number)->toBe('1Z9999999999999999')
        ->and($stalled->fresh()->purchase_failed_at)->not->toBeNull()
        ->and($stalled->fresh()->purchase_failure_reason)->toContain('nothing was bought');
});

it('keeps a UPS package blocked when Label Recovery itself gets no answer', function (): void {
    $stalled = purchaseWithNoAnswer($this->package, upsGround($this->package), [...upsAuthFake(), UpsCreateShipment::class => noAnswer()]);

    Saloon::fake([...upsAuthFake(), LabelRecovery::class => noAnswer()]);

    $result = app(PackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(selectedRate: upsGround($this->package)));

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Earlier Purchase Unresolved')
        ->and($stalled->fresh()->recovery_unanswered_at)->not->toBeNull();

    Saloon::assertNotSent(UpsCreateShipment::class);
});

// --- FedEx -------------------------------------------------------------------

it('settles a FedEx purchase that got no answer as a decline and says to try again', function (): void {
    // FedEx never bills a label that was created and not tendered, and has
    // no lookup to ask; the offer resolves and the packer retries.
    Saloon::fake([...upsAuthFake(), FedexCreateShipment::class => noAnswer()]);
    $rate = quotedDirectly($this->package, new RateResponse('FedEx', 'FEDEX_GROUND', 'FedEx Ground', 12.75, metadata: ['serviceType' => 'FEDEX_GROUND']));

    $result = app(PackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(selectedRate: $rate));

    $offer = ShippingOffer::where('public_id', $rate->offerId)->firstOrFail();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe(FedexAdapter::NO_ANSWER_MESSAGE)
        ->and($offer->isAwaitingPurchaseConfirmation())->toBeFalse()
        ->and($offer->purchase_failed_at)->not->toBeNull()
        ->and($offer->postage_source)->toBe(PostageSource::CarrierAccount);
});

// --- An unreadable 2xx (`project-review/11`) ---------------------------------

/**
 * A 2xx from the label endpoint means the label was created and charged, so a
 * reply the adapter cannot read is an unknown outcome, never a decline: the
 * offer stays unresolved and the next attempt recovers the same label.
 */
function uspsUnreadable(string $case): MockResponse
{
    return match ($case) {
        'missing tracking number' => MockResponse::make(
            body: "--b\r\nContent-Type: application/json\r\n\r\n{\"postage\":8.40}\r\n--b\r\nContent-Type: application/pdf\r\n\r\nJVBERi0xLjQ=\r\n--b--",
            headers: ['Content-Type' => 'multipart/form-data; boundary=b'],
        ),
        'missing label part' => MockResponse::make(
            body: "--b\r\nContent-Type: application/json\r\n\r\n{\"trackingNumber\":\"9200190414219000000011\",\"postage\":8.40}\r\n--b\r\nContent-Type: application/pdf\r\n\r\n\r\n--b--",
            headers: ['Content-Type' => 'multipart/form-data; boundary=b'],
        ),
        'malformed multipart' => MockResponse::make(
            body: 'not a multipart body',
            headers: ['Content-Type' => 'multipart/form-data'],
        ),
        default => throw new InvalidArgumentException($case),
    };
}

function upsUnreadable(string $case): MockResponse
{
    return match ($case) {
        'missing shipment results' => MockResponse::make(['ShipmentResponse' => ['Response' => ['ResponseStatus' => ['Code' => '1']]]]),
        'missing tracking number' => MockResponse::make(['ShipmentResponse' => ['ShipmentResults' => [
            'PackageResults' => ['ShippingLabel' => ['GraphicImage' => 'R0lGODlhAQABAAAAACw=']],
        ]]]),
        'missing label image' => MockResponse::make(['ShipmentResponse' => ['ShipmentResults' => [
            'ShipmentIdentificationNumber' => '1ZREVIEW',
            'PackageResults' => [['TrackingNumber' => '1ZREVIEW', 'ShippingLabel' => []]],
        ]]]),
        'body that is not JSON' => MockResponse::make('<html>ok</html>', 200, ['Content-Type' => 'text/html']),
        default => throw new InvalidArgumentException($case),
    };
}

it('does not treat a USPS 200 it cannot read as a decline', function (): void {
    $calls = 0;
    Saloon::fake([
        ...uspsAuthFakes(),
        Label::class => function () use (&$calls): MockResponse {
            $calls++;

            return uspsUnreadable('missing tracking number');
        },
        LabelReprint::class => noAnswer(),
    ]);

    $workflow = app(PackageShippingWorkflow::class);
    $workflow->ship($this->package, new PackageShippingRequest(selectedRate: uspsGroundAdvantage($this->package)));
    $workflow->ship($this->package->fresh(), new PackageShippingRequest(selectedRate: uspsGroundAdvantage($this->package)));

    expect($calls)->toBe(1);
});

it('does not treat a UPS 2xx it cannot read as a decline', function (): void {
    $calls = 0;
    Saloon::fake([
        ...upsAuthFake(),
        UpsCreateShipment::class => function () use (&$calls): MockResponse {
            $calls++;

            return upsUnreadable('missing label image');
        },
        LabelRecovery::class => noAnswer(),
    ]);

    $workflow = app(PackageShippingWorkflow::class);
    $workflow->ship($this->package, new PackageShippingRequest(selectedRate: upsGround($this->package)));
    $workflow->ship($this->package->fresh(), new PackageShippingRequest(selectedRate: upsGround($this->package)));

    expect($calls)->toBe(1);
});

it('leaves a USPS purchase unresolved when its 2xx cannot be read', function (string $case): void {
    Saloon::fake([...uspsAuthFakes(), Label::class => uspsUnreadable($case)]);
    $rate = uspsGroundAdvantage($this->package);

    $result = app(PackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(selectedRate: $rate));
    $offer = ShippingOffer::where('public_id', $rate->offerId)->firstOrFail();

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Carrier Reply Unreadable')
        ->and($result->message)->toContain('accepted the purchase but its reply could not be read')
        ->and($offer->isAwaitingPurchaseConfirmation())->toBeTrue()
        ->and($offer->purchase_failed_at)->toBeNull()
        ->and($this->package->fresh()->status)->toBe(PackageStatus::Unshipped);
})->with(['missing tracking number', 'missing label part', 'malformed multipart']);

it('leaves a UPS purchase unresolved when its 2xx cannot be read', function (string $case): void {
    Saloon::fake([...upsAuthFake(), UpsCreateShipment::class => upsUnreadable($case)]);
    $rate = upsGround($this->package);

    $result = app(PackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(selectedRate: $rate));
    $offer = ShippingOffer::where('public_id', $rate->offerId)->firstOrFail();

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Carrier Reply Unreadable')
        ->and($offer->isAwaitingPurchaseConfirmation())->toBeTrue()
        ->and($offer->purchase_failed_at)->toBeNull()
        ->and($this->package->fresh()->status)->toBe(PackageStatus::Unshipped);
})->with(['missing shipment results', 'missing tracking number', 'missing label image', 'body that is not JSON']);

it('recovers the USPS label after an unreadable 2xx instead of buying another', function (): void {
    Saloon::fake([...uspsAuthFakes(), Label::class => uspsUnreadable('missing tracking number')]);
    $first = uspsGroundAdvantage($this->package);
    app(PackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(selectedRate: $first));
    $stalled = ShippingOffer::where('public_id', $first->offerId)->firstOrFail();
    $key = $stalled->purchase_context[UspsAdapter::PURCHASE_CONTEXT_KEY];

    // The first attempt's request stays in the fake's history, so a second
    // purchase is counted rather than asserted absent.
    $purchases = 0;
    Saloon::fake([
        ...uspsAuthFakes(),
        LabelReprint::class => uspsMultipart('9200190414219000000011'),
        Label::class => function () use (&$purchases): MockResponse {
            $purchases++;

            return uspsMultipart('9400111899223456789012');
        },
    ]);
    $result = app(PackageShippingWorkflow::class)->ship($this->package->fresh(), new PackageShippingRequest(selectedRate: uspsGroundAdvantage($this->package)));

    expect($result->success)->toBeTrue()
        ->and($purchases)->toBe(0)
        ->and($this->package->fresh()->tracking_number)->toBe('9200190414219000000011')
        ->and($stalled->fresh()->purchase_reference)->toBe('9200190414219000000011');

    Saloon::assertSent(fn ($request): bool => $request instanceof LabelReprint && $request->headers()->get('X-Idempotency-Key') === $key);
});

it('recovers the UPS label after an unreadable 2xx instead of buying another', function (): void {
    Saloon::fake([...upsAuthFake(), UpsCreateShipment::class => upsUnreadable('missing label image')]);
    $first = upsGround($this->package);
    app(PackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(selectedRate: $first));
    $stalled = ShippingOffer::where('public_id', $first->offerId)->firstOrFail();

    // The tracking number UPS did report is kept on the offer, without
    // resolving it, so a person can find the label if recovery cannot.
    expect($stalled->purchase_context[OfferStore::REPORTED_TRACKING_NUMBER] ?? null)->toBe('1ZREVIEW')
        ->and($stalled->isAwaitingPurchaseConfirmation())->toBeTrue();

    $purchases = 0;
    Saloon::fake([
        ...upsAuthFake(),
        LabelRecovery::class => upsRecovered('1ZREVIEW'),
        UpsCreateShipment::class => function () use (&$purchases): MockResponse {
            $purchases++;

            return upsShipped('1Z9999999999999999');
        },
    ]);
    $result = app(PackageShippingWorkflow::class)->ship($this->package->fresh(), new PackageShippingRequest(selectedRate: upsGround($this->package)));

    expect($result->success)->toBeTrue()
        ->and($purchases)->toBe(0)
        ->and($this->package->fresh()->tracking_number)->toBe('1ZREVIEW')
        ->and($stalled->fresh()->purchase_reference)->toBe('1ZREVIEW');

    Saloon::assertSent(fn ($request): bool => $request instanceof LabelRecovery
        && $request->body()->all()['LabelRecoveryRequest']['ReferenceValues']['ReferenceNumber']['Value'] === $stalled->public_id);
});

it('names the tracking number UPS reported when recovery cannot find the label either', function (): void {
    Saloon::fake([...upsAuthFake(), UpsCreateShipment::class => upsUnreadable('missing label image'), LabelRecovery::class => noAnswer()]);
    $workflow = app(PackageShippingWorkflow::class);

    $first = $workflow->ship($this->package, new PackageShippingRequest(selectedRate: upsGround($this->package)));
    $second = $workflow->ship($this->package->fresh(), new PackageShippingRequest(selectedRate: upsGround($this->package)));

    expect($first->message)->toContain('1ZREVIEW')
        ->and($second->title)->toBe('Earlier Purchase Unresolved')
        ->and($second->message)->toContain('1ZREVIEW');
});

it('still settles a genuine decline from USPS or UPS', function (string $carrier): void {
    [$rate, $fakes] = $carrier === 'USPS'
        ? [uspsGroundAdvantage($this->package), [...uspsAuthFakes(), Label::class => MockResponse::make(['error' => ['code' => '400', 'message' => 'Bad Request', 'errors' => [['code' => '020001', 'detail' => 'Invalid ZIP']]]], 400)]]
        : [upsGround($this->package), [...upsAuthFake(), UpsCreateShipment::class => MockResponse::make(['response' => ['errors' => [['code' => '120100', 'message' => 'Missing or invalid shipper number']]]], 400)]];

    $result = app(PackageShippingWorkflow::class)->ship($this->package, new PackageShippingRequest(selectedRate: $rate));
    $offer = ShippingOffer::where('public_id', $rate->offerId)->firstOrFail();

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Shipping Error')
        ->and($offer->purchase_failed_at)->not->toBeNull()
        ->and($offer->isAwaitingPurchaseConfirmation())->toBeFalse();
})->with(['USPS', 'UPS']);
