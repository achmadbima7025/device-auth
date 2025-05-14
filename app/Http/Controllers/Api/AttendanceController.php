<?php

namespace App\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Http\Requests\AttendanceScanRequest;
use App\Http\Resources\AttendanceCollection;
use App\Services\Attendance\AttendanceService;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function __construct(protected readonly AttendanceService $attendanceService)
    {
    }

    public function scan(AttendanceScanRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $deviceIdentifier = $request->header('X-Device-ID');
            $validatedData = $request->validated();

            $result = $this->attendanceService->processScan( // Menangkap hasil sebagai array
                $user,
                $validatedData['qr_payload'],
                $validatedData['client_timestamp'],
                $validatedData['location'] ?? null,
                $deviceIdentifier
            );

            return response()->json([
                'message' => $result['message'], // Menggunakan pesan dari service
                'data' => new AttendanceResource($result['attendance']->loadMissing('workSchedule', 'clockInDevice', 'clockOutDevice')),
            ]);

        } catch (ValidationException $e) {
            Log::warning("Attendance validation failed for user {$request->user()->id}: " . $e->getMessage(), [
                'errors' => $e->errors(),
                'request_data' => $request->except('password'),
            ]);
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->status);
        } catch (Exception $e) {
            Log::error("Error processing attendance scan for user {$request->user()->id}: {$e->getMessage()}", [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except('password'),
            ]);
            return response()->json(['message' => 'An internal server error occurred while processing attendance.'], 500);
        }
    }

    public function getHistory(Request $request): AttendanceCollection|JsonResponse
    {
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

        } catch (Exception $e) {
            Log::error("Error fetching attendance history for user {$request->user()->id}: {$e->getMessage()}", [
                'exception' => get_class($e),
            ]);
            return response()->json(['message' => 'An internal server error occurred while fetching history.'], 500);
        }
    }
}
