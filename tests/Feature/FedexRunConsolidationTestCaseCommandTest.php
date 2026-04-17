<?php

use App\Http\Integrations\Fedex\Requests\AddConsolidationShipment;
use App\Http\Integrations\Fedex\Requests\ConfirmConsolidation;
use App\Http\Integrations\Fedex\Requests\CreateConsolidation;
use App\Http\Integrations\Fedex\Requests\GetConsolidationResults;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

it('polls Step 9 until consolidation results are ready', function (): void {
    $resultsCallCount = 0;

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test_token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        CreateConsolidation::class => MockResponse::make([
            'output' => [
                'consolidationKey' => [
                    'type' => 'INTERNATIONAL_PRIORITY_DISTRIBUTION',
                    'index' => '794804140154',
                    'date' => '2026-04-17',
                ],
            ],
        ]),
        AddConsolidationShipment::class => MockResponse::make([
            'output' => [
                'transactionShipments' => [
                    [
                        'masterTrackingNumber' => '794804140382',
                    ],
                ],
            ],
        ]),
        ConfirmConsolidation::class => MockResponse::make([
            'output' => [
                'jobId' => 'job-123',
            ],
        ]),
        GetConsolidationResults::class => function () use (&$resultsCallCount): MockResponse {
            $resultsCallCount++;

            if ($resultsCallCount === 1) {
                return MockResponse::make([
                    'errors' => [
                        [
                            'code' => 'SHIPMENT.REPLYDATA.NOTREADY',
                            'message' => 'JobId or reply data is not ready.',
                        ],
                    ],
                ], 400);
            }

            return MockResponse::make([
                'output' => [
                    'transactionShipments' => [
                        ['masterTrackingNumber' => '794804140382'],
                    ],
                ],
            ]);
        },
    ]);

    $this->artisan('fedex:run-consolidation-test')
        ->expectsOutputToContain('Consolidation created')
        ->expectsOutputToContain('Shipment 6 added')
        ->expectsOutputToContain('Consolidation confirmed')
        ->expectsOutputToContain('Results not ready yet, retrying')
        ->expectsOutputToContain('Results received')
        ->expectsOutputToContain('IntegratorUS10 completed successfully.')
        ->assertSuccessful();
});
