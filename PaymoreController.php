<?php /** @noinspection PhpArrayShapeAttributeCanBeAddedInspection */

namespace App\Http\Controllers\PaymentSystems;

use App\Documentor\Documentor as D;
use App\Documentor\Endpoint;
use App\Documentor\Group;
use App\Documentor\Param;
use App\Documentor\Role;
use App\Documentor\Text;
use App\Documentor\Verbs;
use App\Http\Controllers\Controller;
use App\PaymentSystems\PaymorePaymentSystem;
use App\Responses\ApiResponse;
use App\Services\Money\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymoreController extends Controller {
    #[
        Group('payment'),
        Endpoint('deposit/paymore'),
        Verbs(D::POST),
        Role('ROLE_ANY'),
        Text('Пополнить счет ЛК через paymore'),
        Param('amount', true, D::INT),
        Param('success_url', true, D::URL),
        Param('cancel_url', true, D::URL)
    ]
    public function deposit(Request $request, PaymorePaymentSystem $paymorePS): ApiResponse
    {
        $val = $request->validate([
            'amount'      => 'required|numeric',
            'cancel_url'  => 'required|string',
            'description' => 'required|string',
            'success_url' => 'required|string',
        ]);
        $val['cur'] = Auth::user()->cur;
        return $paymorePS->startAuthSession($val);
    }

    #[Group('payment')]
    #[Endpoint('paymore_status')]
    #[Verbs(D::POST)]
    #[Role('ROLE_ANY')]
    #[Text('Метод для обработки хуков от paymore')]
    public function hook(Request $request, PaymentService $paymentService, PaymorePaymentSystem $paymorePS)
    {
        return $paymentService->handleHook($paymorePS, $request);
    }
}
