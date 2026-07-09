<?php

namespace App\Services;

class WompiService
{
    public function configurado(): bool
    {
        return ! empty(config('services.wompi.public_key'));
    }

    public function checkoutUrl(int $montoPesos, string $referencia): string
    {
        if (! $this->configurado()) {
            return url("/payments/checkout?ref={$referencia}");
        }

        $centavos = $montoPesos * 100;
        $params = [
            'public-key' => config('services.wompi.public_key'),
            'currency' => 'COP',
            'amount-in-cents' => $centavos,
            'reference' => $referencia,
        ];

        if ($redirect = config('services.wompi.redirect_url')) {
            $params['redirect-url'] = $redirect;
        }

        if ($secreto = config('services.wompi.integrity_secret')) {
            $params['signature:integrity'] = hash('sha256', $referencia . $centavos . 'COP' . $secreto);
        }

        return 'https://checkout.wompi.co/p/?' . http_build_query($params);
    }

    public function verificarEvento(array $payload): bool
    {
        $secreto = config('services.wompi.events_secret');
        if (! $secreto) {
            return true;
        }

        $tx = $payload['data']['transaction'] ?? [];
        $checksum = $payload['signature']['checksum'] ?? '';
        $timestamp = $payload['timestamp'] ?? '';

        $esperado = hash('sha256',
            ($tx['id'] ?? '') . ($tx['status'] ?? '') . ($tx['amount_in_cents'] ?? '') . $timestamp . $secreto
        );

        return hash_equals($esperado, $checksum);
    }
}