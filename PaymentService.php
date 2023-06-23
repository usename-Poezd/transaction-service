<?php


namespace App\Services\Money;


use App\Payment;
use App\PaymentSystems\PaymentSystem;
use App\User;
use Illuminate\Http\Response;
use Illuminate\Http\Request;

interface PaymentService
{
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
     * @return array ['url' => string, 'payment' => Payment]
     */
    public function create(PaymentSystem $paymentSystem, User $user, float $amount, array $paymentData,  array $orders = [], bool $useBalance = false, bool $useCashback = false, string $ip = ''): array;

    /**
     * Update status for payment
     *
     * @param Payment $payment
     * @param string $status
     * @return bool
     */
    public function updateStatus(Payment $payment, string $status): bool;


    /**
     * Handle hook from payment system
     *
     * @param PaymentSystem $paymentSystem
     * @param Request $request
     * @return Response
     */
    public function handleHook(PaymentSystem $paymentSystem, Request $request): mixed;

}
