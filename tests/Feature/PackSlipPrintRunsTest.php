<?php

use App\DataTransferObjects\PackSlips\PackSlipRun;
use App\Models\Shipment;
use App\Models\User;
use App\Services\GotenbergService;
use App\Services\PackSlips\PackSlipPrintRuns;
use App\Services\PackSlips\PackSlipReceipts;
use App\Services\PackSlips\PackSlipRenderer;
use App\Services\SettingsService;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    app(SettingsService::class)->clearCache();
});

afterEach(function (): void {
    app(SettingsService::class)->clearCache();
});

/**
 * @param  list<int>  $ids
 */
function stagedRun(array $ids, ?User $user = null, array $toteCodes = []): array
{
    return app(PackSlipPrintRuns::class)->stage(new PackSlipRun($ids, $toteCodes), $user ?? test()->user);
}

it('stages a long run as one job per print job, drawing none of them', function (): void {
    $this->mock(GotenbergService::class)->shouldNotReceive('pdfFromView');

    $jobs = stagedRun(range(1, PackSlipRenderer::SLIPS_PER_PRINT_JOB * 2 + 1));

    expect(array_column($jobs, 'count'))->toBe([PackSlipRenderer::SLIPS_PER_PRINT_JOB, PackSlipRenderer::SLIPS_PER_PRINT_JOB, 1])
        ->and(array_unique(array_map(fn (array $job): string => parse_url($job['url'], PHP_URL_PATH), $jobs)))->toHaveCount(3);
});

it('draws only the requested job of a run, each request issuing its own receipt', function (): void {
    $shipments = Shipment::factory()->count(PackSlipRenderer::SLIPS_PER_PRINT_JOB + 2)->create();
    $this->mock(GotenbergService::class)->shouldReceive('pdfFromView')->twice()->andReturn('%PDF-fake');

    $jobs = stagedRun($shipments->pluck('id')->all());

    $second = $this->postJson($jobs[1]['url'])->assertOk()->json();
    $first = $this->postJson($jobs[0]['url'])->assertOk()->json();

    $receipts = app(PackSlipReceipts::class);

    expect(base64_decode($second['data']))->toBe('%PDF-fake')
        ->and($second['count'])->toBe(2)
        ->and(array_keys($receipts->open($second['receipt'], $this->user)->itemsVersions))
        ->toBe($shipments->slice(PackSlipRenderer::SLIPS_PER_PRINT_JOB)->pluck('id')->all())
        ->and($first['count'])->toBe(PackSlipRenderer::SLIPS_PER_PRINT_JOB)
        ->and(array_keys($receipts->open($first['receipt'], $this->user)->itemsVersions))
        ->toBe($shipments->take(PackSlipRenderer::SLIPS_PER_PRINT_JOB)->pluck('id')->all());
});

it('records nothing until the job\'s receipt is redeemed, so one failed job costs only itself', function (): void {
    $shipments = Shipment::factory()->count(PackSlipRenderer::SLIPS_PER_PRINT_JOB + 1)->create();
    $this->mock(GotenbergService::class)->shouldReceive('pdfFromView')->andReturn('%PDF-fake');

    $jobs = stagedRun($shipments->pluck('id')->all());

    $first = $this->postJson($jobs[0]['url'])->assertOk()->json();
    $this->postJson($jobs[1]['url'])->assertOk();

    // Drawing, even twice, marks nothing printed.
    $this->postJson($jobs[1]['url'])->assertOk();
    expect(Shipment::query()->whereNotNull('pack_slip_printed_at')->count())->toBe(0);

    // The browser sent job one and stopped before job two.
    $this->postJson(route('pack-slips.printed'), ['receipt' => $first['receipt']])->assertOk();

    expect(Shipment::query()->whereNotNull('pack_slip_printed_at')->count())->toBe(PackSlipRenderer::SLIPS_PER_PRINT_JOB)
        ->and($shipments->last()->fresh()->hasPrintedPackSlip())->toBeFalse();
});

it('keeps tote codes on the slips of a staged job', function (): void {
    $shipment = Shipment::factory()->create();
    $html = null;
    $this->mock(GotenbergService::class)
        ->shouldReceive('pdfFromView')
        ->andReturnUsing(function (string $view, array $data) use (&$html): string {
            $html = view($view, $data)->render();

            return '%PDF-fake';
        });

    $jobs = stagedRun([$shipment->id], toteCodes: [$shipment->id => 'T42']);
    $this->postJson($jobs[0]['url'])->assertOk();

    expect($html)->toContain('T42');
});

it('answers 410 for a run that is missing, expired, or has no such job', function (): void {
    $this->mock(GotenbergService::class)->shouldNotReceive('pdfFromView');
    $jobs = stagedRun([Shipment::factory()->create()->id]);

    $this->postJson(str_replace('/jobs/0', '/jobs/1', $jobs[0]['url']))->assertStatus(410);
    $this->postJson('/pack-slips/print-runs/'.str_repeat('a', 40).'/jobs/0')->assertStatus(410);

    $this->travel(PackSlipPrintRuns::LIFETIME_SECONDS + 1)->seconds();

    $this->postJson($jobs[0]['url'])->assertStatus(410);
});

it('will not draw a run for a different user or a signed-out browser', function (): void {
    $this->mock(GotenbergService::class)->shouldNotReceive('pdfFromView');
    $jobs = stagedRun([Shipment::factory()->create()->id]);

    $this->actingAs(User::factory()->create());
    $this->postJson($jobs[0]['url'])->assertStatus(410);

    auth()->logout();
    $this->postJson($jobs[0]['url'])->assertUnauthorized();
});

it('does not draw jobs while pack slips are off', function (): void {
    $this->mock(GotenbergService::class)->shouldNotReceive('pdfFromView');
    $jobs = stagedRun([Shipment::factory()->create()->id]);
    app(SettingsService::class)->set('pack_slips_enabled', false, 'boolean');

    $this->postJson($jobs[0]['url'])->assertForbidden();
});

it('tells the browser when the PDF renderer is unavailable, with nothing recorded', function (): void {
    $shipment = Shipment::factory()->create();
    $this->mock(GotenbergService::class)->shouldReceive('pdfFromView')->andThrow(new RuntimeException('down'));
    $jobs = stagedRun([$shipment->id]);

    $this->postJson($jobs[0]['url'])
        ->assertStatus(503)
        ->assertJson(['error' => GotenbergService::UNAVAILABLE]);

    expect($shipment->fresh()->hasPrintedPackSlip())->toBeFalse();
});
