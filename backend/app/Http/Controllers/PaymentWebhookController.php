<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Plan;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    /**
     * Endpoint genérico para recibir webhooks de pasarelas.
     * Ejemplo de payload aceptado (flexible): { status: 'SUCCESS', user_email: 'x@x.com', plan: 'Medio' }
     */
    public function __construct(private CreditService $creditService) {}

    public function handle(Request $request, string $provider)
    {
        $raw = $request->getContent();
        $data = $request->all();

        // Verificación de firma (opcional): busca secret en env PAYMENT_WEBHOOK_SECRET_{PROVIDER}
        $secretKey = env('PAYMENT_WEBHOOK_SECRET_' . strtoupper($provider));
        $sigHeader = $request->header('X-Signature') ?? $request->header('X-Signature-256') ?? $request->header('Signature');
        if ($secretKey && $sigHeader) {
            $computed = hash_hmac('sha256', $raw, $secretKey);
            if (! hash_equals($computed, $sigHeader)) {
                Log::warning("Webhook signature mismatch for provider {$provider}");
                return response()->json(['message' => 'Firma inválida.'], 403);
            }
        }

        $status = $data['status'] ?? $data['estado'] ?? null;
        $email = $data['user_email'] ?? $data['email'] ?? ($data['metadata']['email'] ?? null);
        $planName = $data['plan'] ?? $data['metadata']['plan'] ?? null;

        // Idempotencia: intentar extraer un ID del evento/transaction
        $providerEventId = $data['id'] ?? $data['event_id'] ?? $data['data']['id'] ?? $data['data']['object'] ?? null;
        if (is_array($providerEventId)) {
            $providerEventId = $providerEventId['id'] ?? null;
        }

        if (! $status) {
            return response()->json(['message' => 'Payload inválido.'], 400);
        }

        // Solo procesar pagos exitosos
        // Guardar el evento raw antes de procesar para auditoría / idempotencia
        $existing = null;
        if ($providerEventId) {
            $existing = PaymentTransaction::where('provider', $provider)->where('provider_event_id', $providerEventId)->first();
        }

        if ($existing && $existing->processed_at) {
            // Ya procesado
            return response()->json(['message' => 'Evento ya procesado.'], 200);
        }

        $tx = $existing ?? PaymentTransaction::create([
            'provider' => $provider,
            'provider_event_id' => $providerEventId,
            'status' => $status,
            'payload' => $data,
            'amount' => null,
            'currency' => $data['currency'] ?? null,
        ]);

        if (in_array(strtoupper($status), ['SUCCESS', 'APPROVED', 'PAID'])) {
            // Intentar buscar usuario por email primero
            $user = null;
            if ($email) {
                $user = User::where('email', $email)->first();
            }

            // Si la pasarela mandó un user_id en metadata
            if (! $user && ($data['user_id'] ?? $data['metadata']['user_id'] ?? null)) {
                $uid = $data['user_id'] ?? $data['metadata']['user_id'];
                $user = User::find($uid);
            }

            if (! $user) {
                // marcar como procesado parcialmente y guardar
                $tx->processed_at = now();
                $tx->save();
                return response()->json(['message' => 'Usuario no encontrado; requiere metadata con email o user_id.'], 404);
            }

            if (! $planName) {
                // Si no envían plan, asumimos que metadata.plan_id puede existir
                $planName = $data['plan_name'] ?? null;
            }

            if ($planName) {
                $plan = Plan::where('nombre', $planName)->first();
                if ($plan) {
                    $old = $user->plan?->nombre;
                    $user->update(['plan_id' => $plan->id]);
                    Auditoria::registrar(null, $user->id, 'PAGO', strtoupper($provider) . '_WEBHOOK', $old, $plan->nombre);
                    $tx->user_id = $user->id;
                    $tx->plan_id = $plan->id;
                }
            }

            // Soporte compra de créditos: si el payload incluye credit_package_id
            $creditPackageId = $data['credit_package_id'] ?? $data['metadata']['credit_package_id'] ?? $data['package_id'] ?? null;
            if ($creditPackageId) {
                // Determine module & credits via CreditPackage model
                try {
                    $package = \App\Models\CreditPackage::find($creditPackageId);
                    if ($package) {
                        // Acredita créditos al usuario
                        $this->creditService->creditUser($user->id, $package->module, (int) $package->credits, $tx->id, $package->id, 'Compra de créditos via webhook');
                        Auditoria::registrar(null, $user->id, 'PAGO', strtoupper($provider) . '_CREDIT', null, "+{$package->credits} {$package->module}");
                    }
                } catch (\Throwable $e) {
                    Log::error('Error al acreditar créditos: ' . $e->getMessage());
                }
            }

            // Intentar extraer monto
            $amount = $data['amount'] ?? $data['amount_in_cents'] ?? ($data['data']['amount'] ?? null);
            if ($amount !== null) {
                // normalizar si viene en cents
                if (is_numeric($amount) && (int)$amount > 1000000) {
                    // heurística: si es entero grande, puede venir en cents -> convertir
                    $tx->amount = ((float)$amount) / 100;
                } else {
                    $tx->amount = (float)$amount;
                }
            }

            $tx->status = $status;
            $tx->processed_at = now();
            $tx->payload = $data;
            $tx->save();

            return response()->json(['message' => 'Webhook procesado.'], 200);
        }

        // Evento no exitoso: registrar y devolver OK
        $tx->status = $status;
        $tx->payload = $data;
        $tx->processed_at = now();
        $tx->save();
        return response()->json(['message' => 'Evento registrado (no exitoso).'], 200);
    }
}
