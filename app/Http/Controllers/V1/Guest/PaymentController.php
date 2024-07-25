<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        $this->logInfo('Payment notification received', [
            'method' => $method,
            'uuid' => $uuid,
            'request_data' => $request->all(),
        ]);

        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verificationResult = $paymentService->notify($request->all());

            $this->logInfo('Payment verification result', ['verify' => $verificationResult]);

            if ($verificationResult === false) {
                throw new \Exception('Transaction was not successful or verification failed');
            }

            if (!$this->handleOrder($verificationResult['trade_no'], $verificationResult['callback_no'])) {
                throw new \Exception('Handle error');
            }

            $response = $verificationResult['custom_result'] ?? 'success';
            $this->logInfo('Payment process completed', ['response' => $response]);

            return $this->renderPaymentResult(true, 'پرداخت با موفقیت انجام شد.', $verificationResult['trade_no']);
        } catch (\Exception $e) {
            $this->logError('Payment notification error', $e);

            return $this->renderPaymentResult(false, 'خطا در پردازش پرداخت.');
        }
    }

    private function handleOrder($tradeNo, $transactionId)
    {
        $this->logInfo('Handling payment', [
            'trade_no' => $tradeNo,
            'transaction_id' => $transactionId
        ]);

        $order = Cache::remember("order_{$tradeNo}", 60, function() use ($tradeNo) {
            return Order::where('trade_no', $tradeNo)->first();
        });

        if (!$order) {
            $this->logError('Order not found', ['trade_no' => $tradeNo]);
            return false;
        }

        if ($order->status !== 0) {
            return true;
        }

        $orderService = new OrderService($order);
        if (!$orderService->paid($transactionId)) {
            $this->logError('Could not update order status', [
                'trade_no' => $tradeNo,
                'transaction_id' => $transactionId
            ]);
            return false;
        }

        $adjustedAmount = $order->total_amount / 1000;
        $message = $this->generateTelegramMessage($adjustedAmount, $order->trade_no);

        $telegramService = new TelegramService();
        $telegramService->sendMessageWithAdmin($message);

        return true;
    }

    private function renderPaymentResult($success, $message, $tradeNo = null)
    {
        $color = $success ? '#4CAF50' : '#F44336';
        $title = $success ? 'پرداخت موفق' : 'خطا در پرداخت';
        $icon = $success ? '✔' : '✘';

        $orderInfo = '';
        if ($success && $tradeNo) {
            $order = Cache::remember("order_{$tradeNo}", 60, function() use ($tradeNo) {
                return Order::where('trade_no', $tradeNo)->first();
            });

            if ($order) {
                $adjustedAmount = $order->total_amount / 1000;
                $orderInfo = "<p>شماره سفارش: {$order->trade_no}</p>" .
                             "<p>مبلغ پرداخت شده: " . number_format($adjustedAmount, 0, '.', ',') . " تومان</p>";
            }
        }

        $buttonText = $success ? 'رفتن به داشبرد' : 'رفتن به سفارشات';
        $buttonLink = $success ? 'https://drmobilejayzan.info/#/dashboard' : 'https://drmobilejayzan.info/#/dashboard/order';

        return view('payment_result', compact('title', 'color', 'icon', 'message', 'orderInfo', 'buttonText', 'buttonLink'));
    }

    private function logInfo($message, $data = [])
    {
        Log::info($message, $data);
    }

    private function logError($message, \Exception $e)
    {
        Log::error($message, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function generateTelegramMessage($adjustedAmount, $tradeNo)
    {
        return sprintf(
            "💰 پرداخت موفق به مبلغ %s تومان\n———————————————\nشماره سفارش: %s",
            number_format($adjustedAmount, 0, '.', ','),
            $tradeNo
        );
    }
}
