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
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->string('unique_code', 255)->unique()->comment('Unique payload or token embedded in the QR code');
            $table->string('type', 50)->default('daily')->comment('Example: daily, static_location, single_use, shift_specific');
            // $table->foreignId('location_id')->nullable()->constrained('work_locations')->onDelete('cascade'); // If you have a work_locations table
            $table->string('related_location_name')->nullable()->comment('Name of the related location if no specific table');
            $table->foreignId('work_schedule_id')->nullable()->constrained('work_schedules')->onDelete('set null')->comment('If QR code is specific to a work schedule');
            $table->json('additional_payload')->nullable()->comment('Additional JSON payload relevant to the QR code');
            $table->date('valid_on_date')->nullable()->comment('For daily type QR codes, the date it is valid');
            $table->timestamp('expires_at')->nullable()->comment('Exact time the QR code is no longer valid');
            $table->timestamp('used_at')->nullable()->comment('If single-use, record when it was used');
            $table->foreignId('used_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('valid_on_date');
            $table->index('expires_at');
            $table->index('type');
            $table->index('work_schedule_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
