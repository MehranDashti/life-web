<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| User-facing messages (Persian — the default locale)
|--------------------------------------------------------------------------
|
| Every user-facing string in a controller, service, or mediator goes through
| trans('messages.*'). Never inline a literal Persian or English string.
|
*/

return [
    'action_successfully_done' => 'عملیات با موفقیت انجام شد.',
    'login_successful' => 'ورود با موفقیت انجام شد.',
    'logout_successful' => 'خروج با موفقیت انجام شد.',
    'invalid_username_or_password' => 'نام کاربری یا رمز عبور نادرست است.',
    'user_is_unauthenticated' => 'برای دسترسی به این بخش باید وارد شوید.',
    'model_not_found' => 'مورد درخواستی یافت نشد.',
    'resource_not_owned' => 'این مورد متعلق به شما نیست.',
    'search_unavailable' => 'سرویس جست‌وجو در دسترس نیست. لطفاً بعداً تلاش کنید.',
    'period_daily' => 'روزانه',
    'period_weekly' => 'هفتگی',
    'report_created' => 'گزارش با موفقیت ایجاد شد.',
    'report_generated' => 'گزارش با موفقیت تولید شد.',
    'report_run_not_created' => 'اجرای گزارش ثبت نشد؛ احتمالاً همین بازه توسط پردازش دیگری در حال انجام است.',
    'report_file_not_ready' => 'فایل این اجرا هنوز آماده نیست.',
    'report_window_too_wide' => 'بازه درخواستی نباید بیش از :days روز باشد.',
    'attribute_report_name' => 'نام گزارش',
    'attribute_report_period' => 'دوره زمانی',
    'attribute_report_keywords' => 'کلمات کلیدی',
    'report_sheet_title' => 'نام گزارش',
    'report_sheet_keywords' => 'کلمات کلیدی',
    'report_sheet_period' => 'دوره زمانی',
    'report_sheet_from' => 'از تاریخ',
    'report_sheet_to' => 'تا تاریخ',
    'report_sheet_total' => 'مجموع پست‌های منطبق',
    'report_sheet_generated_at' => 'زمان تولید',
    'report_sheet_date_gregorian' => 'تاریخ میلادی',
    'report_sheet_date_jalali' => 'تاریخ شمسی',
    'report_sheet_count' => 'تعداد پست',
    'report_mail_subject' => 'گزارش دوره‌ای: :name',
    'report_mail_greeting' => 'سلام،',
    'report_mail_intro' => 'گزارش دوره‌ای «:name» آماده شد.',
    'report_mail_days' => 'تعداد روزهای گزارش',
    'report_mail_attachment_note' => 'فایل اکسل حاوی هیستوگرام انتشار روزانه، پیوست شده است.',
    'report_mail_signature' => 'لایف وب',
];
