<?php

namespace App\Services;

/**
 * Integración con Wompi (Web Checkout).
 *
 * Genera el enlace de pago hospedado por Wompi, donde el cliente elige
 * PSE, Nequi, tarjeta o Bancolombia. El dinero llega a la cuenta bancaria
 * configurada en el panel del comercio (comercios.wompi.co).
 *
 * Si no hay llaves configuradas (WOMPI_PUBLIC_KEY), se devuelve una URL
 * interna de prueba para no romper el flujo en desarrollo.
 */
class WompiService
{
    public function configurado(): bool
    {
        return ! empty(config('services.wompi.public_key'));
    }

    /**
     * URL del checkout de Wompi.
     *
     * @param int    $montoPesos monto en pesos colombianos (se convierte a centavos)
     * @param string $referencia referencia única de la transacción (ej. LOGIX-PLAN-15)
     */
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

        // Firma de integridad: sha256(referencia + monto_en_centavos + moneda + secreto)
        if ($secreto = config('services.wompi.integrity_secret')) {
            $params['signature:integrity'] = hash('sha256', $referencia . $centavos . 'COP' . $secreto);
        }

        return 'https://checkout.wompi.co/p/?' . http_build_query($params);
    }

    /**
     * Verifica el checksum de un evento (webhook) de Wompi.
     * checksum = sha256(transaction.id + transaction.status + transaction.amount_in_cents + timestamp + events_secret)
     * Si no hay secreto configurado, no se valida (retorna true).
     */
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
