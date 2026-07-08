<?php

namespace App\Http\Controllers;

use App\Models\CreditPackage;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\CreditService;
use App\Services\WompiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CreditController extends Controller
{
    public function __construct(private CreditService $credits, private WompiService $wompi) {}

    // List available packages (public for auth users)
    public function indexPackages(Request $request)
    {
        $module = $request->query('module');
        $q = CreditPackage::where('active', true);
        if ($module) $q->where('module', $module);
        return $q->orderBy('price_cop')->get();
    }

    // Return current user's credits per module
    public function myCredits(Request $request)
    {
        $user = $request->user();
        if (! $user) return response()->json(['message' => 'No autorizado'], 401);
        $credits = $user->load('credits');
        // Format: { module: credits }
        $map = [];
        foreach ($user->credits as $c) {
            $map[$c->module] = $c->credits;
        }
        return response()->json($map);
    }

    /** Crea la sesión de pago (Wompi: PSE, Nequi, tarjeta) para recargar la billetera. */
    public function createSession(Request $request)
    {
        $data = $request->validate([
            'package_id' => ['required', 'exists:credit_packages,id'],
        ]);

        $package = CreditPackage::findOrFail($data['package_id']);
        $user = $request->user();

        // Transacción local para idempotencia y trazabilidad; la referencia viaja
        // a Wompi y regresa en el webhook para saber qué acreditar y a quién.
        $pt = PaymentTransaction::create([
            'provider' => 'wompi',
            'status' => 'PENDING',
            'user_id' => $user->id,
            'payload' => ['tipo' => 'recarga', 'package_id' => $package->id, 'user_id' => $user->id],
            'amount' => $package->price_cop,
            'currency' => 'COP',
        ]);

        $referencia = "LOGIX-CRED-{$pt->id}";
        $pt->update(['payload' => array_merge($pt->payload, ['reference' => $referencia])]);

        return response()->json([
            'checkoutUrl' => $this->wompi->checkoutUrl((int) $package->price_cop, $referencia),
            'payment_transaction_id' => $pt->id,
            'reference' => $referencia,
        ]);
    }

    /** Crea la sesión de pago (Wompi) para pagar o renovar la membresía mensual de un plan. */
    public function createPlanSession(Request $request, Plan $plan)
    {
        $user = $request->user();

        if (! $plan->activo || (int) $plan->precio_mensual <= 0) {
            return response()->json(['message' => 'Este plan no está disponible para pago en línea.'], 422);
        }

        $pt = PaymentTransaction::create([
            'provider' => 'wompi',
            'status' => 'PENDING',
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'payload' => ['tipo' => 'membresia', 'plan_id' => $plan->id, 'plan' => $plan->nombre, 'user_id' => $user->id],
            'amount' => $plan->precio_mensual,
            'currency' => 'COP',
        ]);

        $referencia = "LOGIX-PLAN-{$pt->id}";
        $pt->update(['payload' => array_merge($pt->payload, ['reference' => $referencia])]);

        return response()->json([
            'checkoutUrl' => $this->wompi->checkoutUrl((int) $plan->precio_mensual, $referencia),
            'payment_transaction_id' => $pt->id,
            'reference' => $referencia,
        ]);
    }
}
