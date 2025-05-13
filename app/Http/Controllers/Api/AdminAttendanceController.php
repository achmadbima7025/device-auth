<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignWorkScheduleRequest;
use App\Http\Requests\AttendanceCorrectionRequest;
use App\Http\Requests\AttendanceReportRequest;
use App\Http\Requests\UpdateAttendanceSettingsRequest;
use App\Http\Requests\WorkScheduleRequest;
use App\Http\Resources\AttendanceCollection;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\UserWorkScheduleAssignmentResource;
use App\Http\Resources\WorkScheduleResource;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\QrCodeService;
use App\Services\Attendance\WorkScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminAttendanceController extends Controller
{
    public function __construct(
        protected readonly AttendanceService $attendanceService,
        protected readonly QrCodeService $qrCodeService,
        protected readonly WorkScheduleService $workScheduleService
    ) {
        // Middleware 'is.admin' sebaiknya diterapkan pada grup rute di api.php
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
                'workSchedule:id,name', // Eager load relasi yang relevan
                'clockInDevice:id,name',
                'clockOutDevice:id,name'
            ]);

            if (isset($validated['start_date']) && isset($validated['end_date'])) {
                $query->whereBetween('work_date', [
                    Carbon::parse($validated['start_date'])->startOfDay(),
                    Carbon::parse($validated['end_date'])->endOfDay()
                ]);
            }
            // Filter lainnya
            if (isset($validated['user_id'])) $query->where('user_id', $validated['user_id']);
            if (isset($validated['work_schedule_id'])) $query->where('work_schedule_id', $validated['work_schedule_id']);
            if (isset($validated['clock_in_status'])) $query->where('clock_in_status', $validated['clock_in_status']);
            if (isset($validated['clock_out_status'])) $query->where('clock_out_status', $validated['clock_out_status']);

            $sortBy = $validated['sort_by'] ?? 'work_date';
            $sortDirection = $validated['sort_direction'] ?? 'desc';
            $query->orderBy($sortBy, $sortDirection);
            if ($sortBy !== 'id') { // Tambahan sort untuk konsistensi paginasi
                $query->orderBy('id', $sortDirection);
            }

            $perPage = $validated['per_page'] ?? 15;
            $reports = $query->paginate($perPage);

            return new AttendanceCollection($reports);

        } catch (\Exception $e) {
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
        $dataToUpdate = []; // Array untuk menampung data yang akan diupdate ke service

        // Logika untuk mem-parse waktu (yang dikirim sebagai H:i:s) dan menggabungkannya dengan tanggal kerja
        $baseDateForTime = isset($validatedData['work_date']) ? Carbon::parse($validatedData['work_date']) : $attendance->work_date;

        if (isset($validatedData['work_date'])) {
            $dataToUpdate['work_date'] = $baseDateForTime; // Sudah jadi objek Carbon
        }
        if (isset($validatedData['clock_in_at_time'])) {
            $dataToUpdate['clock_in_at'] = Carbon::parse($baseDateForTime->toDateString() . ' ' . $validatedData['clock_in_at_time']);
        }
        if (isset($validatedData['clock_out_at_time'])) {
            $dataToUpdate['clock_out_at'] = Carbon::parse($baseDateForTime->toDateString() . ' ' . $validatedData['clock_out_at_time']);
            // Handle lintas hari jika clock_out < clock_in pada tanggal yang sama (setelah digabung dengan baseDateForTime)
            $clockInToCompare = $dataToUpdate['clock_in_at'] ?? $attendance->clock_in_at;
            if ($clockInToCompare && $dataToUpdate['clock_out_at']->lt($clockInToCompare)) {
                $dataToUpdate['clock_out_at']->addDay();
            }
        }

        // Salin field lain yang divalidasi dan mungkin diubah
        $otherFields = ['clock_in_status', 'clock_in_notes', 'clock_out_status', 'clock_out_notes', 'work_schedule_id'];
        foreach ($otherFields as $field) {
            if (array_key_exists($field, $validatedData)) {
                $dataToUpdate[$field] = $validatedData[$field];
            }
        }
        // Alasan koreksi wajib dari FormRequest
        $dataToUpdate['admin_correction_notes'] = $validatedData['admin_correction_notes'];

        try {
            $updatedAttendance = $this->attendanceService->correctAttendance($attendance, $dataToUpdate, $admin);
            return response()->json([
                'message' => 'Attendance record corrected successfully.',
                // Eager load relasi yang mungkin relevan untuk ditampilkan kembali
                'data' => new AttendanceResource($updatedAttendance->fresh()->load(['user:id,name', 'workSchedule:id,name', 'correctionLogs.admin:id,name']))
            ]);
        } catch (ValidationException $e) {
            Log::warning("Validation error during attendance correction by admin {$admin->id} for attendance ID {$attendance->id}: " . $e->getMessage(), ['errors' => $e->errors()]);
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->status);
        } catch (\Exception $e) {
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
        // Format agar lebih mudah di frontend: group => { key: value }
        $formattedSettings = $settings->groupBy('group')->mapWithKeys(function ($groupItems, $groupName) {
            return [$groupName => $groupItems->pluck('value', 'key')]; // Accessor di model akan meng-cast value
        });
        return response()->json($formattedSettings);
    }

    /**
     * Memperbarui pengaturan absensi.
     */
    public function updateSettings(UpdateAttendanceSettingsRequest $request): JsonResponse
    {
        $settingsData = $request->validated()['settings']; // Ambil dari 'settings' key yang sudah divalidasi
        $allSettingsInDb = AttendanceSetting::all()->keyBy('key');

        DB::transaction(function () use ($settingsData, $allSettingsInDb) {
            foreach ($settingsData as $key => $value) {
                if ($allSettingsInDb->has($key)) {
                    $settingToUpdate = $allSettingsInDb->get($key);
                    // Mutator di model AttendanceSetting akan menangani casting dan penyimpanan
                    $settingToUpdate->value = $value;
                    $settingToUpdate->save();
                } else {
                    Log::warning("Attempted to update non-existent attendance setting key: {$key}");
                    // Pertimbangkan untuk melempar error atau mengabaikan key yang tidak dikenal
                }
            }
        });

        return response()->json(['message' => 'Attendance settings updated successfully.']);
    }

    // --- QR Code Management (Admin) ---
    public function generateDailyQr(Request $request): JsonResponse // Bisa dibuatkan FormRequest sendiri jika validasi kompleks
    {
        $validated = $request->validate([
            'location_name' => 'sometimes|string|max:100',
            'work_schedule_id' => 'sometimes|nullable|integer|exists:work_schedules,id',
            'additional_payload' => 'sometimes|array'
        ]);
        $locationName = $validated['location_name'] ?? 'Main Office Default';
        $workScheduleId = $validated['work_schedule_id'] ?? null;

        try {
            $qrCodeModel = $this->qrCodeService->generateDailyAttendanceQr($locationName, $workScheduleId, $validated['additional_payload'] ?? []);

            // Data yang akan di-encode ke gambar QR adalah additional_payload dari model QrCode
            $qrImageDataUri = $this->qrCodeService->generateQrCodeImageUri(
                json_encode($qrCodeModel->additional_payload), // Pastikan ini adalah data yang ingin dipindai klien
                "Attendance {$locationName} - " . $qrCodeModel->valid_on_date->format('d M Y')
            );
            return response()->json([
                'message' => 'Daily QR Code generated successfully.',
                'qr_code_data' => $qrCodeModel->toArray(), // Atau gunakan QrCodeResource jika ada
                'qr_code_image_uri' => $qrImageDataUri,
            ]);
        } catch (\Exception $e) {
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
            'qr_code_data' => $qrCodeModel->toArray(), // Atau gunakan QrCodeResource
            'qr_code_image_uri' => $qrImageDataUri,
        ]);
    }

    // --- Work Schedule Management (Admin) ---
    public function listWorkSchedules(Request $request): JsonResponse // Menggunakan JsonResponse untuk konsistensi, bisa juga Resource Collection
    {
        // Tambahkan paginasi jika jumlah jadwal banyak
        $schedules = $this->workScheduleService->getActiveWorkSchedules(); // Hanya yang aktif
        return WorkScheduleResource::collection($schedules)->response();
    }

    public function createWorkSchedule(WorkScheduleRequest $request): JsonResponse // Menggunakan FormRequest
    {
        $schedule = $this->workScheduleService->createWorkSchedule($request->validated());
        return new WorkScheduleResource($schedule->fresh()) // Ambil data terbaru
        ->additional(['message' => 'Work schedule created successfully.'])
            ->response()
            ->setStatusCode(201); // HTTP 201 Created
    }

    public function updateWorkSchedule(WorkScheduleRequest $request, WorkSchedule $workSchedule): JsonResponse // Route Model Binding
    {
        $schedule = $this->workScheduleService->updateWorkSchedule($workSchedule, $request->validated());
        return new WorkScheduleResource($schedule->fresh())
            ->additional(['message' => 'Work schedule updated successfully.'])
            ->response();
    }

    public function assignScheduleToUser(AssignWorkScheduleRequest $request): JsonResponse // Menggunakan FormRequest
    {
        $validatedData = $request->validated();
        $user = User::findOrFail($validatedData['user_id']);
        $workSchedule = WorkSchedule::findOrFail($validatedData['work_schedule_id']);
        $admin = $request->user(); // Admin yang melakukan aksi

        $assignment = $this->workScheduleService->assignScheduleToUser(
            $user,
            $workSchedule,
            $validatedData['effective_start_date'],
            $validatedData['effective_end_date'] ?? null,
            $admin,
            $validatedData['assignment_notes'] ?? null
        );
        // Eager load relasi untuk respons yang lebih informatif
        return new UserWorkScheduleAssignmentResource($assignment->load(['user:id,name', 'workSchedule:id,name', 'assignedBy:id,name']))
            ->additional(['message' => 'Work schedule assigned to user successfully.'])
            ->response()
            ->setStatusCode(201);
    }
}
