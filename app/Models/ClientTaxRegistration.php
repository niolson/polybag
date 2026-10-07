<?php

namespace App\Models;

use App\Enums\TaxRegistrationRegime;
use Database\Factories\ClientTaxRegistrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client's seller tax registration under one regime — ADR-0008 decision 3.
 *
 * A client holds at most one per regime. A Shipment's own registration, when
 * its regime covers the destination, replaces this one rather than joining it.
 *
 * @property int $client_id
 * @property TaxRegistrationRegime $regime
 * @property string $number
 */
class ClientTaxRegistration extends Model
{
    /** @use HasFactory<ClientTaxRegistrationFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'regime',
        'number',
    ];

    protected function casts(): array
    {
        return [
            'regime' => TaxRegistrationRegime::class,
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
