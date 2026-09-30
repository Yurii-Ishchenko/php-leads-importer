<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('path');
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('total_rows')->nullable();
            $table->dateTime('started_at', 3)->nullable();
            $table->dateTime('finished_at', 3)->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('source_row');
            $table->string('external_id')->index();
            $table->dateTime('created_at')->nullable();
            foreach (['first_name', 'last_name', 'phone', 'email', 'city', 'source', 'utm_campaign', 'product', 'status', 'manager'] as $column) {
                $table->string($column)->nullable();
            }
            $table->decimal('budget_uah', 15, 2)->nullable();
            $table->text('comment')->nullable();
            $table->dateTime('next_contact_at')->nullable();
            $table->unique(['import_id', 'source_row']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
        Schema::dropIfExists('imports');
    }
};
