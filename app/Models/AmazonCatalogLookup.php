<?php

namespace App\Models;

use Database\Factories\AmazonCatalogLookupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A SKU an Amazon catalog sync listed, waiting for the catalog look-up of its
 * ASIN (`product-catalog-sync/04`). The listing sync records one per listing
 * as each page commits; the look-up job works through them and deletes them.
 *
 * @property int $id
 * @property int $data_source_id
 * @property string $sku
 * @property string $asin
 */
class AmazonCatalogLookup extends Model
{
    /** @use HasFactory<AmazonCatalogLookupFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'data_source_id',
        'sku',
        'asin',
    ];

    /**
     * @return BelongsTo<DataSource, $this>
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
