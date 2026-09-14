<?php

declare(strict_types=1);

namespace App\Mail;

use Morilog\Jalali\Jalalian;
use App\Models\Report\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use App\Models\Report\ReportRun;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;

/**
 * Delivers a generated report to its owner.
 *
 * The task states that email delivery need not be fully implemented and that the
 * message's appearance does not matter, so this is deliberately plain. The default
 * mailer is `log`, which keeps the whole flow observable — the mailable is built,
 * the attachment is resolved, the send is recorded — without needing SMTP. Pointing
 * MAIL_MAILER at a real transport is the only change required to actually send.
 */
class ReportReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Report $report,
        public readonly ReportRun $run,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('messages.report_mail_subject', ['name' => $this->report->name]),
        );
    }

    public function content(): Content
    {
        $timezone = (string) config('search.histogram.timezone', 'Asia/Tehran');

        return new Content(
            text: 'mail.report-ready',
            with: [
                'reportName' => $this->report->name,
                'keywords' => implode('، ', $this->report->keywords),
                'periodLabel' => $this->report->period->label(),
                'from' => $this->formatted($this->run->period_start, $timezone),
                'to' => $this->formatted($this->run->period_end, $timezone),
                'totalMatched' => $this->run->total_matched,
                'days' => $this->run->rows,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->run->hasFile()) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('reports', (string) $this->run->file_path)
                ->as(basename((string) $this->run->file_path))
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }

    private function formatted(Carbon $moment, string $timezone): string
    {
        $local = $moment->copy()->timezone($timezone);

        return $local->format('Y-m-d').' ('.Jalalian::fromCarbon($local)->format('Y/m/d').')';
    }
}
