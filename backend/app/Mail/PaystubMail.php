<?php

namespace App\Mail;

use App\Models\Payroll;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;

class PaystubMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Payroll $payroll, public string $attachmentPath)
    {
    }

    public function envelope(): Envelope
    {
        $employee = $this->payroll->employee;
        $period = $this->payroll->cutoff_start->format('M d') . ' - ' . $this->payroll->cutoff_end->format('M d, Y');
        
        return new Envelope(
            subject: "Your Paystub - {$period} - Springboard HR",
        );
    }

    public function content(): Content
    {
        $employee = $this->payroll->employee;
        $period = $this->payroll->cutoff_start->format('F d, Y') . ' to ' . $this->payroll->cutoff_end->format('F d, Y');
        
        return new Content(
            view: 'emails.paystub',
            with: [
                'employee' => $employee,
                'payroll' => $this->payroll,
                'period' => $period,
                'gross' => $this->payroll->gross_pay,
                'net' => $this->payroll->net_pay,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->attachmentPath)
                ->as('paystub.xlsx')
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
