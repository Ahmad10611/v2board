<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\ClashMeta;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Morilog\Jalali\Jalalian;
use Symfony\Component\HttpFoundation\Response;

class ClientController extends Controller
{
    protected UserService $userService;
    protected ServerService $serverService;

    public function __construct(UserService $userService, ServerService $serverService)
    {
        $this->userService = $userService;
        $this->serverService = $serverService;
    }

    public function subscribe(Request $request)
    {
        $user = $request->user;

        if (!$user || !$this->userService->isAvailable($user)) {
            return response()->json(['message' => 'Unauthorized or unavailable user'], Response::HTTP_FORBIDDEN);
        }

        $flag = strtolower($request->input('flag', $_SERVER['HTTP_USER_AGENT'] ?? ''));
        $servers = $this->serverService->getAvailableServers($user);

        if (!$flag) {
            return $this->handleGeneral($user, $servers);
        }

        if (strpos($flag, 'sing') !== false) {
            return $this->handleSing($user, $servers, $flag);
        }

        $this->setSubscribeInfoToServers($servers, $user->toArray());

        foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
            $className = 'App\\Protocols\\' . basename($file, '.php');
            if (class_exists($className)) {
                $instance = new $className($user->toArray(), $servers);
                if (strpos($flag, $instance->flag) !== false) {
                    return $instance->handle();
                }
            }
        }

        return $this->handleGeneral($user, $servers);
    }

    private function handleGeneral($user, array $servers)
    {
        $class = new General($user->toArray(), $servers);
        return $class->handle();
    }

    private function handleSing($user, array $servers, string $flag)
    {
        preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches);
        $version = $matches[1] ?? null;

        $userArray = is_array($user) ? $user : $user->toArray();

        if ($version && version_compare($version, '1.12.0', '>=')) {
            $class = new Singbox($userArray, $servers);
        } else {
            $class = new SingboxOld($userArray, $servers);
        }

        return $class->handle();
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (empty($servers) || !(int)config('v2board.show_info_to_server_enable', 0)) {
            return;
        }

        $userArray = is_array($user) ? $user : $user->toArray();

        $useTraffic = $userArray['u'] + $userArray['d'];
        $totalTraffic = $userArray['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $userArray['expired_at'] ? Jalalian::forge($userArray['expired_at'])->format('Y-m-d') : 'نامعلوم';
        $resetDay = $this->userService->getResetDay((object)$userArray);

        array_unshift($servers, array_merge($servers[0], [
            'name' => "تاریخ：{$expiredDate}",
        ]));

        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "روزهای باقی‌مانده تا بازنشانی بعدی：{$resetDay}",
            ]));
        }

        array_unshift($servers, array_merge($servers[0], [
            'name' => "ترافیک：{$remainingTraffic}",
        ]));
    }
}
