<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\AttendanceService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AttendanceCorrectionLog>
 */
class AttendanceCorrectionLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Pastikan ada attendance record dan admin user untuk di-link
        // Jika tidak ada, factory akan membuatnya.
        $attendance = Attendance::inRandomOrder()->first() ?? Attendance::factory()->create();
        $admin = User::where('role', 'admin')->inRandomOrder()->first() ?? User::factory()->admin()->create();

        // Daftar kolom yang mungkin diubah di tabel 'attendances'
        $possibleChangedColumns = [
            'work_date',
            'work_schedule_id',
            'clock_in_at',
            'clock_in_status',
            'clock_in_notes',
            'clock_out_at',
            'clock_out_status',
            'clock_out_notes',
        ];
        $changedColumn = $this->faker->randomElement($possibleChangedColumns);

        $oldValue = null;
        $newValue = null;

        // Generate old/new value yang sedikit lebih realistis berdasarkan kolom
        switch ($changedColumn) {
            case 'work_date':
                $oldValue = $attendance->work_date->copy()->subDays($this->faker->numberBetween(1, 5))->toDateString();
                $newValue = $attendance->work_date->toDateString(); // Atau tanggal lain
                break;
            case 'work_schedule_id':
                $oldValue = (string)($attendance->work_schedule_id ?? (WorkSchedule::inRandomOrder()->first()?->id ?? WorkSchedule::factory()->create()->id));
                $newSchedule = WorkSchedule::where('id', '!=', $oldValue)->inRandomOrder()->first() ?? WorkSchedule::factory()->create();
                $newValue = (string)$newSchedule->id;
                break;
            case 'clock_in_at':
                $oldValueDateTime = $attendance->clock_in_at ? $attendance->clock_in_at->copy()->subMinutes($this->faker->numberBetween(15, 60)) : Carbon::parse($attendance->work_date->toDateString() . " 07:00:00");
                $newValueDateTime = $oldValueDateTime->copy()->addMinutes($this->faker->numberBetween(5, 30));
                $oldValue = $oldValueDateTime->format('Y-m-d H:i:s');
                $newValue = $newValueDateTime->format('Y-m-d H:i:s');
                break;
            case 'clock_out_at':
                $oldValueDateTime = $attendance->clock_out_at ? $attendance->clock_out_at->copy()->addMinutes($this->faker->numberBetween(15, 60)) : ($attendance->clock_in_at ? $attendance->clock_in_at->copy()->addHours(8) : Carbon::parse($attendance->work_date->toDateString() . " 17:30:00"));
                $newValueDateTime = $oldValueDateTime->copy()->subMinutes($this->faker->numberBetween(5, 30));
                $oldValue = $oldValueDateTime->format('Y-m-d H:i:s');
                $newValue = $newValueDateTime->format('Y-m-d H:i:s');
                break;
            case 'clock_in_status':
                $statuses = [AttendanceService::STATUS_CLOCK_IN_ON_TIME, AttendanceService::STATUS_CLOCK_IN_LATE];
                $oldValue = $attendance->clock_in_status ?? $this->faker->randomElement($statuses);
                $newValue = $this->faker->randomElement(array_diff($statuses, [$oldValue])) ?? $statuses[0];
                break;
            case 'clock_out_status':
                $statuses = [AttendanceService::STATUS_CLOCK_OUT_ON_TIME, AttendanceService::STATUS_CLOCK_OUT_EARLY, AttendanceService::STATUS_CLOCK_OUT_OVERTIME];
                $oldValue = $attendance->clock_out_status ?? $this->faker->randomElement($statuses);
                $newValue = $this->faker->randomElement(array_diff($statuses, [$oldValue])) ?? $statuses[0];
                break;
            case 'clock_in_notes':
            case 'clock_out_notes':
                $oldValue = $this->faker->optional()->sentence;
                $newValue = $this->faker->sentence;
                break;
            default:
                $oldValue = (string)$this->faker->word;
                $newValue = (string)$this->faker->word;
        }

        return [
            'attendance_id' => $attendance->id,
            'admin_user_id' => $admin->id,
            'changed_column' => $changedColumn,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'reason' => $this->faker->sentence(10),
            'ip_address_of_admin' => $this->faker->ipv4,
            'corrected_at' => Carbon::instance($this->faker->dateTimeBetween($attendance->updated_at ?? '-1 month', 'now')),
            // created_at and updated_at akan diisi otomatis oleh Eloquent
        ];
    }

    /**
     * Mengkonfigurasi factory untuk record absensi tertentu.
     */
    public function forAttendance(Attendance $attendance): static
    {
        return $this->state(fn (array $attributes) => [
            'attendance_id' => $attendance->id,
        ]);
    }

    /**
     * Mengkonfigurasi factory untuk admin tertentu.
     */
    public function byAdmin(User $admin): static
    {
        // Pastikan user yang di-pass adalah admin
        if ($admin->role !== 'admin') { // Asumsi ada kolom 'role' di User model
            // Atau lempar exception, atau buat admin baru jika tidak ditemukan
            $admin = User::factory()->admin()->create();
        }
        return $this->state(fn (array $attributes) => [
            'admin_user_id' => $admin->id,
        ]);
    }

    /**
     * Mengkonfigurasi factory untuk kolom spesifik yang diubah.
     */
    public function forChangedColumn(string $columnName, $oldValue = null, $newValue = null): static
    {
        return $this->state(function (array $attributes) use ($columnName, $oldValue, $newValue) {
            // Jika oldValue atau newValue tidak disediakan, coba generate yang masuk akal
            if (is_null($oldValue) && is_null($newValue)) {
                // Logika sederhana untuk generate, bisa diperluas
                $oldValue = $this->faker->word;
                $newValue = $this->faker->word;
                if ($columnName === 'clock_in_status' || $columnName === 'clock_out_status') {
                    $statuses = [AttendanceService::STATUS_CLOCK_IN_ON_TIME, AttendanceService::STATUS_CLOCK_IN_LATE];
                    $oldValue = $this->faker->randomElement($statuses);
                    $newValue = $this->faker->randomElement(array_diff($statuses, [$oldValue])) ?? $statuses[0];
                } elseif (str_contains($columnName, '_at')) { // Untuk kolom waktu
                    $oldValue = Carbon::now()->subHours(2)->format('Y-m-d H:i:s');
                    $newValue = Carbon::now()->subHours(1)->format('Y-m-d H:i:s');
                }
            }

            return [
                'changed_column' => $columnName,
                'old_value' => (string) $oldValue, // Pastikan string untuk DB
                'new_value' => (string) $newValue, // Pastikan string untuk DB
            ];
        });
    }
}
