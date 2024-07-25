<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;
use Morilog\Jalali\Jalalian; // اضافه کردن کتابخانه جلالی

class Traffic extends Telegram {
    public $command = '/traffic';
    public $description = 'اطلاعات ترافیک را جستجو کنید';

    public function handle($message, $match = []) {
        $telegramService = $this->telegramService;
        if (!$message->is_private) return;

        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, 'اطلاعات حساب پیدا نشد! ابتدا حساب خود را اضافه کنید😊', 'markdown');
            return;
        }

        function remainingDays($expireDate) {
            $today = time();
            $expire = strtotime($expireDate);
            $diff = $expire - $today;
            return floor($diff / (60 * 60 * 24));
        }

        function remainingHours($expireDate) {
            $today = time();
            $expire = strtotime($expireDate);
            $diff = $expire - $today;
            return floor($diff / (60 * 60));
        }

        // ترافیک آپلود و دانلود امروز
        $todayUpload = round(($user['u'] - $user['last_day_u']) / (1024*1024*1024), 2);
        $todayDownload = round(($user['d'] - $user['last_day_d']) / (1024*1024*1024), 2);
        $todayTraffic = $todayUpload + $todayDownload;

        // محاسبه میانگین ترافیک هفتگی
        $averageTrafficWeek = round((($user['u'] + $user['d']) - ($user['last_day_u'] + $user['last_day_d'])) / 7 / (1024*1024*1024), 2);

        // ترافیک آپلود و دانلود کلی
        $upload = round($user['u'] / (1024*1024*1024), 2);
        $download = round($user['d'] / (1024*1024*1024), 2);
        $useTraffic = $upload + $download;

        // ترافیک کل و ترافیک باقی‌مانده
        $totalTraffic = round($user['transfer_enable'] / (1024*1024*1024), 2);
        $remainingTraffic = $totalTraffic - $useTraffic;
        if ($remainingTraffic < 0) {
            $remainingTraffic = 'تمام شده';
        }

        // تاریخ انقضا
        $expireDate = $user['expired_at'] === NULL ? 'نامحدود' : date('Y-m-d H:i:s', $user['expired_at']);
        $expireDateJalali = $user['expired_at'] === NULL ? 'نامحدود' : Jalalian::forge($user['expired_at'])->format('%Y-%m-%d H:i:s');
        $remainingHours = $expireDate === 'نامحدود' ? 'نامحدود' : remainingHours($expireDate);
        $remainingDays = $expireDate === 'نامحدود' ? 'نامحدود' : remainingDays($expireDate);

        // متن پاسخ
        $text = "🚥اطلاعات ترافیک\n———————————————\nترافیک آپلود شده: `{$upload} GB`\nترافیک دانلود شده: `{$download} GB`\n\nمیانگین ترافیک هفتگی: `{$averageTrafficWeek} GB`\n\nترافیک باقی‌مانده: `{$remainingTraffic} GB`\nمجموع ترافیک استفاده شده تا الان: `{$useTraffic} GB`\n\nترافیک سفارش داده شده: `{$totalTraffic} GB`\n\nروز‌های باقی‌مانده: `{$remainingDays}` روز\nبرابر با: `{$remainingHours}` ساعت\n\nتاریخ انقضا (میلادی): `{$expireDate}`\nتاریخ انقضا (شمسی): `{$expireDateJalali}`\n\nکانال رسمی دکتر موبایل جایزان\n https://t.me/DMJPROXY021";

        if ($remainingTraffic === 'تمام شده') {
            $text = str_replace('GB', '', $text);
        }

        $telegramService->sendMessage($message->chat_id, $text, 'markdown');
    }
}
