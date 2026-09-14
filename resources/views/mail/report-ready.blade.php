{{ trans('messages.report_mail_greeting') }}

{{ trans('messages.report_mail_intro', ['name' => $reportName]) }}

- {{ trans('messages.report_sheet_keywords') }}: {{ $keywords }}
- {{ trans('messages.report_sheet_period') }}: {{ $periodLabel }}
- {{ trans('messages.report_sheet_from') }}: {{ $from }}
- {{ trans('messages.report_sheet_to') }}: {{ $to }}
- {{ trans('messages.report_sheet_total') }}: {{ $totalMatched }}
- {{ trans('messages.report_mail_days') }}: {{ $days }}

{{ trans('messages.report_mail_attachment_note') }}

{{ trans('messages.report_mail_signature') }}
