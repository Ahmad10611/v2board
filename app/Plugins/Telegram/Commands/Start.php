<?php

namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;

class Start extends Telegram {
    public $command = '/start';
    public $description = 'خوش آمدید و کمک کنید';

    public function handle($message, $match = []) {
        $telegramService = $this->telegramService;
        $appName = config('v2board.app_name', 'V2Board');
        $text = "خوش آمدی {$appName} عزیز
        
برای اتصال اکانت خود به ربات باید:

1: با ایمیل و پسورد اکانت خود وارد سایت بشید
2: از قسمت اشتراک با یک کلیک، آدرس اشتراک خودتان را کپی کنید
3: عبارت /bind را نوشته یک فاصله بگزارید و لینک اشتراک خوتان را کپی کنید و برای ربات ارسال کنید

برای مثال:
/bind https://ddr.drmobilejayzan.info/api/v1/client/subscribe?token=da27b965b850079333afd2a130651796

کار هایی که بعد از اتصال اکانت خود این ربات میتواند برای شما انجام دهد:

/getlatesturl - آخرین آدرس سایت
/traffic - اطلاعات ترافیک شما
/unbind - اتصال حساب خود با ربات قطع کنید           

دکتر موبایل جایزان💉";
        $telegramService->sendMessage($message->chat_id, $text, 'markdown');
    }
}
