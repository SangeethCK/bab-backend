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
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('chair_id')->nullable()->after('employee_id')->constrained('chairs')->nullOnDelete();
            $table->index(['tenant_id', 'chair_id', 'start_time', 'end_time', 'status'], 'idx_tenant_chair_booking');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['chair_id']);
            $table->dropIndex('idx_tenant_chair_booking');
            $table->dropColumn('chair_id');
        });
    }
};
