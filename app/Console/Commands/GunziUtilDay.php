<?php

namespace App\Console\Commands;

use App\Models\MailLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use App\Services\TelegramService;

class GunziUtilDay extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gunzi:util_day';

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
        $start_7day = strtotime(date('Y-m-d 00:00:00', strtotime('+7 day')));
        $end_7day = strtotime(date('Y-m-d 23:59:59', strtotime('+7 day')));
        $expired_7day_users = User::query()
            ->where('expired_at', '>', $start_7day)
            ->where('expired_at', '<=', $end_7day)
            ->get();
        $expired_7day_users->each(function ($user) use ($config){
            $this->info("已发送用户还有7天到期邮件提醒：".$user->email);
            $this->sendMail($user->email, $config['user_expire'][0]['title'], $config['user_expire'][0]['content']);
        });

        $start_1day = strtotime(date('Y-m-d 00:00:00', strtotime('+1 day')));
        $end_1day = strtotime(date('Y-m-d 23:59:59', strtotime('+1 day')));
        $expired_1day_users = User::query()
            ->where('expired_at', '>', $start_1day)
            ->where('expired_at', '<=', $end_1day)
            ->get();
        $expired_1day_users->each(function ($user) use ($config){
            $this->info("已发送用户还有1天到期邮件提醒：".$user->email);
            $this->sendMail($user->email, $config['user_expire'][1]['title'], $config['user_expire'][1]['content']);
        });

        $flow_out_users = User::query()->whereRaw('u + d > transfer_enable')->get();
        $flow_out_users->each(function ($user) use($config){
            $log = MailLog::where(['email' => $user->email, 'subject' => $config['flow_out']['title']])->doesntExist();
            if ($log){
                $this->info("已发送用户流量已用尽邮件提醒：".$user->email);
                $this->sendMail($user->email, $config['flow_out']['title'], $config['flow_out']['content']);
            }
        });

        $expire_start_7day = strtotime(date('Y-m-d 00:00:00', strtotime('-7 day')));
        $expire_end_7day = strtotime(date('Y-m-d 23:59:59', strtotime('-7 day')));
        $expire_7day_users = User::query()
            ->where('expired_at', '>', $expire_start_7day)
            ->where('expired_at', '<=', $expire_end_7day)
            ->get();
        $expire_7day_users->each(function ($user) use ($config){
            $this->info("已发送用户已过期7天召回邮件提醒：".$user->email);
            $this->sendMail($user->email, $config['user_expired'][0]['title'], $config['user_expired'][0]['content']);
        });

        $start_15day = strtotime(date('Y-m-d 00:00:00', strtotime('-15 day')));
        $end_15day = strtotime(date('Y-m-d 23:59:59', strtotime('-15 day')));
        $expire_15day_users = User::query()
            ->where('expired_at', '>', $start_15day)
            ->where('expired_at', '<=', $end_15day)
            ->get();
        $expire_15day_users->each(function ($user) use ($config){
            $this->info("已发送用户已过期15天召回邮件提醒：".$user->email);
            $this->sendMail($user->email, $config['user_expired'][1]['title'], $config['user_expired'][1]['content']);
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
