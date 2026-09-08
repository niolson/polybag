<?php

use App\Enums\ServiceEvidence;
use App\Models\Carrier;
use App\Models\CarrierAlias;
use App\Models\Package;
use App\Services\ServiceInference\ImpbTrackingNumber;
use App\Services\ServiceInference\LabelTextExtractor;
use App\Services\ServiceInference\ServiceInferrer;

/**
 * Tracking numbers here are synthetic: a real application identifier and service
 * type code over an all-nines Mailer ID, with a genuine mod-10 check digit so the
 * validation rung actually runs. STC 001 is USPS Ground Advantage and 055 is
 * Priority Mail in the June 2026 appendix.
 */
const IMPB_GROUND_ADVANTAGE = '9300199999999900000011';
const IMPB_PRIORITY_MAIL = '9305599999999900000021';
const IMPB_UNLISTED_STC = '9299999999999900000036';

/**
 * The 26-digit form, same construction. `IMPB_26_AMBIGUOUS` is built so that its
 * own trailing 22 digits also carry a valid check digit — which is what makes a
 * 420-prefixed 34-digit string readable two ways.
 */
const IMPB_26_GROUND_ADVANTAGE = '92001999999999000000000012';
const IMPB_26_AMBIGUOUS = '92011999999999000000000011';

function inferrer(): ServiceInferrer
{
    return app(ServiceInferrer::class);
}

function labelFixture(string $name): string
{
    return base64_encode((string) file_get_contents(__DIR__."/../../Fixtures/Labels/{$name}"));
}

/**
 * A minimal ZPL label printing one text field, for varying the service token.
 */
function zplPrinting(string $field): string
{
    return base64_encode("^XA^LH0,30\n^FO400,10^A0R,42,42^FD{$field}^FS\n^PQ1^XZ");
}

function packageFor(array $attributes = []): Package
{
    return new Package(array_merge([
        'carrier' => 'USPS',
        'service_evidence' => ServiceEvidence::Unknown,
    ], $attributes));
}

describe('IMpb validation', function (): void {
    it('parses a 22-digit number and exposes its service type code', function (): void {
        $impb = ImpbTrackingNumber::tryParse(IMPB_GROUND_ADVANTAGE);

        expect($impb)->not->toBeNull()
            ->and($impb->serviceTypeCode)->toBe('001');
    });

    it('strips the GS1 420 routing prefix a label prints under the barcode', function (): void {
        $impb = ImpbTrackingNumber::tryParse('42030024'.IMPB_GROUND_ADVANTAGE);

        expect($impb)->not->toBeNull()
            ->and($impb->digits)->toBe(IMPB_GROUND_ADVANTAGE);
    });

    it('tolerates the spacing a human transcription carries', function (): void {
        expect(ImpbTrackingNumber::tryParse('9300 1999 9999 9900 0000 11'))->not->toBeNull();
    });

    it('rejects a number whose check digit does not verify', function (): void {
        // Last digit walked one past the real one.
        expect(ImpbTrackingNumber::tryParse('9300199999999900000012'))->toBeNull();
    });

    it('rejects a length USPS does not issue', function (?string $candidate): void {
        expect(ImpbTrackingNumber::tryParse($candidate))->toBeNull();
    })->with([
        '1Z999AA10123456784',
        '930019999999990000001',
        '',
        null,
    ]);

    it('parses the 26-digit form and reads its service type code', function (): void {
        // Observed on the first Shopify Shipping label: USPS issues 26-digit
        // numbers, and reading only 22 left the service null on a package whose
        // service was sitting in its tracking number.
        $impb = ImpbTrackingNumber::tryParse(IMPB_26_GROUND_ADVANTAGE);

        expect($impb)->not->toBeNull()
            ->and($impb->serviceTypeCode)->toBe('001');
    });

    it('rejects a 26-digit number whose check digit does not verify', function (): void {
        expect(ImpbTrackingNumber::tryParse(substr(IMPB_26_GROUND_ADVANTAGE, 0, -1).'3'))->toBeNull();
    });

    it('strips the routing prefix from a 34-digit string with one valid reading', function (): void {
        $impb = ImpbTrackingNumber::tryParse('42030024'.IMPB_26_GROUND_ADVANTAGE);

        expect($impb)->not->toBeNull()
            ->and($impb->digits)->toBe(IMPB_26_GROUND_ADVANTAGE);
    });

    it('declines a 34-digit string that reads as two different barcodes', function (): void {
        // A 5-digit ZIP over 26 digits, and a ZIP+4 over 22 — both check out,
        // and they put different digits in the service position.
        $ambiguous = '42030024'.IMPB_26_AMBIGUOUS;

        expect(ImpbTrackingNumber::tryParse(substr($ambiguous, 12)))->not->toBeNull()
            ->and(ImpbTrackingNumber::tryParse(substr($ambiguous, 8)))->not->toBeNull()
            ->and(ImpbTrackingNumber::tryParse($ambiguous))->toBeNull();
    });
});

describe('rung 1 — tracking number', function (): void {
    it('infers the service from an unambiguous service type code', function (): void {
        $inference = inferrer()->infer(packageFor(['tracking_number' => IMPB_GROUND_ADVANTAGE]));

        expect($inference->isResolved())->toBeTrue()
            ->and($inference->service)->toBe('USPS Ground Advantage')
            ->and($inference->method)->toBe(ServiceInferrer::METHOD_USPS_STC)
            ->and($inference->rulesetVersion)->not->toBeEmpty();
    });

    it('infers the service from a 26-digit number', function (): void {
        $inference = inferrer()->infer(packageFor(['tracking_number' => IMPB_26_GROUND_ADVANTAGE]));

        expect($inference->isResolved())->toBeTrue()
            ->and($inference->service)->toBe('USPS Ground Advantage')
            ->and($inference->method)->toBe(ServiceInferrer::METHOD_USPS_STC);
    });

    it('distinguishes service type codes within the same carrier', function (): void {
        expect(inferrer()->infer(packageFor(['tracking_number' => IMPB_PRIORITY_MAIL]))->service)
            ->toBe('Priority Mail');
    });

    it('falls through on a service type code that names no product', function (): void {
        $inference = inferrer()->infer(packageFor(['tracking_number' => IMPB_UNLISTED_STC]));

        expect($inference->isResolved())->toBeFalse()
            ->and($inference->service)->toBeNull();
    });

    it('infers nothing from a tracking number that fails validation', function (): void {
        $inference = inferrer()->infer(packageFor(['tracking_number' => '9300199999999900000012']));

        expect($inference->isResolved())->toBeFalse();
    });

    // The consolidator guard. A FedEx Ground Economy, UPS Ground Saver or DHL
    // eCommerce parcel carries a genuine IMpb whose service type code names the
    // USPS product carrying the last mile, not the service that was bought.
    // Decoding it would pass every validation and still be wrong.
    it('refuses to decode an IMpb carried by a carrier that is not USPS', function (string $carrier): void {
        $inference = inferrer()->infer(packageFor([
            'carrier' => $carrier,
            'tracking_number' => IMPB_GROUND_ADVANTAGE,
        ]));

        expect($inference->isResolved())->toBeFalse()
            ->and($inference->reason)->toContain('last-mile');
    })->with(['FedEx', 'UPS', 'DHL eCommerce']);

    it('infers nothing without a carrier of record', function (): void {
        expect(inferrer()->infer(packageFor([
            'carrier' => null,
            'tracking_number' => IMPB_GROUND_ADVANTAGE,
        ]))->isResolved())->toBeFalse();
    });
});

describe('rung 2 — label text', function (): void {
    it('reads the service off a ZPL label where the tracking number was inconclusive', function (): void {
        $inference = inferrer()->infer(packageFor([
            'carrier' => 'DHL eCommerce',
            'tracking_number' => IMPB_GROUND_ADVANTAGE,
            'label_data' => labelFixture('dhl-ecommerce-ground.zpl'),
        ]));

        expect($inference->isResolved())->toBeTrue()
            ->and($inference->service)->toBe('DHL SmartMail Parcel Ground')
            ->and($inference->method)->toBe('label-text-zpl');
    });

    it('reads the service out of a PDF label', function (): void {
        $inference = inferrer()->infer(packageFor([
            'carrier' => 'FedEx',
            'label_data' => labelFixture('fedex-express-saver.pdf'),
        ]));

        expect($inference->isResolved())->toBeTrue()
            ->and($inference->service)->toBe('FedEx Express Saver®')
            ->and($inference->method)->toBe('label-text-pdf');
    });

    it('does not mistake a barcode payload for label text', function (): void {
        $fields = (new LabelTextExtractor)->extract(labelFixture('dhl-ecommerce-ground.zpl'));

        expect($fields)->toContain('GRD')
            ->and($fields)->not->toContain('4341009999999999')
            ->and(implode('|', $fields))->not->toContain('>;>8');
    });

    it('falls through rather than matching a token from a different carrier', function (): void {
        // The DHL label prints PS LIGHTWEIGHT -- the USPS product it hands off
        // to -- twelve fields before it prints its own GRD. Under a USPS carrier
        // of record neither is this carrier's token, so nothing resolves.
        $inference = inferrer()->infer(packageFor([
            'carrier' => 'USPS',
            'label_data' => labelFixture('dhl-ecommerce-ground.zpl'),
        ]));

        expect($inference->isResolved())->toBeFalse();
    });

    it('reads nothing from a label whose bytes are not a format it knows', function (): void {
        expect((new LabelTextExtractor)->extract(base64_encode('not a label')))->toBe([]);
    });

    it('does not throw when label_format disagrees with the bytes', function (): void {
        $package = packageFor([
            'carrier' => 'FedEx',
            'label_format' => 'pdf',
            'label_data' => labelFixture('dhl-ecommerce-ground.zpl'),
        ]);

        expect(inferrer()->infer($package)->isResolved())->toBeFalse();
    });
});

describe('exhausting the ladder', function (): void {
    it('leaves the package unknown with its requested preference intact', function (): void {
        $package = packageFor([
            'tracking_number' => IMPB_UNLISTED_STC,
            'requested_service' => 'Ground',
        ]);

        expect($package->recordInferredService(inferrer()->infer($package)))->toBeFalse()
            ->and($package->service)->toBeNull()
            ->and($package->service_evidence)->toBe(ServiceEvidence::Unknown)
            ->and($package->requested_service)->toBe('Ground');
    });
});

describe('token matching', function (): void {
    it('reads a service token that is the whole field', function (): void {
        $inference = inferrer()->infer(packageFor([
            'carrier' => 'FedEx',
            'label_data' => zplPrinting('FedEx Ground'),
        ]));

        expect($inference->service)->toBe('FedEx Ground®');
    });

    // FedEx Ground Economy is SMART_POST -- FedEx's USPS-last-mile service, and a
    // different service from FedEx Ground. Matching the shorter token inside the
    // longer field would name the wrong service and hide a consolidator, which is
    // the failure rung 1's guard exists to prevent.
    it('does not let a shorter token match inside a longer service name', function (string $field): void {
        $inference = inferrer()->infer(packageFor([
            'carrier' => 'FedEx',
            'label_data' => zplPrinting($field),
        ]));

        expect($inference->isResolved())->toBeFalse()
            ->and($inference->service)->toBeNull();
    })->with([
        'FedEx Ground Economy',
        'FEDEX GROUND ECONOMY',
        'FedEx Ground Multiweight',
    ]);

    it('matches a token regardless of the case the label prints it in', function (): void {
        expect(inferrer()->infer(packageFor([
            'carrier' => 'FedEx',
            'label_data' => zplPrinting('fedex ground'),
        ]))->service)->toBe('FedEx Ground®');
    });
});

describe('carrier aliasing', function (): void {
    // Shopify reports the carrier in its own spelling. Comparing the raw string
    // would fire the consolidator guard on a genuine USPS package.
    it('resolves an aliased carrier name before deciding a number is a handoff', function (): void {
        $usps = Carrier::factory()->create(['name' => 'USPS']);
        CarrierAlias::create(['carrier_id' => $usps->id, 'alias' => 'US Postal Service']);

        $inference = inferrer()->infer(packageFor([
            'carrier' => 'US Postal Service',
            'tracking_number' => IMPB_GROUND_ADVANTAGE,
        ]));

        expect($inference->isResolved())->toBeTrue()
            ->and($inference->service)->toBe('USPS Ground Advantage');
    });

    it('finds the label token table through an alias too', function (): void {
        $fedex = Carrier::factory()->create(['name' => 'FedEx']);
        CarrierAlias::create(['carrier_id' => $fedex->id, 'alias' => 'Federal Express']);

        expect(inferrer()->infer(packageFor([
            'carrier' => 'Federal Express',
            'label_data' => zplPrinting('FedEx Ground'),
        ]))->service)->toBe('FedEx Ground®');
    });

    it('still guards a genuinely foreign carrier carrying an IMpb', function (): void {
        Carrier::factory()->create(['name' => 'USPS']);

        $inference = inferrer()->infer(packageFor([
            'carrier' => 'DHL eCommerce',
            'tracking_number' => IMPB_GROUND_ADVANTAGE,
        ]));

        expect($inference->isResolved())->toBeFalse()
            ->and($inference->reason)->toContain('last-mile');
    });
});
