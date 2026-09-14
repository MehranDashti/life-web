<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;

/**
 * Optional explicit window for an on-demand run. Omitted, the report's own period
 * decides the window, exactly as the scheduler would.
 */
class RunReportRequest extends FormRequest
{
    /**
     * Guards against an on-demand run asking for a range so wide it would scan
     * years of indices in a request-path context.
     */
    public const MAX_WINDOW_DAYS = 366;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'required_with:to', 'date'],
            'to' => ['sometimes', 'required_with:from', 'date', 'after_or_equal:from',
                'before_or_equal:'.now()->addDay()->toDateString()],
        ];
    }

    /**
     * @return array<int, callable(Validator):void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->filled('from') || ! $this->filled('to')) {
                    return;
                }

                $days = now()->parse((string) $this->input('from'))
                    ->diffInDays(now()->parse((string) $this->input('to')));

                if ($days > self::MAX_WINDOW_DAYS) {
                    $validator->errors()->add('to', trans('messages.report_window_too_wide', [
                        'days' => self::MAX_WINDOW_DAYS,
                    ]));
                }
            },
        ];
    }
}
