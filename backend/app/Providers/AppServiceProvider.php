<?php

namespace App\Providers;

use App\Mail\Transport\BrevoApiTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Transporte de correo por la API HTTP de Brevo (Render bloquea SMTP saliente).
        Mail::extend('brevo-api', function () {
            return new BrevoApiTransport((string) config('services.brevo.key'));
        });

        // Si hay llave de Brevo configurada, el correo sale por su API HTTP
        // automáticamente (sin tener que cambiar MAIL_MAILER en el servidor).
        if (config('services.brevo.key') && config('mail.default') !== 'brevo') {
            config(['mail.default' => 'brevo']);
        }
    }
}
