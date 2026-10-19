<?php

namespace App\Models;

use Database\Factories\ScanFormRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The `X-Idempotency-Key` a USPS SCAN form request went out under, kept until
 * its form is saved so the same request can be sent again under the same key
 * when the reply never arrived.
 */
class ScanFormRequest extends Model
{
    /** @use HasFactory<ScanFormRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'request_hash',
        'idempotency_key',
    ];

    /**
     * The request for this mailing date and set of tracking numbers, made if
     * it has not been sent before.
     *
     * @param  array<int, string>  $trackingNumbers
     */
    public static function forTrackingNumbers(string $mailingDate, array $trackingNumbers): self
    {
        return self::firstOrCreate(
            ['request_hash' => self::hashOf($mailingDate, $trackingNumbers)],
            ['idempotency_key' => (string) Str::uuid()],
        );
    }

    /**
     * Drop the request once its form is saved, so the key is not reused.
     *
     * @param  array<int, string>  $trackingNumbers
     */
    public static function forgetTrackingNumbers(string $mailingDate, array $trackingNumbers): void
    {
        self::where('request_hash', self::hashOf($mailingDate, $trackingNumbers))->delete();
    }

    /**
     * @param  array<int, string>  $trackingNumbers
     */
    private static function hashOf(string $mailingDate, array $trackingNumbers): string
    {
        sort($trackingNumbers);

        return hash('sha256', $mailingDate.'|'.implode(',', $trackingNumbers));
    }
}
