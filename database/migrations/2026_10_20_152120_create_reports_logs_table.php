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
        Schema::create('reports_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('repots')->cascadeOnDelete();
            $table->foreignId('change_by')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['done', 'pending', 'rejection']);
            $table->string('note');
            $table->string('proof_img');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports_logs');
    }
};
