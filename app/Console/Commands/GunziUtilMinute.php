<?php

namespace App\Console\Commands;

use App\Models\MailLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use App\Services\TelegramService;

class GunziUtilMinute extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gunzi:util_minute';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'gunzi scheduled inspection tasks~';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $config = config('gunzi');
        $register_time = $config['register']['time'];
        $from_time = strtotime("-{$register_time} minute");
        $to_time = strtotime("-" . ($register_time - 1) . " minute");
		$register_users = User::where('created_at', '>=', $from_time)
                      ->where('created_at', '<', $to_time)
                      ->get();
        $register_users->each(function ($user) use ($config) {
            $this->info("已发送注册未下单邮件提醒：".$user->email);
            if (Order::query()->where(['user_id' => $user->id, 'status' => 3])->doesntExist()) {
                $this->sendMail($user->email, $config['register']['title'], $config['register']['content']);
            }
        });
		
		$unpaid_time = $config['unpaid']['time'];
        $from_time = strtotime("-{$unpaid_time} minute");
		$to_time = strtotime("-" . ($unpaid_time - 1) . " minute");
		$unpaid_orders = Order::where('created_at', '>=', $from_time)
                      ->where('created_at', '<', $to_time)
                      ->where('status', 0)
                      ->get();
        $unpaid_orders->each(function ($order) use ($config){
            $email = User::query()->where('id', $order->user_id)->value('email');
            $this->info("已发送订单已创建未付款邮件提醒：".$email);
            $this->sendMail($email, $config['unpaid']['title'], $config['unpaid']['content']);
        });
    }

    private function sendMail($email, $subject, $content)
    {
        $mailConfigs = config('gunzi.mail');
        $telegramService = new TelegramService();

        if (is_array($mailConfigs) && count($mailConfigs) > 0) {
            // 随机选择一个邮箱配置
            $selectedConfig = $mailConfigs[array_rand($mailConfigs)];

            Config::set('mail.host', $selectedConfig['host']);
            Config::set('mail.port', $selectedConfig['port']);
            Config::set('mail.encryption', $selectedConfig['encryption']);
            Config::set('mail.username', $selectedConfig['username']);
            Config::set('mail.password', $selectedConfig['password']);
            Config::set('mail.from.address', $selectedConfig['from_address']);
            Config::set('mail.from.name', config('v2board.app_name', 'V2Board'));
        }
        $params = [
            'template_name' => 'notify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'content' => $content
            ]
        ];

        $params['template_name'] = 'mail.' . config('v2board.email_template', 'default') . '.' . $params['template_name'];
		$fromEmail = $selectedConfig['from_address'];
        try {
            Mail::send(
                $params['template_name'],
                $params['template_value'],
                function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                }
            );
			$adminMessage = "发送营销邮件：成功！\n发送邮箱: `{$fromEmail}`\n邮件主题：{$subject}\n目标邮箱： `{$email}`";
            $telegramService->sendMessageWithAdmin($adminMessage);
        } catch (\Exception $e) {
            $error = $e->getMessage();
			// 使用 TelegramService 发送错误信息，仅包含邮箱地址
            $telegramService->sendMessageWithAdmin("发送营销邮件发送失败,请检查邮箱：`{$fromEmail}`配置是否故障");
        }

        $log = [
            'email' => $email,
            'subject' => $subject,
            'template_name' => $params['template_name'],
            'error' => isset($error) ? $error : NULL
        ];

        MailLog::create($log);
        $log['config'] = config('mail');
        return $log;
    }
}
