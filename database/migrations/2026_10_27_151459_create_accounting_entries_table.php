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
        Schema::create('accounting_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('imports')->cascadeOnDelete();
            $table->string('sheet_name');
            $table->unsignedInteger('row_number');
            $table->string('location');
            $table->string('bank_name');
            $table->date('transaction_date');
            $table->string('check_no')->nullable();
            $table->string('paid_to_by');
            $table->string('account');
            $table->decimal('credit', 16, 2)->nullable();
            $table->decimal('debit', 16, 2)->nullable();
            $table->text('description');
            $table->string('class_name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_entries');
    }
};
