<?php

use App\Models\User;
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
        Schema::create('grid_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('mode', 16);
            $table->string('suggested_grade', 4)->nullable();
            $table->decimal('margin', 18, 2);
            $table->unsignedTinyInteger('leverage');
            $table->decimal('lower_price', 24, 10)->nullable();
            $table->decimal('upper_price', 24, 10)->nullable();
            $table->unsignedSmallInteger('grid_count')->nullable();
            $table->unsignedInteger('matched_trades')->default(0);
            $table->decimal('pnl', 18, 2)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grid_trades');
    }
};
