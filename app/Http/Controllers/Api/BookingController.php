<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Booking\Actions\BookSeatAction;
use App\Domain\Booking\Actions\CancelBookingAction;
use App\Domain\Booking\Data\BookingResult;
use App\Domain\Booking\Data\CancelBookingData;
use App\Http\Middleware\IdentifyStudent;
use App\Http\Requests\BookSeatRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BookingController
{
    /** E1: POST /sessions/{session}/bookings */
    public function store(BookSeatRequest $request, int $session, BookSeatAction $action): JsonResponse
    {
        return $this->respond($action->execute($request->toData($session)));
    }

    /** E2: DELETE /bookings/{booking} */
    public function destroy(Request $request, int $booking, CancelBookingAction $action): JsonResponse
    {
        return $this->respond($action->execute(new CancelBookingData(
            studentId: $request->attributes->get(IdentifyStudent::ATTRIBUTE),
            bookingId: $booking,
        )));
    }

    private function respond(BookingResult $result): JsonResponse
    {
        $headers = $result->replayed ? ['Idempotent-Replayed' => 'true'] : [];

        return new JsonResponse($result->toArray(), $result->httpStatus, $headers);
    }
}
