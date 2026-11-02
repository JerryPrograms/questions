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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('location')->nullable();
            $table->text('summary');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['location', 'created_at']);
        });

        DB::table('accounting_entry_answers')
            ->join('accounting_entries', 'accounting_entries.id', '=', 'accounting_entry_answers.accounting_entry_id')
            ->leftJoin('users', 'users.id', '=', 'accounting_entry_answers.answered_by')
            ->select(
                'accounting_entry_answers.id as answer_id',
                'accounting_entry_answers.accounting_entry_id',
                'accounting_entry_answers.answer',
                'accounting_entry_answers.answered_by',
                'accounting_entry_answers.answered_at',
                'accounting_entries.location',
                'users.name as actor_name',
                'users.email as actor_email',
                'users.role as actor_role',
            )
            ->chunkById(500, function ($answers): void {
                DB::table('audit_logs')->insert($answers->map(fn (object $answer): array => [
                    'actor_id' => $answer->answered_by,
                    'actor_name' => $answer->actor_name,
                    'actor_email' => $answer->actor_email,
                    'actor_role' => $answer->actor_role,
                    'action' => 'question.answered',
                    'subject_type' => 'question',
                    'subject_id' => $answer->accounting_entry_id,
                    'location' => $answer->location,
                    'summary' => 'Added an answer to question #'.$answer->accounting_entry_id.'.',
                    'old_values' => null,
                    'new_values' => json_encode(['answer' => $answer->answer], JSON_THROW_ON_ERROR),
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => $answer->answered_at,
                ])->all());
            }, 'accounting_entry_answers.id', 'answer_id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
