<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class UnBind extends Telegram {
    public $command = '/unbind';
    public $description = 'حذف لینک اکانت تلگرام از وب‌سایت';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;

        $user = User::where('telegram_id', $message->chat_id)->first();
        $telegramService = $this->telegramService;

        if (!$user) {
            $telegramService->sendMessage($message->chat_id, 'اطلاعات حساب پیدا نشد! ابتدا حساب خود را اضافه کنید 😊', 'markdown');
            return;
        }

        $user->telegram_id = NULL;

        try {
            if (!$user->save()) {
                throw new \Exception('بازگشایی نشد');
            }
            $telegramService->sendMessage($message->chat_id, 'از حساب خود خارج شدید 🥲', 'markdown');
        } catch (\Exception $e) {
            $telegramService->sendMessage($message->chat_id, 'مشکلی در بازگشایی حساب شما رخ داد. لطفاً دوباره تلاش کنید.', 'markdown');
        }
    }
}
