<?php

declare(strict_types=1);

use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** One error shape for every failure: {"error": {"code": "...", "message": "..."}}. */
$error = static fn (string $code, string $message, int $status): JsonResponse => new JsonResponse(
    ['error' => ['code' => $code, 'message' => $message]],
    $status,
);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) use ($error): void {
        $exceptions->shouldRenderJsonWhen(static fn (): bool => true);

        // Domain exceptions carry their own status; HTTP mapping happens only here.
        $exceptions->render(static fn (DomainException $e): JsonResponse => $error($e->errorCode(), $e->getMessage(), $e->status()));

        $exceptions->render(static fn (ValidationException $e): JsonResponse => $error('invalid_request', $e->validator->errors()->first(), 422));

        $exceptions->render(static function (NotFoundHttpException $e) use ($error): JsonResponse {
            return $e->getPrevious() instanceof ModelNotFoundException
                ? $error('not_found', 'Resource not found.', 404)
                : $error('route_not_found', 'No such endpoint.', 404);
        });

        $exceptions->render(static function (HttpExceptionInterface $e, Request $request) use ($error): JsonResponse {
            return $error('http_error', $e->getMessage() ?: 'Request failed.', $e->getStatusCode());
        });
    })->create();
