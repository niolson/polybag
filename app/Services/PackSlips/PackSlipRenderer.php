<?php

namespace App\Services\PackSlips;

use App\DataTransferObjects\PackSlips\PackSlip;
use App\DataTransferObjects\PackSlips\PackSlipPrintJob;
use App\DataTransferObjects\PackSlips\PackSlipRun;
use App\Models\Client;
use App\Models\Location;
use App\Models\Shipment;
use App\Models\User;
use App\Services\GotenbergService;
use App\Services\Scanning\ScanCode;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * Draws pack slips for any set of Shipments: a pick batch, a single Shipment's page,
 * or the Print Pack Slips queue. Branding is each Shipment's own Client's, so a run
 * that spans Clients still brands every slip correctly.
 */
class PackSlipRenderer
{
    public const string VIEW = 'pack-slips.slips';

    /**
     * The most slips one print-bridge job holds. A longer run is sent as several
     * jobs, each with its own receipt, so one failure costs at most this many.
     */
    public const int SLIPS_PER_PRINT_JOB = 200;

    public function __construct(
        private readonly GotenbergService $gotenberg,
        private readonly SettingsService $settings,
        private readonly PackSlipReceipts $receipts,
    ) {}

    /**
     * The browser view, carrying the receipt its Mark as printed control redeems.
     */
    public function view(PackSlipRun $run, User $user): View
    {
        $receipt = $this->receipts->issue($run->shipmentIds, $user);

        return view(self::VIEW, [
            ...$this->viewData($run),
            'receipt' => $this->receipts->seal($receipt),
        ]);
    }

    /**
     * The run as print-bridge jobs, in run order.
     *
     * @return list<PackSlipPrintJob>
     *
     * @throws \RuntimeException if the PDF renderer is unavailable
     */
    public function printJobs(PackSlipRun $run, User $user): array
    {
        return array_map(function (PackSlipRun $chunk) use ($user): PackSlipPrintJob {
            $receipt = $this->receipts->issue($chunk->shipmentIds, $user);

            return new PackSlipPrintJob(
                pdf: $this->gotenberg->pdfFromView(self::VIEW, $this->viewData($chunk)),
                receipt: $this->receipts->seal($receipt),
                count: $receipt->count(),
            );
        }, $run->chunk(self::SLIPS_PER_PRINT_JOB));
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(PackSlipRun $run): array
    {
        return [
            'title' => $run->title,
            'slips' => $this->slips($run),
            'generator' => new BarcodeGeneratorSVG,
            'defaultLocation' => Location::getDefault(),
            'receipt' => null,
        ];
    }

    /**
     * @return list<PackSlip>
     */
    private function slips(PackSlipRun $run): array
    {
        $shipments = Shipment::query()
            ->with(['client', 'shipmentItems.product'])
            ->whereKey($run->shipmentIds)
            ->get()
            ->keyBy('id');

        $logos = [];
        $slips = [];

        foreach ($run->shipmentIds as $id) {
            $shipment = $shipments->get($id);

            if (! $shipment) {
                continue;
            }

            $logoKey = $shipment->client_id ?? 0;
            $logos[$logoKey] ??= $this->logoDataUri($shipment->client);

            $slips[] = new PackSlip(
                shipment: $shipment,
                client: $shipment->client,
                logoDataUri: $logos[$logoKey],
                toteCode: $run->toteCodes[$id] ?? null,
                scanCode: ScanCode::forShipment($shipment),
            );
        }

        return $slips;
    }

    private function logoDataUri(?Client $client): ?string
    {
        $path = $client->logo ?? $this->settings->get('pack_slip_logo');

        if (blank($path) || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $mimeType = match (strtolower(pathinfo((string) $path, PATHINFO_EXTENSION))) {
            'svg' => 'image/svg+xml',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return 'data:'.$mimeType.';base64,'.base64_encode((string) Storage::disk('public')->get($path));
    }
}
