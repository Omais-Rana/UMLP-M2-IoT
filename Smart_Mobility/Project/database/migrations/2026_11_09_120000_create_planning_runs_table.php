<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status')->index();
            $table->unsignedInteger('total_participants');
            $table->unsignedInteger('completed_participants')->default(0);
            $table->json('participants');
            $table->json('request_payload');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_runs');
    }
};
