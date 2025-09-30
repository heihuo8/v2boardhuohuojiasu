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

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;
        // account not expired and is not banned.
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            if($flag) {
                if (!strpos($flag, 'sing')) {
                    $this->setSubscribeInfoToServers($servers, $user);
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            return $class->handle();
                        }
                    }
                }
                if (strpos($flag, 'sing') !== false) {
                    $version = null;
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if (!is_null($version) && $version >= '1.12.0') {
                        $class = new Singbox($user, $servers);
                    } else {
                        $class = new SingboxOld($user, $servers);
                    }
                    return $class->handle();
                }
            }
            $class = new General($user, $servers);
            return $class->handle();
        }

        // 二开功能：用户不可用时的处理
        // 检查 new_servers 配置是否存在
        $newServersConfig = config('gunzi.new_servers', null);

        // 如果 new_servers 没有配置或未启用，则直接返回
        if ($newServersConfig === null || !$newServersConfig['enabled']) {
            return;
        }

        // 新增逻辑 - 给过期或封禁用户显示提示信息
        if (!$user['banned']) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);

            $newServers = [];

            // 检查 expired 配置是否存在
            $expiredMessages = $newServersConfig['expired'] ?? [];
            if ($user['expired_at'] <= time() && !empty($expiredMessages)) {
                foreach ($expiredMessages as $message) {
                    $newServers[] = array_merge($servers[0], [
                        'name' => $message,
                    ]);
                }
            }

            // 检查流量是否用尽
            $useTraffic = $user['u'] + $user['d'];
            $totalTraffic = $user['transfer_enable'];
            $trafficExhaustedMessages = $newServersConfig['traffic_exhausted'] ?? [];
            if ($useTraffic >= $totalTraffic && !empty($trafficExhaustedMessages)) {
                foreach ($trafficExhaustedMessages as $message) {
                    $newServers[] = array_merge($servers[0], [
                        'name' => $message,
                    ]);
                }
            }

            // 添加通用提示信息
            $generalMessages = $newServersConfig['general'] ?? [];
            if (!empty($generalMessages)) {
                foreach ($generalMessages as $message) {
                    $newServers[] = array_merge($servers[0], [
                        'name' => $message,
                    ]);
                }
            }

            // 新节点
            $servers = $newServers;

            if ($flag) {
                // 支持过期用户的 Singbox 客户端
                if (strpos($flag, 'sing') !== false) {
                    $version = null;
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if (!is_null($version) && $version >= '1.12.0') {
                        $class = new Singbox($user, $servers);
                    } else {
                        $class = new SingboxOld($user, $servers);
                    }
                    return $class->handle();
                }
                // 支持其他协议
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
        }
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);

        // 二开功能：添加群组信息
        $groupInfo = config('gunzi.group_info', []);
        if (!empty($groupInfo)) {
            foreach ($groupInfo as $info) {
                array_unshift($servers, array_merge($servers[0], [
                    'name' => $info,
                ]));
            }
        }

        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));

        // 二开功能：检查流量是否即将用尽
        $newServersConfig = config('gunzi.new_servers', null);
        if ($newServersConfig === null || !$newServersConfig['enabled']) {
            return;
        }

        // 如果流量用尽，在正常订阅中也添加提示
        $remainingBytes = $totalTraffic - $useTraffic;
        if ($remainingBytes <= 0) {
            $trafficExhaustedMessages = $newServersConfig['traffic_exhausted'] ?? [];
            $newServers = [];
            foreach ($trafficExhaustedMessages as $message) {
                $newServers[] = array_merge($servers[0], [
                    'name' => $message,
                ]);
            }

            if (count($newServers) > 2) {
                $servers = $newServers;
            } else {
                $servers = array_merge($newServers, $servers);
            }
        }
    }
}
