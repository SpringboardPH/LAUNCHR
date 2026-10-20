<?php

namespace App\Mail;

use App\Models\BirFormDraft;
use App\Models\SystemSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells an employee their 2316 is signed by the employer and waiting for them (contract:
 * docs/bir-api-contract.md). Only a link to "Forms to sign"; no amounts, no attachment.
 */
class BirFormToSignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public BirFormDraft $draft)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your BIR Form 2316 for {$this->draft->period} is ready to sign",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.bir-form-to-sign',
            with: [
                'name' => $this->draft->employee?->first_name,
                'year' => $this->draft->period,
                'link' => rtrim(config('app.frontend_url'), '/') . '/my-bir-forms',
                'logo' => SystemSettings::get('system_logo', 'launchr_black.svg'),
            ],
        );
    }
}
