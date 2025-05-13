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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->date('work_date')->comment('Effective work date, the day the shift starts. Crucial for night shifts.');
            $table->foreignId('work_schedule_id')->nullable()->constrained('work_schedules')->onDelete('set null')->comment('The work schedule applicable for this attendance record.');

            $table->dateTime('clock_in_at')->nullable()->comment('Exact timestamp (UTC or with timezone) of clock-in.');
            $table->string('clock_in_status', 50)->nullable()->comment('e.g., On Time, Late, Approved Late');
            $table->text('clock_in_notes')->nullable();
            $table->decimal('clock_in_latitude', 10, 7)->nullable();
            $table->decimal('clock_in_longitude', 10, 7)->nullable();
            $table->foreignId('clock_in_device_id')->nullable()->constrained('user_devices')->onDelete('set null');
            $table->foreignId('clock_in_qr_code_id')->nullable()->constrained('qr_codes')->onDelete('set null');
            $table->string('clock_in_method', 50)->nullable()->default('qr_scan')->comment('Clock-in method: qr_scan, manual_admin, auto_system');

            $table->dateTime('clock_out_at')->nullable()->comment('Exact timestamp (UTC or with timezone) of clock-out.');
            $table->string('clock_out_status', 50)->nullable()->comment('e.g., On Time, Early Leave, Approved Early Leave');
            $table->text('clock_out_notes')->nullable();
            $table->decimal('clock_out_latitude', 10, 7)->nullable();
            $table->decimal('clock_out_longitude', 10, 7)->nullable();
            $table->foreignId('clock_out_device_id')->nullable()->constrained('user_devices')->onDelete('set null');
            $table->foreignId('clock_out_qr_code_id')->nullable()->constrained('qr_codes')->onDelete('set null');
            $table->string('clock_out_method', 50)->nullable()->default('qr_scan')->comment('Clock-out method: qr_scan, manual_admin, auto_system');

            // Denormalized scheduled times for easier reporting of adherence
            $table->time('scheduled_start_time')->nullable()->comment('Denormalized: Scheduled start time for this work_date.');
            $table->time('scheduled_end_time')->nullable()->comment('Denormalized: Scheduled end time for this work_date.');
            $table->integer('scheduled_work_duration_minutes')->nullable()->comment('Denormalized: Scheduled work duration in minutes (could include paid breaks).');

            $table->integer('work_duration_minutes')->nullable()->comment('Actual duration between clock_in_at and clock_out_at.');
            $table->integer('effective_work_minutes')->nullable()->comment('Work duration minus scheduled breaks (if applicable).');
            $table->integer('overtime_minutes')->nullable()->default(0)->comment('Overtime minutes, calculated based on schedule.');
            $table->integer('lateness_minutes')->nullable()->default(0)->comment('Lateness minutes, calculated based on schedule.');
            $table->integer('early_leave_minutes')->nullable()->default(0)->comment('Early leave minutes, calculated based on schedule.');

            $table->boolean('is_manually_corrected')->default(false)->comment('Flag if this record was ever corrected by an admin.');
            $table->foreignId('last_corrected_by')->nullable()->constrained('users')->onDelete('set null')->comment('FK to users. Last admin who corrected this.');
            $table->timestamp('last_correction_at')->nullable()->comment('Timestamp of the last correction.');
            $table->text('correction_summary_notes')->nullable()->comment('Summary notes from all corrections on this record.');

            $table->timestamps();

            $table->unique(['user_id', 'work_date'], 'attendances_user_id_work_date_unique');
            $table->index('work_date');
            $table->index('clock_in_at');
            $table->index('clock_out_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
