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
        Schema::create('attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique()->comment('Unique identifier for the setting');
            $table->text('value')->nullable()->comment('Value of the setting');
            $table->string('data_type', 20)->default('string')->comment('string, integer, boolean, json, time');
            $table->text('description')->nullable()->comment('Description of the setting');
            $table->string('group', 50)->nullable()->comment('Setting group, e.g.: general, gps, schedule, overtime_rules');
            // Scope for settings, e.g., global, department_id, user_role_id
            // $table->string('scope_type')->default('global'); 
            // $table->unsignedBigInteger('scope_id')->nullable(); 
            // $table->index(['scope_type', 'scope_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_settings');
    }
};
