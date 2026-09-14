<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Exception messages (Persian)
|--------------------------------------------------------------------------
|
| Consumed by mehrand/api-exceptions via trans('errors.*'). The package ships
| the same keys under its own `errors::` namespace, but its exception classes
| call the UNnamespaced key — so these application-level files are what actually
| resolve. Without them the API returns literal strings like "errors.validation".
|
*/

return [
    'unauthenticated' => 'برای دسترسی به این بخش باید وارد شوید.',
    'unauthorized' => 'شما اجازه دسترسی به این بخش را ندارید.',
    'default' => 'خطایی رخ داده است. لطفاً دوباره تلاش کنید.',
    'unexpected' => 'خطای غیرمنتظره‌ای رخ داده است.',
    'validation' => 'اطلاعات ارسالی معتبر نیست.',
    'model_not_found' => 'مورد درخواستی یافت نشد.',
    'not_found' => 'یافت نشد.',
    'route_not_found' => 'آدرس درخواستی یافت نشد.',
    'method_not_allowed' => 'این متد برای آدرس درخواستی مجاز نیست.',
];
