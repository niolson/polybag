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
