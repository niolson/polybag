<?php

namespace App\Services\PackSlips;

use App\DataTransferObjects\PackSlips\PackSlipReceipt;
use App\DataTransferObjects\PackSlips\PackSlipRedemption;
use App\Enums\ShipmentStatus;
use App\Exceptions\InvalidPackSlipReceiptException;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;

/**
 * Issues and redeems pack slip receipts.
 *
 * A receipt is sealed with the app key, so its Shipment list and versions cannot
 * be altered in the browser. Redemption only moves forward: it records a print
 * only when the receipt's (items version, issue time) is newer than the one
 * stored, so a duplicate or late acknowledgment never overwrites a newer print.
 */
class PackSlipReceipts
{
    /**
     * Long enough for a slip view left open across a shift to still be marked
     * printed, short enough that an old tab cannot record a print days later.
     */
    public const int LIFETIME_SECONDS = 86400;

    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.u';

    /**
     * Read each Shipment's items version. Call this before loading the items that
     * go on the slip, so a change made while the slip renders leaves it out of date.
     *
     * @param  list<int>  $shipmentIds
     */
    public function issue(array $shipmentIds, User $user): PackSlipReceipt
    {
        $versions = Shipment::query()
            ->whereKey($shipmentIds)
            ->pluck('items_version', 'id')
            ->map(fn (mixed $version): int => (int) $version)
            ->all();

        $issuedAt = now()->utc();

        return new PackSlipReceipt(
            userId: $user->id,
            issuedAt: $issuedAt->format(self::TIMESTAMP_FORMAT),
            expiresAt: $issuedAt->getTimestamp() + self::LIFETIME_SECONDS,
            itemsVersions: $versions,
        );
    }

    public function seal(PackSlipReceipt $receipt): string
    {
        return Crypt::encryptString((string) json_encode([
            'u' => $receipt->userId,
            't' => $receipt->issuedAt,
            'e' => $receipt->expiresAt,
            'v' => $receipt->itemsVersions,
        ]));
    }

    /**
     * @throws InvalidPackSlipReceiptException
     */
    public function open(string $token, User $user): PackSlipReceipt
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new InvalidPackSlipReceiptException('This pack slip receipt is not valid.');
        }

        if (! is_array($payload)
            || ! is_int($payload['u'] ?? null)
            || ! is_string($payload['t'] ?? null)
            || ! is_int($payload['e'] ?? null)
            || ! is_array($payload['v'] ?? null)) {
            throw new InvalidPackSlipReceiptException('This pack slip receipt is not valid.');
        }

        if ($payload['u'] !== $user->id) {
            throw new InvalidPackSlipReceiptException('This pack slip receipt was issued to another user.');
        }

        if ($payload['e'] < now()->getTimestamp()) {
            throw new InvalidPackSlipReceiptException('This pack slip receipt has expired. View or print the slips again.');
        }

        $versions = [];

        foreach ($payload['v'] as $shipmentId => $version) {
            $versions[(int) $shipmentId] = (int) $version;
        }

        return new PackSlipReceipt($payload['u'], $payload['t'], $payload['e'], $versions);
    }

    /**
     * Record the receipt's print on each Shipment still open whose stored print is
     * older. Nothing but the four pack slip print columns changes.
     */
    public function redeem(PackSlipReceipt $receipt): PackSlipRedemption
    {
        $printedAt = now();
        $recorded = 0;

        $idsByVersion = [];

        foreach ($receipt->itemsVersions as $shipmentId => $version) {
            $idsByVersion[$version][] = $shipmentId;
        }

        foreach ($idsByVersion as $version => $shipmentIds) {
            $recorded += Shipment::query()->toBase()
                ->whereIn('id', $shipmentIds)
                ->where('status', ShipmentStatus::Open->value)
                ->where(fn (Builder $query) => $query
                    ->whereNull('pack_slip_items_version')
                    ->orWhere('pack_slip_items_version', '<', $version)
                    ->orWhere(fn (Builder $query) => $query
                        ->where('pack_slip_items_version', $version)
                        ->where(fn (Builder $query) => $query
                            ->whereNull('pack_slip_receipt_issued_at')
                            ->orWhere('pack_slip_receipt_issued_at', '<', $receipt->issuedAt))))
                ->update([
                    'pack_slip_items_version' => $version,
                    'pack_slip_receipt_issued_at' => $receipt->issuedAt,
                    'pack_slip_printed_at' => $printedAt,
                    'pack_slip_printed_by_user_id' => $receipt->userId,
                ]);
        }

        return new PackSlipRedemption($recorded, $receipt->count() - $recorded);
    }
}
