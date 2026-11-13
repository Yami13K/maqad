<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\Exceptions\UnknownStudent;
use App\Domain\Student\Models\Student;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The task's stand-in for auth: X-Student-Id names the caller. Resolved once at the edge
 * and stored on the request; nothing deeper reads the header.
 */
final class IdentifyStudent
{
    public const string ATTRIBUTE = 'student_id';

    public function handle(Request $request, Closure $next): Response
    {
        $raw = (string) $request->header('X-Student-Id', '');

        if (preg_match('/^[1-9]\d{0,17}$/', $raw) !== 1 || ! Student::query()->whereKey((int) $raw)->exists()) {
            throw new UnknownStudent;
        }

        $request->attributes->set(self::ATTRIBUTE, (int) $raw);

        return $next($request);
    }
}
