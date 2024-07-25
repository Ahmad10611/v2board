<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Morilog\Jalali\Jalalian;

class ClientController extends Controller
{
    /**
     * Handle subscription requests.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function subscribe(Request $request)
    {
        try {
            $flag = $request->input('flag') ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
            $flag = strtolower($flag);
            $user = $request->user;
            $userService = new UserService();

            if ($userService->isAvailable($user)) {
                $servers = Cache::remember("available_servers_{$user->id}", 60, function() use ($user) {
                    $serverService = new ServerService();
                    return $serverService->getAvailableServers($user);
                });

                if (!($flag && strpos($flag, 'sing-box'))) {
                    $this->setSubscribeInfoToServers($servers, $user);
                }

                if ($flag) {
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            return $class->handle();
                        }
                    }
                }

                $class = new General($user, $servers);
                return $class->handle();
            } else {
                return response()->json(['error' => 'Account is either expired or banned.'], 403);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred. Please try again later.'], 500);
        }
    }

    /**
     * Set subscription info to servers.
     *
     * @param array $servers
     * @param array $user
     */
    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);

        // تبدیل تاریخ میلادی به شمسی
        $expiredDate = $user['expired_at'] ? Jalalian::forge($user['expired_at'])->format('Y-m-d') : '长期有效';

        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "تاریخ: {$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "روزهای باقی‌مانده تا بازنشانی بعدی: {$resetDay}",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "ترافیک: {$remainingTraffic}",
        ]));
    }
}
