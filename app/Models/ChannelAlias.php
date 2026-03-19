<?php

namespace App\Models;

use Database\Factories\ChannelAliasFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelAlias extends Model
{
    /** @use HasFactory<ChannelAliasFactory> */
    use HasFactory;

    protected $fillable = [
        'reference',
        'channel_id',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
