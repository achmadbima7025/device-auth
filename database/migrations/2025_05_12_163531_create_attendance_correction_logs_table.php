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
        Schema::create('attendance_correction_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained('attendances')->onDelete('cascade')->comment('The attendance record that was corrected');
            $table->foreignId('admin_user_id')->constrained('users')->onDelete('restrict')->comment('The admin user who made the correction');
            $table->string('changed_column', 100)->comment('The name of the column that was changed in the attendances table');
            $table->text('old_value')->nullable()->comment('The value of the column before the change');
            $table->text('new_value')->nullable()->comment('The value of the column after the change');
            $table->text('reason')->comment('The reason provided by the admin for the correction');
            $table->string('ip_address_of_admin', 45)->nullable()->comment('IP address of the admin at the time of correction');
            $table->timestamp('corrected_at')->useCurrent()->comment('Timestamp when the correction was made');
            
            $table->timestamps();

            $table->index('attendance_id');
            $table->index('admin_user_id');
            $table->index('corrected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_correction_logs');
    }
};
