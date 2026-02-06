<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelAlias extends Model
{
    /** @use HasFactory<\Database\Factories\ChannelAliasFactory> */
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
