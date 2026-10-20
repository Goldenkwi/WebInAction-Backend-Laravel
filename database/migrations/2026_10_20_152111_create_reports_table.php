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
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->integer('ai_pred_cat_id');
            $table->float('ai_confidence');
            $table->enum('report_type', ['pengaduan', 'aspirasi']);
            $table->string('title');
            $table->string('description');
            $table->date('incident_date');
            $table->string('image_url');
            $table->string('location_text');
            $table->boolean('is_anonympus');
            $table->boolean('is_private');
            $table->enum('status', ['done', 'pending', 'rejection']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
