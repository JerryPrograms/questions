<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('accounting_entry_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_entry_id')->constrained()->cascadeOnDelete();
            $table->text('answer');
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at');
        });

        DB::table('accounting_entries')
            ->whereNotNull('answer')
            ->whereNotNull('answered_at')
            ->orderBy('id')
            ->eachById(function (object $entry): void {
                DB::table('accounting_entry_answers')->insert([
                    'accounting_entry_id' => $entry->id,
                    'answer' => $entry->answer,
                    'answered_by' => $entry->answered_by,
                    'answered_at' => $entry->answered_at,
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_entry_answers');
    }
};
