<?php

namespace  App\Services\Money\Services;

use App\Cashback;
use App\Exceptions\NonReportable\BadCurrencyException;
use App\Exceptions\NonReportable\BadParameterException;
use App\Exceptions\NonReportable\InsufficientFundsException;
use App\Services\Money\TransactionStorage;
use App\Transaction;
use App\User;
use Stripe\Service\BalanceService;

/**
 * Class CashbackService
 * @package App\Services
 *
 * Сервис для операций с кешбеком
 */
class CashbackService implements TransactionStorage
{

    const INFLOW_TYPES = [
        Cashback::CASHBACK_INFLOW_CREATE,
        Cashback::CASHBACK_INFLOW_PAYMENT
    ];

    const OUTFLOW_TYPES = [
        Cashback::CASHBACK_OUTFLOW_ORDER
    ];

    public function sum(mixed $user, string $cur): float
    {
        if (!$user instanceof User) {
            $user = User::findOrFail($user);
        }
        $balance = $user->cashBackHistory()
            ->where('cur', $cur)->get()->sum('amount');

        return round($balance, 2, PHP_ROUND_HALF_DOWN);
    }

    public function create(
        mixed $user, 
        string $type, 
        float $amount, 
        string $cur, 
        $comment = '', 
        int $paymentId = null, 
        array $orderIds = []): mixed
    {
        if (!$user instanceof User) {
            $user = User::findOrFail($user);
        }

        if (!in_array($cur, Transaction::CUR)) {
            throw new BadCurrencyException($cur);
        }

        if (in_array($type, self::INFLOW_TYPES) && $amount < 0) {
            throw new BadParameterException(__('s.inflow_positive'));
        }

        if (in_array($type, self::OUTFLOW_TYPES)) {
            if ($amount > 0) {
                throw new BadParameterException(__('s.outflow_negative'));
            }

            // $amount < 0
            if ($this->sum($user, $cur) - abs($amount) < 0) {
                throw new InsufficientFundsException();
            }
        }

       return $user->cashBackHistory()->create([
            'type' => $type,
            'amount' => $amount,
            'cur' => $cur,
            'comment' => $comment,
            'payment_id' => $paymentId,
            'order_ids' => $orderIds
        ]);
    }

    public function calculateCashback(mixed $user, float $amount, float $finalBalance)
    {
        if (!$user instanceof User) {
            $user = User::findOrFail($user);
        }

        $balance = $this->sum($user, $user->cur);

        return min($balance, $amount - $finalBalance);
    }
}