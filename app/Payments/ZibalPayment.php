<?php
namespace App\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;

class ZibalPayment
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'zibal_merchant' => [
                'label' => 'کد مرچنت زیبال',
                'description' => '',
                'type' => 'input',
            ],
            'zibal_callback' => [
                'label' => 'آدرس بازگشت',
                'description' => '',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {
        if (!isset($this->config['zibal_merchant'], $this->config['zibal_callback'])) {
            Log::error('Zibal config is missing required keys');
            throw new \Exception('تنظیمات زیبال ناقص است.');
        }

        $params = [
            'merchant' => $this->config['zibal_merchant'],
            'amount' => $order['total_amount'] * 10,
            'callbackUrl' => $this->config['zibal_callback'],
            'orderId' => $order['trade_no'],
            'description' => 'پرداخت سفارش ' . $order['trade_no'],
        ];

        try {
            // استفاده از retry ساده (سازگار با Laravel قدیمی)
            $response = Http::retry(3, 100)
                ->timeout(20)
                ->post('https://gateway.zibal.ir/v1/request', $params);
            
            $result = $response->json();

            Log::info('Zibal payment request:', $this->filterLogData($params));
            Log::info('Zibal payment response:', $this->filterLogData($result));

            if ($response->successful() && ($result['result'] ?? 0) === 100) {
                // ذخیره trackId در cache
                cache()->put(
                    "zibal_track_{$order['trade_no']}", 
                    $result['trackId'], 
                    300
                );
                
                return [
                    'type' => 1,
                    'data' => 'https://gateway.zibal.ir/start/' . $result['trackId'],
                ];
            }

            Log::error('Zibal payment failed', $result);
            throw new \Exception($result['message'] ?? 'خطای نامشخص از زیبال');

        } catch (\Exception $e) {
            Log::error('Zibal payment error: ' . $e->getMessage());
            return false;
        }
    }

    public function notify($params)
    {
        Log::info('Zibal notify received:', $this->filterLogData($params));

        // جلوگیری از پردازش تکراری
        $processKey = "processed_{$params['orderId']}_{$params['trackId']}";
        if (cache()->has($processKey)) {
            Log::info('Payment already processed', ['key' => $processKey]);
            return cache()->get("payment_result_{$params['orderId']}") ?: false;
        }

        $requiredParams = ['trackId', 'orderId', 'success'];
        foreach ($requiredParams as $param) {
            if (!isset($params[$param])) {
                Log::error('Missing required parameter: ' . $param);
                return false;
            }
        }

        if ($params['success'] != 1) {
            Log::error('Transaction failed with status: ' . $params['success']);
            return false;
        }

        $order = cache()->remember("order_{$params['orderId']}", 60, function() use ($params) {
            return Order::where('trade_no', $params['orderId'])->first();
        });

        if (!$order) {
            Log::error('Order not found: ' . $params['orderId']);
            return false;
        }

        // بررسی trackId
        $cachedTrackId = cache()->get("zibal_track_{$params['orderId']}");
        if ($cachedTrackId && $cachedTrackId !== $params['trackId']) {
            Log::error('TrackId mismatch', [
                'cached' => $cachedTrackId,
                'received' => $params['trackId']
            ]);
            return false;
        }

        try {
            $response = Http::retry(3, 100)
                ->timeout(20)
                ->post('https://gateway.zibal.ir/v1/verify', [
                    'merchant' => $this->config['zibal_merchant'],
                    'trackId' => $params['trackId']
                ]);

            $result = $response->json();
            Log::info('Zibal verify response:', $this->filterLogData($result));

            if (($result['result'] ?? 0) !== 100 || $result['amount'] != ($order->total_amount * 10)) {
                Log::error('Payment verification failed', $result);
                return false;
            }

            Log::info('Raw cardNumber from Zibal:', ['cardNumber' => $result['cardNumber'] ?? 'N/A']);

            $cardNumber = isset($result['cardNumber']) ? $this->maskCardNumber($result['cardNumber']) : 'N/A';

            $successResult = [
                'trade_no' => $params['orderId'],
                'callback_no' => $params['trackId'],
                'amount' => $order->total_amount,
                'card_number' => $cardNumber
            ];

            // ذخیره نتیجه
            cache()->put($processKey, true, 86400);
            cache()->put("payment_result_{$params['orderId']}", $successResult, 86400);
            
            // پاکسازی cache
            cache()->forget("order_{$params['orderId']}");
            cache()->forget("zibal_track_{$params['orderId']}");

            return $successResult;
            
        } catch (\Exception $e) {
            Log::error('Zibal verify error: ' . $e->getMessage());
            return false;
        }
    }

    private function filterLogData($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $filtered = $data;
        $sensitiveFields = ['merchant', 'cardNumber', 'token'];
        
        foreach ($sensitiveFields as $field) {
            if (isset($filtered[$field])) {
                $filtered[$field] = '***' . substr($filtered[$field], -4);
            }
        }
        
        array_walk_recursive($filtered, function (&$value, $key) use ($sensitiveFields) {
            if (in_array($key, $sensitiveFields) && is_string($value)) {
                $value = '***' . substr($value, -4);
            }
        });
        
        return $filtered;
    }

    private function maskCardNumber($cardNumber)
    {
        if (empty($cardNumber) || !is_string($cardNumber)) {
            return 'N/A';
        }
        
        $cardNumber = preg_replace('/\D/', '', $cardNumber);
        
        if (strlen($cardNumber) < 10) {
            return 'N/A';
        }
        
        return substr($cardNumber, 0, 6) . str_repeat('*', max(6, strlen($cardNumber) - 10)) . substr($cardNumber, -4);
    }
}
