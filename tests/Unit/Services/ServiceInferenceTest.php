<?php

use App\Enums\ServiceEvidence;
use App\Models\Carrier;
use App\Models\CarrierAlias;
use App\Models\Package;
use App\Services\ServiceInference\ImpbTrackingNumber;
use App\Services\ServiceInference\LabelTextExtractor;
use App\Services\ServiceInference\ServiceInferrer;
use App\Services\ServiceInference\Ups1zTrackingNumber;

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

/**
 * A real UPS Ground Saver 1Z, off a Shopify-bought label that prints both this
 * number and the plaintext `UPS GROUND SAVER`. Its check digit is genuine, which
 * is what the check digit implementation was verified against.
 */
const UPS_1Z_GROUND_SAVER = '1Z28X87GYW27798425';

/** The USPS IMpb printed on that same Ground Saver label, for the last mile. */
const IMPB_ON_THE_GROUND_SAVER_LABEL = '92612903368162541515909494';

/**
 * Real Shopify-bought UPS international labels. Every one of these indicators was
 * read off a production shipment history first and then returned independently by
 * these purchases -- two unrelated systems agreeing on values that appear in no
 * UPS documentation, which is why the international rows are mapped at all.
 */
const UPS_1Z_WORLDWIDE_SAVER = '1Z28X87G0411692869';
const UPS_1Z_WORLDWIDE_EXPRESS = '1Z28X87G6604926058';
const UPS_1Z_WORLDWIDE_EXPEDITED = '1Z28X87G6713238443';

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

describe('UPS 1Z validation', function (): void {
    it('reads the service level indicator out of bytes 9 and 10', function (): void {
        $ups = Ups1zTrackingNumber::tryParse(UPS_1Z_GROUND_SAVER);

        expect($ups)->not->toBeNull()
            ->and($ups->serviceIndicator)->toBe('YW');
    });

    it('tolerates the spacing a label prints and the case a person types', function (): void {
        expect(Ups1zTrackingNumber::tryParse('1z 28x 87g yw 2779 8425')?->serviceIndicator)->toBe('YW');
    });

    it('rejects a number whose check digit does not compute', function (): void {
        expect(Ups1zTrackingNumber::tryParse(substr(UPS_1Z_GROUND_SAVER, 0, -1).'4'))->toBeNull();
    });

    it('rejects a placeholder 1Z even though UPS tracks it', function (): void {
        // UPS's own tracking page accepts this number and follows it through a
        // void, so it is registered -- but its check digit does not compute, and
        // reading a service out of a number that fails validation is the
        // confident wrong answer the whole rung is built to refuse.
        //
        // This is not what a development store issues generally: it came from a
        // label bought by hand in the Shopify admin, and every label bought
        // through PolyBag against the same store carries a well-formed number.
        expect(Ups1zTrackingNumber::tryParse('1Z000X00YW00000002'))->toBeNull();
    });

    it('accepts the numbers a development store issues through the API', function (string $number): void {
        expect(Ups1zTrackingNumber::tryParse($number))->not->toBeNull();
    })->with([
        'ground saver' => UPS_1Z_GROUND_SAVER,
        'worldwide expedited' => UPS_1Z_WORLDWIDE_EXPEDITED,
    ]);

    it('rejects anything that is not the 1Z shape', function (string $candidate): void {
        expect(Ups1zTrackingNumber::tryParse($candidate))->toBeNull();
    })->with([
        'too short' => '1Z28X87GYW2779842',
        'no 1Z prefix' => '2Z28X87GYW27798425',
        'letters in the package number' => '1Z28X87GYWABCD8425',
        'empty' => '',
    ]);
});

describe('the UPS 1Z rung', function (): void {
    it('infers Ground Saver from the YW service level indicator', function (): void {
        $inference = inferrer()->infer(packageFor([
            'carrier' => 'UPS',
            'tracking_number' => UPS_1Z_GROUND_SAVER,
        ]));

        expect($inference->service)->toBe('UPS Ground Saver')
            ->and($inference->method)->toBe(ServiceInferrer::METHOD_UPS_1Z)
            ->and($inference->rulesetVersion)->not->toBeEmpty();
    });

    it('infers the domestic services whose indicator matches the API service code', function (string $indicator, string $service): void {
        $number = ups1zWithIndicator($indicator);

        expect(inferrer()->infer(packageFor(['carrier' => 'UPS', 'tracking_number' => $number]))->service)
            ->toBe($service);
    })->with([
        ['01', 'UPS Next Day Air'],
        ['02', 'UPS 2nd Day Air'],
        ['03', 'UPS Ground'],
        ['12', 'UPS 3 Day Select'],
        ['13', 'UPS Next Day Air Saver'],
    ]);

    it('infers the international services from real labels', function (string $number, string $service): void {
        expect(inferrer()->infer(packageFor(['carrier' => 'UPS', 'tracking_number' => $number]))->service)
            ->toBe($service);
    })->with([
        [UPS_1Z_WORLDWIDE_SAVER, 'UPS Worldwide Saver'],
        [UPS_1Z_WORLDWIDE_EXPRESS, 'UPS Worldwide Express'],
        [UPS_1Z_WORLDWIDE_EXPEDITED, 'UPS Worldwide Expedited'],
    ]);

    it('infers UPS Standard, the one row resting on a single source', function (): void {
        expect(inferrer()->infer(packageFor([
            'carrier' => 'UPS',
            'tracking_number' => ups1zWithIndicator('68'),
        ]))->service)->toBe('UPS Standard');
    });

    it('does not read a UPS API service code as a service level indicator', function (string $apiCode, string $wouldHaveMeant): void {
        // The two vocabularies diverge on every international service, so a table
        // built from UPS's published API codes would resolve these -- to the right
        // service name from the wrong vocabulary, on numbers that mean something
        // else or nothing. Pinned so that mistake cannot be made quietly later.
        $inference = inferrer()->infer(packageFor([
            'carrier' => 'UPS',
            'tracking_number' => ups1zWithIndicator($apiCode),
        ]));

        expect($inference->isResolved())->toBeFalse()
            ->and($inference->service)->not->toBe($wouldHaveMeant);
    })->with([
        'Worldwide Saver is 04, not 65' => ['65', 'UPS Worldwide Saver'],
        'Worldwide Express is 66, not 07' => ['07', 'UPS Worldwide Express'],
        'Worldwide Expedited is 67, not 08' => ['08', 'UPS Worldwide Expedited'],
        'Standard is 68, not 11' => ['11', 'UPS Standard'],
    ]);

    it('falls through on an indicator the table has no evidence for', function (string $indicator): void {
        $inference = inferrer()->infer(packageFor([
            'carrier' => 'UPS',
            'tracking_number' => ups1zWithIndicator($indicator),
        ]));

        expect($inference->isResolved())->toBeFalse()
            ->and($inference->reason)->toContain($indicator);
    })->with([
        // Seen on a label whose service is unknown. A Y prefix is not assumed to
        // be a Ground Saver family.
        'YN' => 'YN',
        // Adjacent to the observed international block on both sides. Contiguity
        // is not evidence, so neither is mapped.
        '69' => '69',
        '05' => '05',
    ]);

    it('declines a 1Z reported under a carrier that is not UPS', function (): void {
        $inference = inferrer()->infer(packageFor([
            'carrier' => 'USPS',
            'tracking_number' => UPS_1Z_GROUND_SAVER,
        ]));

        expect($inference->isResolved())->toBeFalse()
            ->and($inference->reason)->toContain('disagree');
    });

    it('reads the 1Z rather than the IMpb on a Ground Saver label, and gets a different answer', function (): void {
        // Both numbers are printed on the one label. The IMpb is genuine and its
        // service type code decodes to Parcel Select -- the USPS product carrying
        // the last mile, not the service the customer bought. Which number the
        // postage source happens to report therefore decides whether the answer
        // is right, so both paths are pinned here.
        $fromImpb = inferrer()->infer(packageFor([
            'carrier' => 'UPS',
            'tracking_number' => IMPB_ON_THE_GROUND_SAVER_LABEL,
        ]));

        expect($fromImpb->isResolved())->toBeFalse()
            ->and($fromImpb->reason)->toContain('last-mile');

        expect(inferrer()->infer(packageFor([
            'carrier' => 'UPS',
            'tracking_number' => UPS_1Z_GROUND_SAVER,
        ]))->service)->toBe('UPS Ground Saver');
    });
});

/**
 * A valid 1Z carrying a given service level indicator, check digit recomputed so
 * the number passes validation the way a real one does.
 */
function ups1zWithIndicator(string $indicator): string
{
    $body = '28X87G'.$indicator.'2779842';

    $odd = 0;
    $even = 0;

    foreach (str_split($body) as $position => $character) {
        $value = ctype_digit($character) ? (int) $character : (ord($character) - 63) % 10;

        if ($position % 2 === 0) {
            $odd += $value;
        } else {
            $even += $value;
        }
    }

    return '1Z'.$body.((10 - ($odd + $even * 2) % 10) % 10);
}
