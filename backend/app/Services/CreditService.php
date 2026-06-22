<?php

namespace App\Services;

use App\Models\CreditPackage;
use App\Models\CreditTransaction;
use App\Models\PaymentTransaction;
use App\Models\UserCredit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreditService
{
    /** Acredita créditos a un usuario de forma atómica y registra la transacción */
    public function creditUser(int $userId, string $module, int $credits, ?int $paymentTxId = null, ?int $packageId = null, ?string $description = null): UserCredit
    {
        return DB::transaction(function () use ($userId, $module, $credits, $paymentTxId, $packageId, $description) {
            $uc = UserCredit::where('user_id', $userId)->where('module', $module)->lockForUpdate()->first();
            if (! $uc) {
                $uc = UserCredit::create(['user_id' => $userId, 'module' => $module, 'credits' => 0]);
            }

            $uc->credits = (int) $uc->credits + (int) $credits;
            $uc->save();

            CreditTransaction::create([
                'user_id' => $userId,
                'module' => $module,
                'change' => $credits,
                'balance_after' => $uc->credits,
                'type' => 'purchase',
                'credit_package_id' => $packageId,
                'payment_transaction_id' => $paymentTxId,
                'description' => $description,
            ]);

            // Update payment transaction link if provided
            if ($paymentTxId) {
                try {
                    $pt = PaymentTransaction::find($paymentTxId);
                    if ($pt) {
                        $pt->user_id = $userId;
                        $pt->save();
                    }
                } catch (ModelNotFoundException $e) {
                    // ignore
                }
            }

            return $uc;
        });
    }

    /** Consume credits; lanza ValidationException si no hay saldo */
    public function consume(int $userId, string $module, int $amount = 1): CreditTransaction
    {
        return DB::transaction(function () use ($userId, $module, $amount) {
            $uc = UserCredit::where('user_id', $userId)->where('module', $module)->lockForUpdate()->first();
            $current = $uc ? (int) $uc->credits : 0;

            if ($current < $amount) {
                throw ValidationException::withMessages(['credits' => ['Saldo insuficiente de créditos para este módulo.']]);
            }

            $uc->credits = $current - $amount;
            $uc->save();

            $tx = CreditTransaction::create([
                'user_id' => $userId,
                'module' => $module,
                'change' => -$amount,
                'balance_after' => $uc->credits,
                'type' => 'consumption',
                'description' => "Consume {$amount} credit(s)",
            ]);

            return $tx;
        });
    }
}
