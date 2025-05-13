<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\UserWorkScheduleAssignment;
use App\Services\Attendance\WorkScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class WorkScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WorkScheduleService $workScheduleService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workScheduleService = $this->app->make(WorkScheduleService::class);
    }

    public function test_get_user_active_schedule_for_date_returns_assigned_schedule_when_active(): void
    {
        $user = User::factory()->create();
        $schedule = WorkSchedule::factory()->create(['name' => 'Specific Active Shift']);
        UserWorkScheduleAssignment::factory()->for($user)->for($schedule)
            ->state([
                'effective_start_date' => Carbon::today()->subDays(5),
                'effective_end_date' => Carbon::today()->addDays(5),
            ])->create();

        $activeSchedule = $this->workScheduleService->getUserActiveScheduleForDate($user, Carbon::today());

        $this->assertNotNull($activeSchedule);
        $this->assertEquals($schedule->id, $activeSchedule->id);
        $this->assertEquals('Specific Active Shift', $activeSchedule->name);
    }

    public function test_get_user_active_schedule_for_date_returns_default_if_no_specific_assignment_active(): void
    {
        $user = User::factory()->create();
        $defaultSchedule = WorkSchedule::factory()->defaultSchedule()->create(['name' => 'Company Default']);
        // Pastikan tidak ada assignment lain yang aktif untuk user ini
        UserWorkScheduleAssignment::where('user_id', $user->id)->delete();


        $activeSchedule = $this->workScheduleService->getUserActiveScheduleForDate($user, Carbon::today());

        $this->assertNotNull($activeSchedule);
        $this->assertEquals($defaultSchedule->id, $activeSchedule->id);
        $this->assertTrue($activeSchedule->is_default);
    }

    public function test_get_user_active_schedule_for_date_returns_null_if_no_assignment_and_no_active_default(): void
    {
        $user = User::factory()->create();
        WorkSchedule::where('is_default', true)->update(['is_active' => false]); // Nonaktifkan semua default

        $activeSchedule = $this->workScheduleService->getUserActiveScheduleForDate($user, Carbon::today());

        $this->assertNull($activeSchedule);
    }

    public function test_assign_schedule_to_user_correctly_creates_assignment_and_ends_previous_ones(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $oldSchedule = WorkSchedule::factory()->create(['name' => 'Old Shift']);
        $newSchedule = WorkSchedule::factory()->create(['name' => 'New Shift']);

        // Assignment lama yang masih aktif tanpa batas akhir
        UserWorkScheduleAssignment::factory()->for($user)->for($oldSchedule)
            ->state([
                'effective_start_date' => Carbon::today()->subMonths(3),
                'effective_end_date' => null,
            ])->create();

        // Assignment lama yang periodenya tumpang tindih
        UserWorkScheduleAssignment::factory()->for($user)->for($oldSchedule)
            ->state([
                'effective_start_date' => Carbon::today()->subMonths(1),
                'effective_end_date' => Carbon::today()->addMonths(1), // Akan diakhiri
            ])->create();


        $newAssignmentStartDate = Carbon::today()->addDays(15);
        $assignment = $this->workScheduleService->assignScheduleToUser(
            $user,
            $newSchedule,
            $newAssignmentStartDate->toDateString(),
            null, // Indefinite
            $admin,
            'Transition to new role'
        );

        $this->assertNotNull($assignment);
        $this->assertEquals($newSchedule->id, $assignment->work_schedule_id);
        $this->assertEquals($newAssignmentStartDate->toDateString(), $assignment->effective_start_date->toDateString());
        $this->assertNull($assignment->effective_end_date);

        // Cek assignment lama yang tanpa batas akhir, seharusnya sudah diakhiri
        $indefiniteOldAssignment = UserWorkScheduleAssignment::where('user_id', $user->id)
            ->where('work_schedule_id', $oldSchedule->id)
            ->where('effective_start_date', Carbon::today()->subMonths(3)->toDateString())
            ->first();
        $this->assertNotNull($indefiniteOldAssignment->effective_end_date);
        $this->assertEquals(
            $newAssignmentStartDate->copy()->subDay()->toDateString(),
            $indefiniteOldAssignment->effective_end_date->toDateString()
        );

        // Cek assignment lama yang tumpang tindih, seharusnya juga sudah diakhiri
        $overlappingOldAssignment = UserWorkScheduleAssignment::where('user_id', $user->id)
            ->where('work_schedule_id', $oldSchedule->id)
            ->where('effective_start_date', Carbon::today()->subMonths(1)->toDateString())
            ->first();
        $this->assertNotNull($overlappingOldAssignment->effective_end_date);
        $this->assertEquals(
            $newAssignmentStartDate->copy()->subDay()->toDateString(),
            $overlappingOldAssignment->effective_end_date->toDateString()
        );
    }

    public function test_create_work_schedule_ensures_only_one_default_if_new_is_default(): void
    {
        $oldDefault = WorkSchedule::factory()->defaultSchedule()->create(['name' => 'Original Default']);
        $newScheduleData = WorkSchedule::factory()->make(['is_default' => true, 'name' => 'New System Default'])->toArray();

        $newDefaultSchedule = $this->workScheduleService->createWorkSchedule($newScheduleData);

        $this->assertTrue($newDefaultSchedule->is_default);
        $oldDefault->refresh(); // Refresh model lama dari database
        $this->assertFalse($oldDefault->is_default);
        $this->assertDatabaseHas('work_schedules', ['id' => $newDefaultSchedule->id, 'is_default' => true]);
        $this->assertDatabaseHas('work_schedules', ['id' => $oldDefault->id, 'is_default' => false]);
    }

    public function test_update_work_schedule_ensures_only_one_default(): void
    {
        $schedule1 = WorkSchedule::factory()->create(['name' => 'Schedule 1', 'is_default' => false]);
        $schedule2 = WorkSchedule::factory()->defaultSchedule()->create(['name' => 'Schedule 2 (Old Default)']);

        // Update schedule1 menjadi default
        $updatedSchedule1 = $this->workScheduleService->updateWorkSchedule($schedule1, ['is_default' => true]);

        $this->assertTrue($updatedSchedule1->is_default);
        $schedule2->refresh();
        $this->assertFalse($schedule2->is_default);
    }
}
