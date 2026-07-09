<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WompiService
{
    /**
     * Llave pública saneada: sin espacios, saltos de línea ni comillas
     * pegadas al copiar/pegar en el dashboard de Render.
     */
    public function publicKey(): ?string
    {
        $key = config('services.wompi.public_key');
        $key = trim((string) $key, " \t\n\r\"'");
        return $key !== '' ? $key : null;
    }

    private function secreto(string $nombre): ?string
    {
        $valor = trim((string) config("services.wompi.{$nombre}"), " \t\n\r\"'");
        return $valor !== '' ? $valor : null;
    }

    public function configurado(): bool
    {
        return $this->publicKey() !== null;
    }

    /** true si la llave es de pruebas (pub_test_...). */
    public function esSandbox(): bool
    {
        return str_starts_with((string) $this->publicKey(), 'pub_test');
    }

    /**
     * Verifica contra la API de Wompi que la llave pública corresponde a un
     * comercio válido. Evita mandar al cliente a un checkout roto
     * ("No se pudo cargar la información del undefined").
     * El resultado se cachea 10 minutos. Si la API no responde, no bloquea.
     */
    public function verificarComercio(): array
    {
        $key = $this->publicKey();
        if (! $key) {
            return ['ok' => false, 'error' => 'Sin llave pública configurada.'];
        }

        return Cache::remember("wompi_comercio_{$key}", 600, function () use ($key) {
            $base = $this->esSandbox() ? 'https://sandbox.wompi.co' : 'https://production.wompi.co';
            try {
                $res = Http::timeout(8)->get("{$base}/v1/merchants/{$key}");
                if ($res->successful()) {
                    return ['ok' => true, 'nombre' => $res->json('data.name')];
                }
                Log::warning('Wompi: la llave pública no corresponde a un comercio válido', [
                    'status' => $res->status(), 'sandbox' => $this->esSandbox(),
                ]);
                return ['ok' => false, 'error' => 'Wompi no reconoce la llave pública configurada (revisa WOMPI_PUBLIC_KEY y que sea del ambiente correcto: pub_prod_ para producción).'];
            } catch (\Throwable $e) {
                Log::warning('Wompi: no se pudo verificar el comercio', ['error' => $e->getMessage()]);
                return ['ok' => true, 'nombre' => null]; // no bloquear por un fallo de red
            }
        });
    }

    /**
     * URL del Web Checkout de Wompi.
     * @param int    $montoPesos monto en pesos colombianos (se convierte a centavos)
     * @param string $referencia referencia única (ej. LOGIX-PLAN-15)
     */
    public function checkoutUrl(int $montoPesos, string $referencia): string
    {
        if (! $this->configurado()) {
            return url("/payments/checkout?ref={$referencia}");
        }

        $centavos = $montoPesos * 100;
        $params = [
            'public-key' => $this->publicKey(),
            'currency' => 'COP',
            'amount-in-cents' => $centavos,
            'reference' => $referencia,
        ];

        // A dónde vuelve el cliente al terminar el pago (obligatorio para una buena UX;
        // si no está configurado, se usa la pantalla de planes del frontend).
        $redirect = $this->secreto('redirect_url')
            ?? rtrim((string) env('FRONTEND_URL', ''), '/');
        if ($redirect) {
            $params['redirect-url'] = str_contains($redirect, '://') ? $redirect : "https://{$redirect}";
        }

        $query = http_build_query($params);

        // Firma de integridad: sha256(referencia + centavos + moneda + secreto).
        // El nombre del parámetro lleva ':' literal (como en la documentación de
        // Wompi); http_build_query lo codificaría como %3A, así que va aparte.
        if ($secreto = $this->secreto('integrity_secret')) {
            $query .= '&signature:integrity=' . hash('sha256', $referencia . $centavos . 'COP' . $secreto);
        } else {
            Log::warning('Wompi: falta WOMPI_INTEGRITY_SECRET; el checkout puede fallar si el comercio exige firma de integridad.');
        }

        return 'https://checkout.wompi.co/p/?' . $query;
    }

    public function verificarEvento(array $payload): bool
    {
        $secreto = $this->secreto('events_secret');
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
