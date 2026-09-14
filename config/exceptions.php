<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Auth\AuthenticationException;
use App\Exceptions\SearchUnavailableException;
use Illuminate\Validation\ValidationException;
use App\Exceptions\CustomUnauthorizedException;
use App\Exceptions\CustomAuthenticationException;
use App\Exceptions\CustomMethodNotAllowedException;
use App\Exceptions\CustomSearchUnavailableException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Mehrand\ApiExceptions\Exceptions\CustomQueryException;
use Mehrand\ApiExceptions\Exceptions\CustomDefaultException;
use Mehrand\ApiExceptions\Exceptions\CustomValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Mehrand\ApiExceptions\Exceptions\CustomModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/*
|--------------------------------------------------------------------------
| Exception → response mapping
|--------------------------------------------------------------------------
|
| Mehrand\ApiExceptions\Handlers\ApiException looks the thrown exception up in
| this list by its EXACT class name (get_class), not by instanceof — so parent
| classes and interfaces do not match. Anything unlisted falls through to
| CustomDefaultException. Exceptions that can only be matched by interface
| (e.g. Elasticsearch's) are normalised in bootstrap/app.php before they reach
| here.
|
*/

return [
    'list' => [
        QueryException::class => CustomQueryException::class,
        RuntimeException::class => CustomDefaultException::class,
        Exception::class => CustomDefaultException::class,
        ValidationException::class => CustomValidationException::class,
        NotFoundHttpException::class => CustomModelNotFoundException::class,
        ModelNotFoundException::class => CustomModelNotFoundException::class,
        MethodNotAllowedHttpException::class => CustomMethodNotAllowedException::class,
        AuthenticationException::class => CustomAuthenticationException::class,
        UnauthorizedHttpException::class => CustomUnauthorizedException::class,
        SearchUnavailableException::class => CustomSearchUnavailableException::class,
    ],
];
