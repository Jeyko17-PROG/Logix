<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Plan;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Notificador;
use App\Services\WompiService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PaymentWebhookController extends Controller
{
    /**
     * Endpoint genérico para recibir webhooks de pasarelas.
     * Ejemplo de payload aceptado (flexible): { status: 'SUCCESS', user_email: 'x@x.com', plan: 'Medio' }
     */
    public function __construct(
        private CreditService $creditService,
        private Notificador $notificador,
        private WompiService $wompi,
    ) {}

    public function handle(Request $request, string $provider)
    {
        $raw = $request->getContent();
        $data = $request->all();

        // Wompi envía los datos anidados en data.transaction: se convierten
        // al formato genérico que procesa el resto de este método.
        if (strtolower($provider) === 'wompi') {
            $data = $this->normalizarWompi($data);
            if ($data === null) {
                return response()->json(['message' => 'Evento de Wompi inválido o firma incorrecta.'], 403);
            }
        }

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
                    // Renueva la membresía un mes y reactiva la cuenta si estaba bloqueada.
                    $user->renovarMembresia();
                    Auditoria::registrar(null, $user->id, 'PAGO', strtoupper($provider) . '_WEBHOOK', $old, $plan->nombre);
                    $tx->user_id = $user->id;
                    $tx->plan_id = $plan->id;

                    // Alerta en tiempo real dentro del software confirmando el pago.
                    $this->notificador->aUsuario($user->id, 'PAGO',
                        'Pago de membresía confirmado',
                        "Tu plan {$plan->nombre} quedó activo hasta el {$user->membresia_vence_at?->format('d/m/Y')}. ¡Gracias por tu pago!");

                    // Facturación automática: recibo PDF de la suscripción al correo del cliente.
                    $this->enviarReciboPago($user, "Suscripción mensual - Plan {$plan->nombre}",
                        (float) ($tx->amount ?? $plan->precio_mensual), $tx);
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

                        // Alerta en tiempo real: recarga de saldo confirmada.
                        $this->notificador->aUsuario($user->id, 'PAGO',
                            'Recarga de saldo confirmada',
                            "Se acreditaron {$package->credits} créditos de {$package->module} a tu billetera.");

                        // Facturación automática: recibo PDF de la recarga al correo del cliente.
                        $this->enviarReciboPago($user, "Recarga de saldo - {$package->name}",
                            (float) ($tx->amount ?? $package->price_cop), $tx);
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

    /**
     * Convierte el evento de Wompi (transaction.updated) al formato genérico.
     * Recupera usuario/plan/paquete desde la PaymentTransaction local usando
     * la referencia (LOGIX-PLAN-{id} o LOGIX-CRED-{id}) creada en el checkout.
     * Devuelve null si la firma del evento no es válida.
     */
    private function normalizarWompi(array $payload): ?array
    {
        if (! $this->wompi->verificarEvento($payload)) {
            return null;
        }

        $txWompi = $payload['data']['transaction'] ?? [];
        $referencia = (string) ($txWompi['reference'] ?? '');

        $normalizado = [
            'status' => $txWompi['status'] ?? null, // APPROVED | DECLINED | ERROR | VOIDED
            'id' => $txWompi['id'] ?? null,
            'amount' => isset($txWompi['amount_in_cents']) ? ((int) $txWompi['amount_in_cents']) / 100 : null,
            'currency' => $txWompi['currency'] ?? 'COP',
            'user_email' => $txWompi['customer_email'] ?? null,
            'wompi_reference' => $referencia,
        ];

        // Busca la transacción local creada al abrir el checkout.
        if (preg_match('/^LOGIX-(PLAN|CRED)-(\d+)$/', $referencia, $m)) {
            $local = PaymentTransaction::find((int) $m[2]);
            if ($local) {
                $normalizado['user_id'] = $local->user_id ?? ($local->payload['user_id'] ?? null);
                if ($m[1] === 'PLAN') {
                    $normalizado['plan'] = $local->payload['plan'] ?? $local->plan?->nombre;
                } else {
                    $normalizado['credit_package_id'] = $local->payload['package_id'] ?? null;
                }
            }
        }

        return $normalizado;
    }

    /**
     * Facturación automática de la plataforma: genera un recibo PDF por el pago
     * de suscripción o recarga y lo envía al correo del cliente SaaS.
     * Nunca lanza excepciones: el webhook debe responder 200 aunque falle el PDF/correo.
     */
    private function enviarReciboPago(User $user, string $concepto, float $monto, PaymentTransaction $tx): void
    {
        try {
            $pdf = Pdf::loadView('pdf.recibo_pago', [
                'usuario' => $user,
                'concepto' => $concepto,
                'monto' => $monto,
                'transaccion' => $tx,
                'fecha' => now(),
            ]);

            $ruta = "recibos/recibo_{$tx->id}_" . now()->timestamp . '.pdf';
            Storage::disk('public')->put($ruta, $pdf->output());

            $this->notificador->correo(
                $user->email,
                "Recibo de pago #{$tx->id} - Logix",
                'Recibo de pago',
                [
                    "Hola {$user->name},",
                    "Confirmamos tu pago por concepto de: {$concepto}.",
                    'Monto: $' . number_format($monto, 0, ',', '.') . ' COP.',
                    'Adjuntamos el recibo en PDF. ¡Gracias por confiar en Logix!',
                ],
                Storage::disk('public')->path($ruta),
                'PAGO',
            );
        } catch (\Throwable $e) {
            Log::warning('No se pudo generar/enviar el recibo de pago', ['tx' => $tx->id, 'error' => $e->getMessage()]);
        }
    }
}
