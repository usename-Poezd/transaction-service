<?php

namespace  App\Services\Money\Services;

use App\Exceptions\NonReportable\BadCurrencyException;
use App\Exceptions\NonReportable\BadParameterException;
use App\Exceptions\NonReportable\InsufficientFundsException;
use App\Exceptions\TException;
use App\PremiumStatus;
use App\Services\Money\TransactionStorage;
use App\Transaction;
use App\User;
use Illuminate\Support\Facades\Log;

/**
 * Class TransactionsService
 * @package App\Services\Money\Services
 *
 * Сервис для операций с транзакций
 */
class TransactionsService implements TransactionStorage
{

    const INFLOW_TYPES = [
        Transaction::INFLOW_TEST,
        Transaction::INFLOW_OTHER,
        Transaction::INFLOW_CREATE,
        Transaction::INFLOW_REFUND,
        Transaction::INFLOW_PAYMENT,
        Transaction::GROUP_EARNED,
        Transaction::INFLOW_REF_BONUS,
        Transaction::INFLOW_USER_JOB,
    ];

    const OUTFLOW_TYPES = [
        Transaction::OUTFLOW_TEST,
        Transaction::OUTFLOW_OTHER,
        Transaction::OUTFLOW_ORDER,
        Transaction::OUTFLOW_CANCEL_REF_BONUS,
        Transaction::OUTFLOW_CANCEL_REFUND,
        Transaction::OUTFLOW_DESTROY,
    ];

    const PREMIUM_STATUS_TYPES = [
        Transaction::INFLOW_PAYMENT,
        Transaction::INFLOW_CREATE
    ];

    public function sum(mixed $user, string $cur): float
    {
        if (!$user instanceof User) {
            $user = User::findOrFail($user);
        }
        $balance = $user->transactions()
            ->where('cur', $cur)->get()->sum('amount');

        return round($balance, 2, PHP_ROUND_HALF_DOWN);
    }

    public function paymentsSum(mixed $user, string $cur): float
    {
        if (!$user instanceof User) {
            $user = User::findOrFail($user);
        }

        return $user->transactions()
            ->whereIn('type', [
                Transaction::INFLOW_PAYMENT,
                Transaction::INFLOW_CREATE
            ])
            ->where('cur', $cur)
            ->get()
            ->sum('amount');
    }

    public function createWithRelated(mixed $user, string $type, float $amount, string $cur, $comment = '', int $related = null, array $orderIds = []): mixed
    {
        return $this->createLocalTransaction($user, $type, $amount, $cur, $comment, $related, $paymentId = null, $orderIds);
    }

    public function create(mixed $user, string $type, float $amount, string $cur, $comment = '', int $paymentId = null, array $orderIds = []): mixed
    {
       return $this->createLocalTransaction($user, $type, $amount, $cur, $comment, null, $paymentId, $orderIds);
    }

    protected function createLocalTransaction(mixed $user, string $type, float $amount, string $cur, $comment = '', int $related = null, int $paymentId = null, array $orderIds = []): mixed
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

        $transaction = $user->transactions()->create([
            'type' => $type,
            'amount' => $amount,
            'cur' => $cur,
            'comment' => $comment,
            'event_id' => null,
            'related_user_id' => $related,
            'payment_id' => $paymentId,
            'order_ids' => $orderIds
        ]);

        // premium status
        if (in_array($type, self::PREMIUM_STATUS_TYPES)) {
            $spentInCurrency = $this->sum($user, $cur);
            $currentStatus = $user->premiumStatus;

            $premiumStatuses = PremiumStatus::where('cur', $cur)->get();

            foreach ($premiumStatuses as $ps) {
                if ($ps->cash > $currentStatus->cash &&
                    $ps->id > $currentStatus->id &&
                    $spentInCurrency >= $ps->cash) {
                    $currentStatus = $ps;
                }
            }
            $user->update(['premium_status_id' => $currentStatus->id]);
            $user->refresh();
        }

        return $transaction;
    }

    public function calculateBalance(mixed $user, float $amount)
    {
        if (!$user instanceof User) {
            $user = User::findOrFail($user);
        }

        $balance = $this->sum($user, $user->cur);

        return min($balance, $amount);
    }
}