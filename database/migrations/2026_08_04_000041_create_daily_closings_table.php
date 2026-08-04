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
        Schema::create('daily_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->date('closing_date');
            $table->decimal('opening_cash', 10, 2)->default(0.00);
            $table->decimal('cash_in', 10, 2)->default(0.00);
            $table->decimal('cash_out', 10, 2)->default(0.00);
            $table->decimal('closing_cash', 10, 2)->default(0.00);
            $table->decimal('actual_cash', 10, 2)->nullable();
            $table->decimal('discrepancy', 10, 2)->default(0.00);
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'closing_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_closings');
    }
};
