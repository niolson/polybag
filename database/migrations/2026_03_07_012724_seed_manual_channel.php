<?php

use App\Models\Channel;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Channel::firstOrCreate(
            ['name' => 'Manual'],
            [
                'icon' => 'heroicon-o-pencil-square',
                'active' => true,
            ]
        );
    }

    public function down(): void
    {
        Channel::where('name', 'Manual')->delete();
    }
};
