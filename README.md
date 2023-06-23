## Пример кода для собеседования
Код с реального Laravel проекта

Пояснение:
Код выполняет задачу, связаннуб с обработкой транзакций на кешбек бонусами и валюты на балансе личного кабинета

Так как эти таблицы вынесены в отдельные для каждого типа, а PaymentService реализует единую работу с обработкой платежей, то с помощью интерфейса мы четко указываем функционал, который должен быть реализован для Transaction Storage классов

Потом в Services/PaymentService.php мы получаем реализацию исходя из типа

```
 /**
     * Map TransactionStorage class for string type
     *
     * @param string $type
     * @return ITransactionStorage
     */
    protected function mapService(string $type): ITransactionStorage
    {
        return match ($type) {
            Payment::ACTION_TYPE_TRANSACTION => $this->transactionService,
            Payment::ACTION_TYPE_CASHBACK => $this->cashbackService,
            default => $this->transactionService,
        };
    }

```

## Вопросы по коду

Код дернуть с реального проекта, так что, к сожалению показать не смогу все

Да, он не идеален, например можно добавить:
- DTO в PaymentService чтобы не пробрасывать кучу параметров
- Так же вынести магические строки в константы, чтобы избежать опечаток и ошибок
- В контроллере вместо $request->validate сделать отдельный PaymorePaymentSystemRequest где указать правила валидации
- В контроллере пробросить DI не через параметры метода а через __construct
- Вынести работу c бд в репозитории, чтобы обеспечить слабое связывание
