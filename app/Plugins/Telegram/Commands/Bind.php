<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class Bind extends Telegram {
    public $command = '/bind';
    public $description = 'لطفاً‌اکانت‌تلگرام‌را‌به‌وب‌سایت‌متصل‌کنید';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        if (!isset($message->args[0])) {
            abort(500, 'پارامتر اشتباه است⚠️ بعد از عبارت bind یک فاصل بزارید و لینک حساب خود را وارد و مجدد ارسال کنید.');
        }
        $subscribeUrl = $message->args[0];
        $subscribeUrl = parse_url($subscribeUrl);
        parse_str($subscribeUrl['query'], $query);
        $token = $query['token'];
        if (!$token) {
            abort(500, 'آدرس‌اشتراک‌نامعتبراست');
        }
        $user = User::where('token', $token)->first();
        if (!$user) {
            abort(500, 'کاربروجودندارد');
        }
        if ($user->telegram_id) {
            abort(500, 'ربات از قبل به یک اکانت متصل شده است. برای قطع اتصال از عبارت /unbind استفاده کنید.');
        }
        $user->telegram_id = $message->chat_id;
        if (!$user->save()) {
            abort(500, 'راه‌اندازی‌ناموفق‌بود');
        }
        $telegramService = $this->telegramService;
        $telegramService->sendMessage($message->chat_id, 'با موفقیت متصل شد😍 برای مشاهده ترافیک خود عبارت /traffic را ارسال کنید.');
    }
}
