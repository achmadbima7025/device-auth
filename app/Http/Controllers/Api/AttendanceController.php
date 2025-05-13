<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceScanRequest;
use App\Http\Resources\AttendanceCollection;
use App\Http\Resources\AttendanceResource;
use App\Services\Attendance\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function __construct(protected readonly AttendanceService $attendanceService)
    {
    }

    /**
     * Menerima data scan QR Code dan memproses absensi.
     *
     * @param AttendanceScanRequest $request
     * @return JsonResponse
     */
    public function scan(AttendanceScanRequest $request): JsonResponse
    {
        try {
            $user = $request->user(); // Pengguna yang terautentikasi (dari Sanctum)
            $deviceIdentifier = $request->header('X-Device-ID'); // Identifier perangkat dari header

            // Mengambil data yang sudah divalidasi oleh AttendanceScanRequest
            $validatedData = $request->validated();

            $attendance = $this->attendanceService->processScan(
                $user,
                $validatedData['qr_payload'],
                $validatedData['client_timestamp'],
                $validatedData['location'] ?? null, // Opsional, jadi gunakan null coalescing atau isset
                $deviceIdentifier
            );

            return response()->json([
                'message' => $attendance->success_message ?? 'Attendance processed successfully.', // success_message di-set di service
                'data' => new AttendanceResource($attendance->loadMissing('workSchedule', 'clockInDevice', 'clockOutDevice'))
            ]);

        } catch (ValidationException $e) {
            // Log error validasi dari service
            Log::warning("Attendance validation failed for user {$request->user()->id}: " . $e->getMessage(), [
                'errors' => $e->errors(),
                'request_data' => $request->except('password') // Jangan log password
            ]);
            return response()->json([
                'message' => $e->getMessage(), // Pesan utama dari exception
                'errors' => $e->errors(),   // Detail error per field
            ], $e->status); // Status HTTP dari ValidationException (biasanya 422 atau 403 dari service)
        } catch (\Exception $e) {
            Log::error("Error processing attendance scan for user {$request->user()->id}: {$e->getMessage()}", [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(), // Hati-hati dengan trace di produksi, bisa sangat panjang
                'request_data' => $request->except('password')
            ]);
            return response()->json(['message' => 'An internal server error occurred while processing attendance.'], 500);
        }
    }

    /**
     * Mengambil riwayat absensi untuk pengguna yang terautentikasi.
     *
     * @param Request $request
     * @return AttendanceCollection|JsonResponse
     */
    public function getHistory(Request $request): AttendanceCollection|JsonResponse
    {
        // Validasi bisa juga dipindahkan ke FormRequest jika lebih kompleks
        $validated = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
            'start_date' => 'sometimes|date_format:Y-m-d',
            'end_date' => 'sometimes|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        try {
            $user = $request->user();
            $perPage = $validated['per_page'] ?? 15;
            $startDate = $validated['start_date'] ?? null;
            $endDate = $validated['end_date'] ?? null;

            $history = $this->attendanceService->getAttendanceHistoryForUser($user, $perPage, $startDate, $endDate);

            return new AttendanceCollection($history);

        } catch (\Exception $e) {
            Log::error("Error fetching attendance history for user {$request->user()->id}: {$e->getMessage()}", [
                'exception' => get_class($e),
            ]);
            return response()->json(['message' => 'An internal server error occurred while fetching history.'], 500);
        }
    }
}
