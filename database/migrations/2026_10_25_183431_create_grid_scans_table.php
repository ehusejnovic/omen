<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('grid_scans', function (Blueprint $table) {
            $table->id();
            $table->string('status', 16)->index();
            $table->unsignedSmallInteger('symbols_scanned')->default(0);
            $table->unsignedSmallInteger('suggestions_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grid_scans');
    }
};
