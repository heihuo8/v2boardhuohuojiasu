<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use App\Models\MailLog;
use App\Services\TelegramService; // 引入 TelegramService

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $params;

    public $tries = 3;
    public $timeout = 10;
    
    private static $emailSettings = [ // 邮箱配置列表
        [
            'host' => 'smtp.larksuite.com',
            'port' => 465,
            'username' => '账户',
            'password' => '密码',
            'from_address' => '邮箱地址',
            'encryption' => 'SSL'  // 指定加密方式
        ],        // 可以继续添加更多邮箱配置
        [
            'host' => 'smtp.larksuite.com',
            'port' => 465,
            'username' => '账户',
            'password' => '密码',
            'from_address' => '邮箱地址',
            'encryption' => 'SSL'  // 指定加密方式
        ],
    ];
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($params, $queue = 'send_email')
    {
        $this->onQueue($queue);
        $this->params = $params;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (config('v2board.email_host')) {
            if (!empty(self::$emailSettings)) {
                $index = rand(0, count(self::$emailSettings) - 1); // 随机选择一个配置
                $setting = self::$emailSettings[$index];
            } else {
                // 处理没有邮箱配置的情况
                throw new \Exception('No email settings available.');
            }
            
            \Config::set('mail.host', $setting['host']);
            \Config::set('mail.port', $setting['port']);
            \Config::set('mail.encryption',  $setting['encryption']);
            \Config::set('mail.username', $setting['username']);
            \Config::set('mail.password', $setting['password']);
            \Config::set('mail.from.address', $setting['from_address']);
            \Config::set('mail.from.name', config('v2board.app_name', 'V2Board'));
        }
        $params = $this->params;
        $email = $params['email'];
        $subject = $params['subject'];
        // 获取 from_email
        $fromEmail = $setting['from_address'];
        $params['template_name'] = 'mail.' . config('v2board.email_template', 'default') . '.' . $params['template_name'];
        try {
            Mail::send(
                $params['template_name'],
                $params['template_value'],
                function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                }
            );
            // 发送成功消息给管理员
            $telegramService = new TelegramService();
            $adminMessage = "发送邮箱： `{$fromEmail}` \n状态：成功！\n邮件主题：{$subject}\n目标邮箱： `{$email}`";
            $telegramService->sendMessageWithAdmin($adminMessage);
        } catch (\Exception $e) {
            $error = $e->getMessage();
            // 发送错误消息给管理员
            $telegramService = new TelegramService();
            $adminMessage = "发送邮箱： `{$fromEmail}` \n状态：失败！\n邮件主题：{$subject}\n错误信息：{$error}";
            $telegramService->sendMessageWithAdmin($adminMessage);
        }
        
        

        $log = [
            'email' => $params['email'],
            'subject' => $params['subject']. ' (from: ' . $fromEmail . ')',
            'template_name' => $params['template_name'],
            'error' => isset($error) ? $error : NULL
        ];

        MailLog::create($log);
        $log['config'] = config('mail');
        return $log;
    }
}
