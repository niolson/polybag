<?php

namespace App\Console\Commands;

use App\Http\Integrations\Fedex\FedexConnector;
use App\Services\FedexTestCases\FedexTestCaseNormalizer;
use App\Services\FedexTestCases\FedexTestCaseRepository;
use App\Services\FedexTestCases\FedexTestCaseRunner;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class FedexRunTestCases extends Command
{
    private const SUPPORTED_REGIONS = ['us', 'ca', 'lac'];

    private const SUPPORTED_SUITES = ['ship', 'rate'];

    protected $signature = 'fedex:run-test-cases
        {cases?* : Test case IDs to run (e.g. IntegratorUS01 IntegratorUS02). Runs all by default.}
        {--region=us : Fixture region to load from resources/data/carrier-test-cases/fedex (us, ca, lac)}
        {--suite=ship : Fixture suite to load from resources/data/carrier-test-cases/fedex (ship, rate)}
        {--fixture= : Explicit path to a test case JSON file}
        {--save-labels : Save label files under storage/app/dev/fedex-test-runs/}
        {--dump-payloads : Save resolved request/response payloads under storage/app/dev/fedex-test-runs/}';

    protected $description = 'Run FedEx developer validation test cases and log request/response details';

    public function handle(
        SettingsService $settings,
        FedexTestCaseRepository $repository,
        FedexTestCaseNormalizer $normalizer,
        FedexTestCaseRunner $runner,
    ): int {
        $region = strtolower((string) $this->option('region'));
        $suiteName = strtolower((string) $this->option('suite'));

        if (! in_array($region, self::SUPPORTED_REGIONS, true)) {
            $this->error('Unsupported region ['.$region.']. Valid regions: '.implode(', ', self::SUPPORTED_REGIONS));

            return self::FAILURE;
        }

        if (! in_array($suiteName, self::SUPPORTED_SUITES, true)) {
            $this->error('Unsupported suite ['.$suiteName.']. Valid suites: '.implode(', ', self::SUPPORTED_SUITES));

            return self::FAILURE;
        }

        try {
            $suite = $repository->load(
                region: $region,
                suite: $suiteName,
                path: $this->option('fixture') ?: null,
            );
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $testCases = $suite->cases();

        $requested = $this->argument('cases');

        if (! empty($requested)) {
            $testCases = $testCases->filter(fn ($testCase) => in_array($testCase->id, $requested, true));

            if ($testCases->isEmpty()) {
                $this->error('No matching test cases found. Valid IDs: '.implode(', ', $suite->caseIds()));

                return self::FAILURE;
            }
        }

        $skipped = $testCases->filter(fn ($testCase) => ! $testCase->supported);
        $testCases = $testCases->reject(fn ($testCase) => ! $testCase->supported);

        foreach ($skipped as $testCase) {
            $this->warn("Skipping {$testCase->id} ({$testCase->description}) — {$testCase->skipReason}");
        }

        if ($testCases->isEmpty()) {
            $this->warn('No supported test cases to run.');

            return self::SUCCESS;
        }

        $shipperAccountNumber = (string) $settings->get('fedex.account_number', '');

        if (empty($shipperAccountNumber)) {
            $this->error('FedEx account number not configured in settings (fedex.account_number).');

            return self::FAILURE;
        }

        $connector = FedexConnector::getAuthenticatedConnector();
        $saveLabels = (bool) $this->option('save-labels');
        $dumpPayloads = (bool) $this->option('dump-payloads');
        $artifactDirectory = ($saveLabels || $dumpPayloads)
            ? 'dev/fedex-test-runs/'.now()->format('Ymd_His')
            : null;

        $passed = 0;
        $failed = 0;

        foreach ($testCases as $testCase) {
            $this->info("Running {$testCase->id}: {$testCase->description} ...");

            $payload = $normalizer->normalize($testCase, $shipperAccountNumber);
            $result = $runner->run(
                connector: $connector,
                testCase: $testCase,
                payload: $payload,
                saveLabels: $saveLabels,
                artifactDirectory: $artifactDirectory,
            );

            if (! ($result['success'] ?? false)) {
                $status = $result['status'] ?? 'ERR';
                $errorCode = $result['error_code'] ?? 'UNKNOWN';
                $message = $result['message'] ?? 'Unknown error';
                $this->error("  ✗ Failed ({$status}): [{$errorCode}] {$message}");
                $failed++;

                continue;
            }

            $this->line("  ✓ Tracking: {$result['tracking_number']}");

            if ($result['label_path']) {
                $this->line('  ✓ Label saved: storage/app/'.$result['label_path']);
            }

            if ($result['package_id'] && $result['shipment_id']) {
                $this->line("  ✓ Package record created: ID {$result['package_id']} (Shipment ID {$result['shipment_id']})");
            }

            $passed++;
        }

        $this->newLine();
        $this->info("Done. Passed: {$passed}, Failed: {$failed}.");

        if ($artifactDirectory) {
            $this->line('Artifacts saved under: storage/app/'.$artifactDirectory);
        }

        $this->line('Full request/response logged to: storage/logs/fedex-validation.log');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
