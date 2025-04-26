<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\Clash;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpFoundation\Response;

class AppController extends Controller
{
    protected UserService $userService;
    protected ServerService $serverService;

    public function __construct(UserService $userService, ServerService $serverService)
    {
        $this->userService = $userService;
        $this->serverService = $serverService;
    }

    public function getConfig(Request $request)
    {
        $user = $request->user;
        $servers = [];

        if ($user && $this->userService->isAvailable($user)) {
            $servers = $this->serverService->getAvailableServers($user);
        }

        $defaultConfigPath = base_path('resources/rules/app.clash.yaml');
        $customConfigPath = base_path('resources/rules/custom.app.clash.yaml');

        $configPath = File::exists($customConfigPath) ? $customConfigPath : $defaultConfigPath;
        $config = Yaml::parseFile($configPath);

        $proxy = [];
        $proxies = [];

        foreach ($servers as $item) {
            switch ($item['type']) {
                case 'shadowsocks':
                    if (in_array($item['cipher'], ['aes-128-gcm', 'aes-192-gcm', 'aes-256-gcm', 'chacha20-ietf-poly1305'])) {
                        $proxy[] = Clash::buildShadowsocks($user['uuid'], $item);
                        $proxies[] = $item['name'];
                    }
                    break;
                case 'vmess':
                    $proxy[] = Clash::buildVmess($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
                case 'trojan':
                    $proxy[] = Clash::buildTrojan($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
            }
        }

        $config['proxies'] = array_merge($config['proxies'] ?? [], $proxy);

        foreach ($config['proxy-groups'] as &$group) {
            if (isset($group['proxies'])) {
                $group['proxies'] = array_merge($group['proxies'], $proxies);
            }
        }

        $yamlContent = Yaml::dump($config);

        return response($yamlContent, Response::HTTP_OK)
            ->header('Content-Type', 'text/yaml');
    }

    public function getVersion(Request $request)
    {
        $userAgent = $request->header('user-agent', '');

        if (str_contains($userAgent, 'tidalab/4.0.0') || str_contains($userAgent, 'tunnelab/4.0.0')) {
            $isWindows = str_contains($userAgent, 'Win64');
            $platform = $isWindows ? 'windows' : 'macos';

            return response([
                'data' => [
                    'version' => config("v2board.{$platform}_version"),
                    'download_url' => config("v2board.{$platform}_download_url"),
                ]
            ]);
        }

        return response([
            'data' => [
                'windows_version' => config('v2board.windows_version'),
                'windows_download_url' => config('v2board.windows_download_url'),
                'macos_version' => config('v2board.macos_version'),
                'macos_download_url' => config('v2board.macos_download_url'),
                'android_version' => config('v2board.android_version'),
                'android_download_url' => config('v2board.android_download_url'),
            ]
        ]);
    }
}
