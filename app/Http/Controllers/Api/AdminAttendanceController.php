<?php

namespace App\Http\Controllers\Api;

use Exception;
use App\Models\User;
use App\Models\Attendance;
use App\Models\WorkSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\AttendanceSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\QrCode as QrCodeModel;
use Illuminate\Database\QueryException;
use App\Http\Requests\WorkScheduleRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\UserWorkScheduleAssignment;
use App\Services\Attendance\QrCodeService;
use App\Http\Resources\AttendanceCollection;
use App\Http\Resources\WorkScheduleResource;
use App\Http\Requests\AttendanceReportRequest;
use App\Services\Attendance\AttendanceService;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\AssignWorkScheduleRequest;
use App\Services\Attendance\WorkScheduleService;
use App\Http\Requests\AttendanceCorrectionRequest;
use App\Http\Requests\UpdateAttendanceSettingsRequest;
use App\Http\Requests\UpdateUserWorkScheduleAssignmentRequest;
use App\Http\Resources\UserWorkScheduleAssignmentResource;
use App\Http\Resources\UserWorkScheduleAssignmentCollection;

class AdminAttendanceController extends Controller
{
    public function __construct(
        protected readonly AttendanceService $attendanceService,
        protected readonly QrCodeService $qrCodeService,
        protected readonly WorkScheduleService $workScheduleService
    )
    {
    }

    /**
     * Melihat laporan absensi.
     *
     * @param AttendanceReportRequest $request
     * @return AttendanceCollection|JsonResponse
     */
    public function viewReports(AttendanceReportRequest $request): AttendanceCollection|JsonResponse
    {
        try {
            $validated = $request->validated();
            $query = Attendance::with([
                'user:id,name,email',
                'workSchedule:id,name',
                'clockInDevice:id,name',
                'clockOutDevice:id,name',
            ]);

            if (isset($validated['start_date']) && isset($validated['end_date'])) {
                $query->whereBetween('work_date', [
                    Carbon::parse($validated['start_date'])->startOfDay(),
                    Carbon::parse($validated['end_date'])->endOfDay(),
                ]);
            }
            if (isset($validated['user_id'])) $query->where('user_id', $validated['user_id']);
            if (isset($validated['work_schedule_id'])) $query->where('work_schedule_id', $validated['work_schedule_id']);
            if (isset($validated['clock_in_status'])) $query->where('clock_in_status', $validated['clock_in_status']);
            if (isset($validated['clock_out_status'])) $query->where('clock_out_status', $validated['clock_out_status']);

            $sortBy = $validated['sort_by'] ?? 'work_date';
            $sortDirection = $validated['sort_direction'] ?? 'desc';
            $query->orderBy($sortBy, $sortDirection);
            if ($sortBy !== 'id' && $sortBy !== $query->getModel()->getKeyName()) {
                $query->orderBy($query->getModel()->getKeyName(), $sortDirection);
            }

            $perPage = $validated['per_page'] ?? 15;
            $reports = $query->paginate($perPage);

            return new AttendanceCollection($reports);

        } catch (Exception $e) {
            Log::error("Error fetching admin attendance reports: {$e->getMessage()}", ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'An internal server error occurred while fetching reports.'], 500);
        }
    }

    /**
     * Melakukan koreksi data absensi.
     *
     * @param AttendanceCorrectionRequest $request
     * @param Attendance $attendance (Route Model Binding)
     * @return JsonResponse
     */
    public function makeCorrection(AttendanceCorrectionRequest $request, Attendance $attendance): JsonResponse
    {
        $admin = $request->user();
        Log::info("Admin {$admin->id} ({$admin->email}) is attempting to correct attendance ID: {$attendance->id}");

        $validatedData = $request->validated();
        $dataToPassToService = [];

        // Tentukan tanggal dasar untuk waktu. Jika work_date diubah, gunakan itu.
        // Jika tidak, gunakan tanggal kerja yang ada di record absensi.
        $baseDateForTime = isset($validatedData['work_date'])
            ? Carbon::parse($validatedData['work_date'])
            : $attendance->work_date; // Ini sudah objek Carbon dari model

        if (isset($validatedData['work_date'])) {
            $dataToPassToService['work_date'] = Carbon::parse($validatedData['work_date'])->startOfDay();
        }

        // Penting: Asumsikan admin input H:i:s dalam timezone aplikasi (UTC) atau frontend sudah konversi ke UTC.
        // Jika frontend kirim datetime lengkap dengan timezone, parsing akan lebih mudah.
        if (isset($validatedData['clock_in_at_time'])) {
            $dataToPassToService['clock_in_at'] = Carbon::parse($baseDateForTime->toDateString() . ' ' . $validatedData['clock_in_at_time'], config('app.timezone'));
        }
        if (isset($validatedData['clock_out_at_time'])) {
            if ($validatedData['clock_out_at_time'] === null || $validatedData['clock_out_at_time'] === '') { // Handle jika admin ingin mengosongkan clock_out
                $dataToPassToService['clock_out_at'] = null;
            } else {
                $dataToPassToService['clock_out_at'] = Carbon::parse($baseDateForTime->toDateString() . ' ' . $validatedData['clock_out_at_time'], config('app.timezone'));
                // Penanganan lintas hari jika clock_out < clock_in pada tanggal yang sama
                $clockInToCompare = $dataToPassToService['clock_in_at'] ?? $attendance->clock_in_at;
                if ($clockInToCompare && $dataToPassToService['clock_out_at'] && $dataToPassToService['clock_out_at']->lt($clockInToCompare)) {
                    $dataToPassToService['clock_out_at']->addDay();
                }
            }
        }

        // Salin field lain yang divalidasi
        $otherFields = ['clock_in_status', 'clock_in_notes', 'clock_out_status', 'clock_out_notes', 'work_schedule_id', 'admin_correction_notes'];
        foreach ($otherFields as $field) {
            if (array_key_exists($field, $validatedData)) {
                $dataToPassToService[$field] = $validatedData[$field];
            }
        }

        try {
            $updatedAttendance = $this->attendanceService->correctAttendance($attendance, $dataToPassToService, $admin);
            return response()->json([
                'message' => 'Attendance record corrected successfully.',
                'data' => new AttendanceResource($updatedAttendance->fresh()->load(['user:id,name', 'workSchedule:id,name', 'correctionLogs.admin:id,name'])),
            ]);
        } catch (ValidationException $e) {
            Log::warning("Validation error during attendance correction by admin {$admin->id} for attendance ID {$attendance->id}: " . $e->getMessage(), ['errors' => $e->errors()]);
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->status);
        } catch (Exception $e) {
            Log::error("Error correcting attendance ID {$attendance->id} by admin {$admin->id}: {$e->getMessage()}", ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Failed to correct attendance record.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Mengambil semua pengaturan absensi.
     */
    public function getSettings(): JsonResponse
    {
        $settings = AttendanceSetting::orderBy('group')->orderBy('key')->get();
        $formattedSettings = $settings->groupBy('group')->mapWithKeys(function ($groupItems, $groupName) {
            return [$groupName => $groupItems->pluck('value', 'key')];
        });
        return response()->json($formattedSettings);
    }

    /**
     * Memperbarui pengaturan absensi.
     */
    public function updateSettings(UpdateAttendanceSettingsRequest $request): JsonResponse
    {
        $settingsData = $request->validated()['settings'];
        $allSettingsInDb = AttendanceSetting::all()->keyBy('key');

        DB::transaction(function () use ($settingsData, $allSettingsInDb) {
            foreach ($settingsData as $key => $value) {
                if ($allSettingsInDb->has($key)) {
                    $settingToUpdate = $allSettingsInDb->get($key);
                    $settingToUpdate->value = $value;
                    $settingToUpdate->save();
                } else {
                    Log::warning("Attempted to update non-existent attendance setting key: {$key}");
                }
            }
        });

        return response()->json(['message' => 'Attendance settings updated successfully.']);
    }

    // --- QR Code Management (Admin) ---
    public function generateDailyQr(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_name' => 'sometimes|string|max:100',
            'work_schedule_id' => 'sometimes|nullable|integer|exists:work_schedules,id',
            'additional_payload' => 'sometimes|array',
        ]);
        $locationName = $validated['location_name'] ?? 'Main Office Default';
        $workScheduleId = $validated['work_schedule_id'] ?? null;

        try {
            $qrCodeModel = $this->qrCodeService->generateDailyAttendanceQr($locationName, $workScheduleId, $validated['additional_payload'] ?? []);
            $qrImageDataUri = $this->qrCodeService->generateQrCodeImageUri(
                json_encode($qrCodeModel->additional_payload),
                "Attendance {$locationName} - " . $qrCodeModel->valid_on_date->format('d M Y')
            );
            return response()->json([
                'message' => 'Daily QR Code generated successfully.',
                'qr_code_data' => $qrCodeModel->toArray(),
                'qr_code_image_uri' => $qrImageDataUri,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to generate daily QR Code: {$e->getMessage()}", ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Failed to generate daily QR Code.', 'error' => $e->getMessage()], 500);
        }
    }

    public function getActiveQrCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_name' => 'sometimes|string|max:100',
            'work_schedule_id' => 'sometimes|nullable|integer|exists:work_schedules,id',
        ]);
        $locationName = $validated['location_name'] ?? 'Main Office Default';
        $workScheduleId = $validated['work_schedule_id'] ?? null;

        $qrCodeModel = $this->qrCodeService->getActiveDisplayableQrCode($locationName, $workScheduleId);

        if (!$qrCodeModel) {
            return response()->json(['message' => "No active QR Code found for {$locationName}" . ($workScheduleId ? " and schedule ID {$workScheduleId}" : "") . "."], 404);
        }

        $qrImageDataUri = $this->qrCodeService->generateQrCodeImageUri(
            json_encode($qrCodeModel->additional_payload),
            "Attendance {$locationName} - " . $qrCodeModel->valid_on_date->format('d M Y')
        );
        return response()->json([
            'qr_code_data' => $qrCodeModel->toArray(),
            'qr_code_image_uri' => $qrImageDataUri,
        ]);
    }

    // --- Work Schedule Management (Admin) ---
    public function listWorkSchedules(Request $request): JsonResponse
    {
        // Bisa ditambahkan paginasi jika diperlukan
        $perPage = $request->query('per_page', 15);
        $schedules = WorkSchedule::orderBy('name')->paginate($perPage);
        return WorkScheduleResource::collection($schedules)->response();
    }

    public function createWorkSchedule(WorkScheduleRequest $request): JsonResponse
    {
        $schedule = $this->workScheduleService->createWorkSchedule($request->validated());
        return new WorkScheduleResource($schedule->fresh())
            ->additional(['message' => 'Work schedule created successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    public function showWorkSchedule(WorkSchedule $workSchedule): WorkScheduleResource|JsonResponse
    {
        // Pastikan workSchedule di-load jika ada relasi yang ingin ditampilkan oleh resource
        // $workSchedule->loadMissing([]); // Contoh: $workSchedule->loadMissing('userAssignments.user');
        return new WorkScheduleResource($workSchedule);
    }

    public function deleteWorkSchedule(WorkSchedule $workSchedule): JsonResponse
    {
        try {
            // Tambahkan logika untuk mengecek apakah jadwal kerja masih digunakan
            // sebelum menghapus, misalnya di UserWorkScheduleAssignment atau Attendance.
            if ($workSchedule->userAssignments()->exists() || $workSchedule->attendances()->exists()) {
                return response()->json(['message' => 'Cannot delete work schedule. It is still in use.'], 409); // Conflict
            }
            DB::transaction(function () use ($workSchedule) {
                // Jika ada aturan terkait penghapusan assignment atau QR codes yang terkait, handle di sini
                UserWorkScheduleAssignment::where('work_schedule_id', $workSchedule->id)->delete();
                QrCodeModel::where('work_schedule_id', $workSchedule->id)->update(['work_schedule_id' => null]); // Atau delete
                $workSchedule->delete();
            });

            return response()->json(['message' => 'Work schedule deleted successfully.'], 200);
        } catch (QueryException $e) {
            // Tangani error foreign key constraint jika ada
            Log::error("Error deleting work schedule ID {$workSchedule->id}: {$e->getMessage()}");
            return response()->json(['message' => 'Failed to delete work schedule. It might be in use or a database error occurred.'], 500);
        } catch (Exception $e) {
            Log::error("Error deleting work schedule ID {$workSchedule->id}: {$e->getMessage()}");
            return response()->json(['message' => 'An unexpected error occurred.'], 500);
        }
    }

    public function listUserScheduleAssignments(Request $request, User $user): UserWorkScheduleAssignmentCollection|JsonResponse
    {
        // Validasi tambahan untuk request jika perlu (misal, filter tanggal)
        $perPage = $request->query('per_page', 15);
        $assignments = UserWorkScheduleAssignment::where('user_id', $user->id)
                                                 ->with(['workSchedule:id,name', 'assignedBy:id,name'])
                                                 ->orderBy('effective_start_date', 'desc')
                                                 ->paginate($perPage);

        return new UserWorkScheduleAssignmentCollection($assignments);
    }

    public function updateWorkSchedule(WorkScheduleRequest $request, WorkSchedule $workSchedule): JsonResponse
    {
        $schedule = $this->workScheduleService->updateWorkSchedule($workSchedule, $request->validated());
        return new WorkScheduleResource($schedule->fresh())
            ->additional(['message' => 'Work schedule updated successfully.'])
            ->response();
    }

    public function assignScheduleToUser(AssignWorkScheduleRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        $user = User::findOrFail($validatedData['user_id']);
        $workSchedule = WorkSchedule::findOrFail($validatedData['work_schedule_id']);
        $admin = $request->user();

        $assignment = $this->workScheduleService->assignScheduleToUser(
            $user,
            $workSchedule,
            $validatedData['effective_start_date'],
            $validatedData['effective_end_date'] ?? null,
            $admin,
            $validatedData['assignment_notes'] ?? null
        );
        return new UserWorkScheduleAssignmentResource($assignment->load(['user:id,name', 'workSchedule:id,name', 'assignedBy:id,name']))
            ->additional(['message' => 'Work schedule assigned to user successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Memperbarui penugasan jadwal kerja pengguna.
     *
     * @param UpdateUserWorkScheduleAssignmentRequest $request
     * @param UserWorkScheduleAssignment $userWorkScheduleAssignment
     * @return JsonResponse
     */
    public function updateScheduleAssignment(UpdateUserWorkScheduleAssignmentRequest $request, UserWorkScheduleAssignment $userWorkScheduleAssignment): JsonResponse
    {
        try {
            $validatedData = $request->validated();
            $admin = $request->user();

            // Jika work_schedule_id diubah, pastikan jadwal kerja ada
            if (isset($validatedData['work_schedule_id'])) {
                $workSchedule = WorkSchedule::findOrFail($validatedData['work_schedule_id']);
                // Pastikan jadwal kerja aktif
                if (!$workSchedule->is_active) {
                    return response()->json(['message' => 'Cannot assign inactive work schedule.'], 422);
                }
            }

            $assignment = $this->workScheduleService->updateUserScheduleAssignment(
                $userWorkScheduleAssignment,
                $validatedData,
                $admin
            );

            return new UserWorkScheduleAssignmentResource($assignment->fresh()->load(['user:id,name', 'workSchedule:id,name', 'assignedBy:id,name']))
                ->additional(['message' => 'Work schedule assignment updated successfully.'])
                ->response();
        } catch (Exception $e) {
            Log::error("Error updating work schedule assignment ID {$userWorkScheduleAssignment->id}: {$e->getMessage()}");
            return response()->json(['message' => 'An unexpected error occurred while updating the work schedule assignment.'], 500);
        }
    }

    /**
     * Menghapus penugasan jadwal kerja pengguna.
     *
     * @param UserWorkScheduleAssignment $userWorkScheduleAssignment
     * @return JsonResponse
     */
    public function deleteScheduleAssignment(UserWorkScheduleAssignment $userWorkScheduleAssignment): JsonResponse
    {
        try {
            // Cek apakah penugasan ini sedang digunakan dalam absensi
            $hasAttendances = Attendance::where('user_id', $userWorkScheduleAssignment->user_id)
                ->where('work_schedule_id', $userWorkScheduleAssignment->work_schedule_id)
                ->whereBetween('work_date', [
                    $userWorkScheduleAssignment->effective_start_date,
                    $userWorkScheduleAssignment->effective_end_date ?? now()->addYears(10) // Jika tidak ada tanggal akhir, gunakan tanggal jauh di masa depan
                ])
                ->exists();

            if ($hasAttendances) {
                return response()->json(['message' => 'Cannot delete work schedule assignment. It is being used in attendance records.'], 409);
            }

            $this->workScheduleService->deleteUserScheduleAssignment($userWorkScheduleAssignment);

            return response()->json(['message' => 'Work schedule assignment deleted successfully.'], 200);
        } catch (Exception $e) {
            Log::error("Error deleting work schedule assignment ID {$userWorkScheduleAssignment->id}: {$e->getMessage()}");
            return response()->json(['message' => 'An unexpected error occurred while deleting the work schedule assignment.'], 500);
        }
    }
}
