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
        Schema::table('accounting_entries', function (Blueprint $table) {
            $table->text('answer')->nullable()->after('description');
            $table->foreignId('answered_by')->nullable()->after('answer')->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable()->after('answered_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounting_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('answered_by');
            $table->dropColumn(['answer', 'answered_at']);
        });
    }
};
