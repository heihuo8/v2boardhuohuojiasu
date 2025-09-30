<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Plan;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Carbon\Carbon; // 引入Carbon库
use Illuminate\Support\Facades\Cache; // 引入 Cache


class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) abort(500, 'verify error');
            if (!$this->handle($verify['trade_no'], $verify['callback_no'])) {
                abort(500, 'handle error');
            }
            return(isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            abort(500, 'fail');
        }
    }

    private function handle($tradeNo, $callbackNo)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            abort(500, 'order is not found');
        }
        if ($order->status !== 0) return true;
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }
        //获取支付方式和接口
        $payment = Payment::where('id', $order->payment_id)->first();
        // 获取用户邮箱
        $user = User::find($order->user_id);
        $userEmail = $user ? $user->email : '未知';
        // 获取套餐信息
        $planName = $order->plan_id ? Plan::find($order->plan_id)->name : '未知套餐';
        $planPeriod = $this->getPeriodText($order->period);
        
        // 获取优惠券信息
        $couponCode = Cache::get('order_coupon_' . $order->trade_no, '无'); // 从缓存中获取
        
        // 从缓存中获取 referer 信息
        $refererDomain = Cache::get('order_referer_' . $order->trade_no, '客户端');
        
        // 获取今日的日期范围并转换为时间戳
        $todayStart = Carbon::today()->startOfDay()->timestamp; // 开始时间戳
        $todayEnd = Carbon::today()->endOfDay()->timestamp; // 结束时间戳
        
        // 计算今日总收入
        $totalIncomeToday = Order::whereNotNull('callback_no') // callback_no 不为 null
            ->whereBetween('created_at', [$todayStart, $todayEnd]) // created_at 在今天范围内
            ->sum('total_amount') / 100; // 转换为元
            
        //获取邀请人邮箱信息
        $inviteUser = User::find($order->invite_user_id);
        $inviteUserEmail = $inviteUser ? $inviteUser->email : '无';
        
        
        $telegramService = new TelegramService();
        $message = sprintf(
            "💰 成功收款%s元\n———————————————\n🌐 支付接口：%s\n🏦 支付渠道：%s\n📧 用户邮箱：`%s`\n📦 购买套餐：%s\n📅 套餐周期：%s\n🎫 优  惠  券：`%s`\n👥 邀  请  人：`%s`\n🆔 订  单  号：`%s`\n🌐 来源网址：`%s`\n———————————————\n💵 今日总收入：%s元",
            $order->total_amount / 100,
            $payment->payment,
            $payment->name,
            $userEmail,
            $planName,
            $planPeriod,
            $couponCode, // 在消息中包含优惠券信息
            $inviteUserEmail,
            $order->trade_no,
            $refererDomain,
            $totalIncomeToday
        );
        $telegramService->sendMessageWithAdmin($message);
        return true;
    }
    private function getPeriodText($period)
    {
        switch ($period) {
            case 'month_price':
                return '月付';
            case 'quarter_price':
                return '季付';
            case 'half_year_price':
                return '半年付';
            case 'year_price':
                return '年付';
            case 'two_year_price':
                return '两年付';
            case 'three_year_price':
                return '三年付';
            case 'onetime_price':
                return '不限时';
            case 'reset_price':
                return '重置流量';
            default:
                return '未知周期';
        }
    }
}
