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
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('Descriptive name for the schedule, e.g., "Office Hours", "Night Shift A"');
            $table->time('start_time')->comment('Standard start time for this shift');
            $table->time('end_time')->comment('Standard end time for this shift');
            $table->boolean('crosses_midnight')->default(false)->comment('Indicates if the shift crosses midnight');
            $table->decimal('work_duration_hours', 4, 2)->comment('Expected work duration in hours');
            $table->integer('break_duration_minutes')->default(0)->comment('Expected break duration in minutes');
            
            // Days of the week this schedule applies to
            $table->boolean('monday')->default(false);
            $table->boolean('tuesday')->default(false);
            $table->boolean('wednesday')->default(false);
            $table->boolean('thursday')->default(false);
            $table->boolean('friday')->default(false);
            $table->boolean('saturday')->default(false);
            $table->boolean('sunday')->default(false);

            $table->integer('grace_period_late_minutes')->default(0)->comment('Grace period for lateness in minutes');
            $table->integer('grace_period_early_leave_minutes')->default(0)->comment('Grace period for early leave in minutes');

            $table->boolean('is_default')->default(false)->comment('Is this the default schedule if no specific assignment is found?');
            $table->boolean('is_active')->default(true)->comment('Is this schedule currently active?');
            
            $table->timestamps();

            $table->index('name');
            $table->index('is_default');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
    }
};
