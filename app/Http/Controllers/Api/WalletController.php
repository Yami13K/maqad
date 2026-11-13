<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Wallet\Queries\WalletStatement;
use App\Http\Middleware\IdentifyStudent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WalletController
{
    /** E3: GET /wallets/me */
    public function show(Request $request, WalletStatement $statement): JsonResponse
    {
        return new JsonResponse($statement->forStudent($request->attributes->get(IdentifyStudent::ATTRIBUTE)));
    }
}
