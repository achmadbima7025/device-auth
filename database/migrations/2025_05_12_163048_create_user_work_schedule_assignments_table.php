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
        Schema::create('user_work_schedule_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('work_schedule_id')->constrained('work_schedules')->onDelete('cascade');
            $table->date('effective_start_date')->comment('Start date for this schedule assignment');
            $table->date('effective_end_date')->nullable()->comment('End date for this schedule assignment (null if indefinite)');
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->onDelete('set null')->comment('Admin or manager who assigned this schedule');
            $table->text('assignment_notes')->nullable()->comment('Notes regarding this assignment');
            $table->timestamps();

            $table->index('user_id');
            $table->index('work_schedule_id');
            $table->index('effective_start_date');
            $table->index('effective_end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_work_schedule_assignments');
    }
};
