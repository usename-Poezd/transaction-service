<?php


namespace App\Services\Money;


interface TransactionStorage
{
    public function sum(mixed $user, string $cur): float;

    public function create(
        mixed $user, 
        string $type, 
        float $amount, 
        string $cur, 
        $comment = '',
        int $paymentId = null,
        array $orderIds = []
    ): mixed;
}
