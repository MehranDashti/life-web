<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Throwable;
use Illuminate\Http\JsonResponse;
use Mehrand\ApiResponse\Responses\FailureResponse;
use Mehrand\ApiResponse\Responses\SuccessResponse;

/**
 * The base every controller extends.
 *
 * Controllers never build a JsonResponse by hand — every response leaves through
 * one of these two methods, so the envelope is identical across the whole API:
 *
 *   success: {"success": true,  "code": 200, "message": "...", "data":  ...}
 *   failure: {"success": false, "code": 422, "message": "...", "error": ...}
 *
 * The response classes are constructed directly rather than through
 * ApiResponse::successResponse(), which resolves class names via __callStatic
 * and is therefore invisible to static analysis.
 */
abstract class Controller
{
    protected function successResponse(string $message, mixed $data = []): JsonResponse
    {
        return (new SuccessResponse)
            ->setMessage($message)
            ->setResponseValue($data)
            ->render();
    }

    /**
     * The status code comes from the exception when it carries a usable one —
     * services throw `RuntimeException($message, Response::HTTP_*)` for exactly this.
     */
    protected function failureResponse(string $message, ?Throwable $exception = null): JsonResponse
    {
        $response = (new FailureResponse)->setMessage($message);

        if ($exception instanceof Throwable && $exception->getCode() > 0) {
            $response->setCode((int) $exception->getCode());
        }

        return $response->render();
    }
}
