<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MaintenanceTicketEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly array $ticket) {}

    public function build(): static
    {
        return $this->subject('Tiket pemeliharaan baru: '.$this->ticket['number'])
            ->html('<h2>Tiket pemeliharaan baru</h2><p><b>Nomor:</b> '.e($this->ticket['number']).'</p><p><b>Mesin:</b> '.e($this->ticket['machine']).'</p><p><b>Plant:</b> '.e($this->ticket['plant']).'</p><p><b>Masalah:</b><br>'.nl2br(e($this->ticket['description'])).'</p>');
    }
}
