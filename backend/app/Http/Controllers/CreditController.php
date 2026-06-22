<?php

namespace App\Http\Controllers;

use App\Models\CreditPackage;
use App\Models\PaymentTransaction;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CreditController extends Controller
{
    public function __construct(private CreditService $credits) {}

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

    /** Create a checkout session for buying credits (returns checkoutUrl placeholder) */
    public function createSession(Request $request)
    {
        $data = $request->validate([
            'package_id' => ['required', 'exists:credit_packages,id'],
        ]);

        $package = CreditPackage::findOrFail($data['package_id']);
        $user = $request->user();

        // Create a PaymentTransaction placeholder for idempotency and tracking
        $pt = PaymentTransaction::create([
            'provider' => env('PAYMENT_GATEWAY', 'epayco'),
            'status' => 'PENDING',
            'payload' => ['package_id' => $package->id, 'user_id' => $user->id],
            'amount' => $package->price_cop / 100,
            'currency' => 'COP',
        ]);

        // TODO: integrate real checkout with ePayco; for now return a placeholder URL
        $checkoutUrl = url("/payments/checkout?tx={$pt->id}");

        return response()->json(['checkoutUrl' => $checkoutUrl, 'payment_transaction_id' => $pt->id]);
    }
}
