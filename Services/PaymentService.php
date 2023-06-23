<?php


namespace App\Services\Money\Services;

use App\Cashback;
use App\Domain\Models\CompositeOrder;
use App\Exceptions\NonReportable\BadCurrencyException;
use App\Exceptions\Reportable\ReportableException;
use App\Http\Middleware\SetRegionMW;
use App\Payment;
use App\PaymentSystems\PaymentSystem;
use App\Services\Money\IPaymentService as BasePaymentService;
use App\Services\Money\ITransactionStorage;
use App\Transaction;
use App\User;
use App\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class PaymentService implements BasePaymentService
{
    protected ?User $user = null;
    protected TransactionsService $transactionService;
    protected CashbackService $cashbackService;

    public function __construct(TransactionsService $transactionService, CashbackService $cashbackService)
    {
        $this->transactionService = $transactionService;
        $this->cashbackService = $cashbackService;
    }

    /**
     * Create payment
     *
     * @param PaymentSystem $paymentSystem
     * @param User $user
     * @param float $amount
     * @param array $paymentData
     * @param array $orders
     * @param bool $useBalance
     * @param bool $useCashback
     * @param string $ip
     * @return array
     * @throws ReportableException
     * @throws \Throwable
     */
    public function create(PaymentSystem $paymentSystem, User $user, float $amount, array $paymentData, array $orders = [], bool $useBalance = false, bool $useCashback = false, string $ip = ''): array
    {
        $this->user = $user;

        if (!$paymentSystem->hasCurrency($this->user->cur)) {
            throw new BadCurrencyException();
        }

        $payment = $this->createLocalPayment($paymentSystem, $amount, $orders, $useBalance, $useCashback, $ip);
        $paymentData = $this->preparePaymentData($payment['payment'], $paymentData);

        $url = $paymentData['success_url'];

        if(config('payment-systems.isDebugMode')){
            Log::channel('payments')->debug('Create payment', [
                'orders' => $orders,
                'amount' => $amount,
                'payment' => $payment,
                'paymentData' => $paymentData
            ]);
        }

        if ($payment['amounts']['payable'] > 0) {
            $paymentData['amount'] = $payment['amounts']['payable'];
            $paymentData['cur'] = $user->cur;
            $paymentData['user_name'] = $user->name;
            $paymentData['locale'] = $user->lang;

            $remotePayment = $this->createRemotePayment($paymentSystem, $payment['payment'], $paymentData);
            $payment['payment'] = $this->updateForeign($payment['payment'], $remotePayment['id']);

            $url = $remotePayment['url'];
        } else {
            $this->updateStatus($payment['payment'], Payment::STATUS_SUCCEEDED);
        }


        return [
            'url' => $url,
            'payment' => $payment['payment'],
        ];
    }

    protected function createRemotePayment(PaymentSystem $paymentSystem, Payment $payment, array $paymentData): array{
        try{
            return $paymentSystem->createRemotePayment($payment, $paymentData);
        }catch(ReportableException $e){
            if(config('payment-systems.isDebugMode')){
                Log::channel('payments')->debug('Create payment error', [$e->__toString()]);
            }
            throw $e;
        }catch(Exception $e){
            throw $e;
        }
    }

    protected function preparePaymentData(Payment $payment, array $paymentData): array
    {
        if (!isset($paymentData['forApp'])) {
            return $paymentData;
        }
        unset($paymentData['forApp']);
        unset($paymentData['ip']);
        $id = $payment->id;

        $locale = $paymentData['locale'];
        $locale = $locale === 'ru' ? '' : $locale;

        $app_waiting_url = route('main_domain_thanks', [
            'action'     => 'PaymentWaiting',
            'app'        => 'true',
            'fwd'        => 0,
            'locale'     => $locale ?? 'en',
            'payment_id' => $id
        ]);

        $paymentData['success_url'] = $app_waiting_url;
        $paymentData['cancel_url']  = $app_waiting_url;
        $paymentData['waiting_url'] = $app_waiting_url;

        return $paymentData;
    }



    /**
     * Process payment
     *
     * @param Payment $payment
     * @throws ReportableException
     * @throws \Throwable
     */
    protected function processPayment(Payment $payment)
    {
        $this->runActions($payment);
        $this->runOrders($payment);
    }

    /**
     * Update status for payment
     *
     * @param Payment $payment
     * @param string $status
     * @return bool
     * @throws ReportableException
     * @throws \Throwable
     */
    public function updateStatus(Payment $payment, string $status): bool
    {
        if (in_array($payment->status, Payment::TERMINAL_STATUSES)) {
            return false;
        }

        $payment->status = $status;
        $payment->save();


        if (Payment::STATUS_SUCCEEDED === $payment->status) {
            $this->processPayment($payment->refresh());
        }

        return true;
    }


    /**
     * Map TransactionStorage class for string type
     *
     * @param string $type
     * @return TransactionStorage
     */
    protected function mapService(string $type): ITransactionStorage
    {
        return match ($type) {
            Payment::ACTION_TYPE_TRANSACTION => $this->transactionService,
            Payment::ACTION_TYPE_CASHBACK => $this->cashbackService,
            default => $this->transactionService,
        };
    }

    /**
     * Run actions for payment
     *
     * @param Payment $payment
     * @throws \Throwable
     */
    protected function runActions(Payment $payment)
    {
        DB::transaction(function () use ($payment) {
            collect($payment->actions)->each(function ($action) use ($payment) {
                $service = $this->mapService($action['type']);
                $service->create(
                    amount: $action['amount'],
                    cur: $payment->currency,
                    orderIds: $payment->order_ids,
                    paymentId: $payment->id,
                    type: $action['action'],
                    user: $payment->user_id,
                );
            });
        });
    }

    /**
     * Run orders for payment
     *
     * @param Payment $payment
     * @throws ReportableException
     */
    protected function runOrders(Payment $payment)
    {
        // Need for loop, case each($callback) function in Laravel
        // collection does not throw error
        foreach ($payment->order_ids as $order_id) {
            $order = CompositeOrder::findOrFail($order_id);
            try {
                $order->pay();
                Log::info("[OL] Landing order id $order_id paid");
                $order->run();
                Log::info("[OL] Landing order id $order_id run");
            }
            catch (\Throwable $e) {
                throw (new ReportableException('Could not run order from main page.'))
                    ->withData(['exception' => $e]);
            }
        }
    }

    /**
     *  Create actions
     *
     * @param array $userServicesPrices
     * @param float $payableAmount
     * @param float $balanceAmount
     * @param float $cashbackAmount
     * @return array
     * [
     *      [
     *          type =>   string,
     *          action => string,
     *          amount => float
     *      ]
     * ]
     */
    protected function createActions(array $userServicesPrices, float $amount, float $payableAmount, float $balanceAmount, float $cashbackAmount, array $orders): array {
        $actions = collect([]);

        if ($payableAmount) {
            $actions->add([
                'type' =>   Payment::ACTION_TYPE_TRANSACTION,
                'action' => Transaction::INFLOW_PAYMENT,
                'amount' => $payableAmount
            ]);
        }

        if (count($orders) && ($payableAmount || $balanceAmount)) {
            $actions->add([
                'type' =>   Payment::ACTION_TYPE_TRANSACTION,
                'action' => Transaction::OUTFLOW_ORDER,
                'amount' => -($payableAmount + $balanceAmount)
            ]);
        }

        if ($cashbackAmount) {
            $actions->add([
                'type' =>   Payment::ACTION_TYPE_CASHBACK,
                'action' => Cashback::CASHBACK_OUTFLOW_ORDER,
                'amount' => -$cashbackAmount
            ]);
        }

        $cashbackSum = collect($userServicesPrices)->map(fn($i) => $i['cashback'])->sum()
            * (($payableAmount + $balanceAmount) / $amount);

        if (count($userServicesPrices) && $cashbackSum) {
            $actions->add([
                'type' =>   Payment::ACTION_TYPE_CASHBACK,
                'action' => Cashback::CASHBACK_INFLOW_PAYMENT,
                'amount' =>  $cashbackSum
            ]);
        }

        return $actions->toArray();
    }

    /**
     *  Calculate payable, balance and cashback amounts for payment and orders
     *
     * @param float $amount
     * @param array $userServicesPrices
     * @param bool $useBalance
     * @param bool $useCashback
     * @return array
     * [
     *      payable => float,
     *      balance => float,
     *      cashback => float
     * ]
     */
    protected function calculateAmounts(float $amount, array $userServicesPrices, bool $useBalance = false, bool $useCashback = false): array
    {
        $finalBalance = 0;
        $finalCashback = 0;

        if ($useBalance && count($userServicesPrices)) {
            $finalBalance = $this->transactionService->calculateBalance($this->user, $amount);
        }

        if ($useCashback && count($userServicesPrices)) {
            $finalCashback = $this->cashbackService->calculateCashback($this->user, $amount, $finalBalance);
        }

        $payableAmount = $amount - $finalBalance - $finalCashback;

        return [
            'payable' => $payableAmount,
            'balance' => $finalBalance,
            'cashback' => $finalCashback,
        ];
    }

    /**
     * Create local payment with actions
     *
     * @param PaymentSystem $paymentSystem
     * @param float $amount
     * @param array $orders
     * @param bool $useBalance
     * @param bool $useCashback
     * @param string $ip
     * @return array
     * [
     *      payment => Payment,
     *      amounts => [
     *          payable => float,
     *          balance => float,
     *          cashback => float
     *      ]
     * ]
     */
    protected function createLocalPayment(PaymentSystem $paymentSystem, float $amount, array $orders, bool $useBalance = false, bool $useCashback = false, string $ip = ''): array
    {
        $userServicesPrices = [];

        if (count($orders) > 0) {
            $userServicesPrices = UserService::getPricesFromOrders($orders, $this->user->cur, $this->user);
            $amount = collect($userServicesPrices)->map(fn($i) => $i['price'])->sum();
        }

        $amounts = $this->calculateAmounts($amount, $userServicesPrices, $useBalance, $useCashback);

        $cur = $this->user->cur;
        $paymentSystemClass = get_class($paymentSystem);
        return [
            'amounts' => $amounts,
            'payment' => Payment::create([
                'amount'      => $amount,
                'currency'    => $this->user->cur,
                'description' => substr("Payment {$amount} {$cur} by {$paymentSystemClass}", 0, 190),
                'order_ids'   => collect($orders)->map(fn($o) => $o->id),
                'status'      => Payment::STATUS_CREATED,
                'payment_system' => get_class($paymentSystem),
                'user_id'     => $this->user->id,
                'ip'          => $ip,
                'geocode'     => (new SetRegionMW())->getCountry($ip),
                'actions'     => $this->createActions(
                    $userServicesPrices,
                    $amount,
                    $amounts['payable'],
                    $amounts['balance'],
                    $amounts['cashback'],
                    $orders
                ),
            ]),
        ];
    }

    /**
     * Update foreign_id
     *
     * @param Payment $payment
     * @param mixed $id
     * @return Payment
     */
    protected function updateForeign(Payment $payment, mixed $id): Payment
    {
        $payment->update([
            'foreign_id' => $id,
            'status'     => Payment::STATUS_PENDING
        ]);
        $payment->save();
        return $payment;
    }

    /**
     *  Handle hook from payment system
     *
     * @param PaymentSystem $paymentSystem
     * @param Request $request
     * @return Response|JsonResponse
     * @throws ReportableException
     */
    public function handleHook(PaymentSystem $paymentSystem, Request $request): mixed
    {
        try {
            $paymentSystem->checkSignature($request);
            $status = $paymentSystem->mapRequestToStatus($request);
            if($paymentForeignId = $paymentSystem->getForeignPaymentId($request)) {
                $payment = Payment::where('foreign_id', $paymentForeignId)->firstOrFail();

                if (!$this->updateStatus($payment, $status)) {
                    Log::channel('payments')
                        ->error("Payment {$payment->id} missed status: $status");
                    Log::channel('payments')
                        ->info('Request '. json_encode($request->all()));

                    return $paymentSystem->getDefaultResponse();
                }

                Log::channel('payments')
                    ->info("Payment {$payment->id} new status: $status");
            }

            return $paymentSystem->getDefaultResponse();
        }
        catch (\Throwable $e) {
            throw (new ReportableException('Payment hook error'))
                ->withData(['exception' => describe_exception($e, true)]);
        }
    }
}
