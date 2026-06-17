<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo genérico de Logix basado en una plantilla.
 * Sirve para: bienvenida, confirmación de cita, recordatorio, factura, etc.
 */
class CorreoLogix extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $asunto,
        public string $titulo,
        public array $lineas = [],
        public ?string $adjuntoPath = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->asunto);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.generico', with: [
            'titulo' => $this->titulo,
            'lineas' => $this->lineas,
        ]);
    }

    public function attachments(): array
    {
        if ($this->adjuntoPath && file_exists($this->adjuntoPath)) {
            return [\Illuminate\Mail\Mailables\Attachment::fromPath($this->adjuntoPath)];
        }
        return [];
    }
}
