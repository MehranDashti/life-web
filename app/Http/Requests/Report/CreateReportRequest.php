<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use App\Enums\ReportPeriod;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class CreateReportRequest extends FormRequest
{
    /**
     * The number of keywords a single report may carry. Each one becomes a
     * `multi_match` clause, so an unbounded list is an unbounded query — a cheap
     * way for one subscription to make every scheduled run expensive.
     */
    public const MAX_KEYWORDS = 10;

    public const MAX_KEYWORD_LENGTH = 64;

    /**
     * Gating is route middleware; a FormRequest only validates.
     */
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
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'period' => ['required', 'string', Rule::in(ReportPeriod::values())],

            'keywords' => ['required', 'array', 'min:1', 'max:'.self::MAX_KEYWORDS],
            'keywords.*' => ['required', 'string', 'min:2', 'max:'.self::MAX_KEYWORD_LENGTH],

            'news_agency_ids' => ['sometimes', 'nullable', 'array', 'max:20'],
            'news_agency_ids.*' => ['required', 'string', 'max:64'],

            'match_all_keywords' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => trans('messages.attribute_report_name'),
            'period' => trans('messages.attribute_report_period'),
            'keywords' => trans('messages.attribute_report_keywords'),
        ];
    }
}
