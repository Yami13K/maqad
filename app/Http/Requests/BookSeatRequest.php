<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Booking\Data\BookSeatData;
use App\Http\Middleware\IdentifyStudent;
use Illuminate\Foundation\Http\FormRequest;

/** E1 boundary: validates the Idempotency-Key header and maps the request to a DTO. */
final class BookSeatRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['idempotency_key' => ['required', 'string', 'max:255']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'idempotency_key.*' => 'The Idempotency-Key header is required (1 to 255 characters).',
        ];
    }

    public function toData(int $sessionId): BookSeatData
    {
        return new BookSeatData(
            studentId: $this->attributes->get(IdentifyStudent::ATTRIBUTE),
            sessionId: $sessionId,
            idempotencyKey: $this->validated('idempotency_key'),
            // Method, path and body: the same key on another session or body is a different request (422).
            requestHash: hash('sha256', $this->method().' '.$this->path().' '.$this->getContent()),
        );
    }
}
