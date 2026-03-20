<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manifests', function (Blueprint $table) {
            $table->id();
            $table->string('carrier');
            $table->string('manifest_number');
            $table->longText('image')->nullable();
            $table->date('manifest_date');
            $table->unsignedInteger('package_count');
            $table->timestamps();
            $table->index(['carrier', 'manifest_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manifests');
    }
};
