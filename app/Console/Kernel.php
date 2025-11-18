<?php

namespace App\Console;

use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    protected $commands = [];

    protected function schedule(Schedule $schedule)
    {
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 🔧 System Maintenance
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        
        $schedule->call(function () {
            $directory = base_path('storage/logs');
            exec("sudo chown -R www:www " . escapeshellarg($directory));
            exec("sudo chmod -R 775 " . escapeshellarg($directory));
        })->everyMinute()->name('fix-logs-permissions');

        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 📊 Scheduler Heartbeat (Monitoring)
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        
        $schedule->call(function () {
            Cache::put('schedule_last_run', time(), 86400);
            Log::info('✓ Scheduler heartbeat', [
                'timestamp' => now()->format('Y-m-d H:i:s')
            ]);
        })->everyFiveMinutes()->name('scheduler-heartbeat');

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 🚀 V2Board Core Commands
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        
        $schedule->command('traffic:update')
            ->everyMinute()
            ->withoutOverlapping()
            ->name('traffic-update');

        $schedule->command('check:order')
            ->everyMinute()
            ->withoutOverlapping()
            ->name('check-orders');

        $schedule->command('check:ticket')
            ->everyMinute()
            ->name('check-tickets');

        $schedule->command('check:commission')
            ->everyFifteenMinutes()
            ->name('check-commissions');

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 📊 Statistics & Reports
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        
        $schedule->command('v2board:statistics')
            ->dailyAt('0:10')
            ->name('daily-statistics');

        $schedule->command('horizon:snapshot')
            ->everyFiveMinutes()
            ->name('horizon-snapshot');

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 🔄 Daily Maintenance Tasks
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        
        $schedule->command('reset:traffic')
            ->daily()
            ->name('reset-traffic');

        $schedule->command('reset:log')
            ->daily()
            ->name('reset-logs');

        $schedule->command('check:renewal')
            ->dailyAt('22:30')
            ->name('check-renewals');

        $schedule->command('send:remindMail')
            ->dailyAt('11:30')
            ->name('send-reminder-emails');

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // 💳 Payment Recovery System v2.1 (OPTIMIZED)
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        
        /**
         * 🔥 Fast Recovery - Check pending payments every 5 minutes
         * - Verifies successful payments quickly
         * - Refunds after 30 minutes if verification fails
         * - Max 3 inquiry attempts before force refund
         * - Checks last 6 hours (recent orders only)
         */
        $schedule->command('payment:check-pending --refund-after=30 --check-interval=5 --expire-after=30 --max-inquiry-fails=3 --hours=6')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->runInBackground()
            ->name('payment-recovery-fast')
            ->onSuccess(function () {
                Cache::put('payment_recovery_last_success', time(), 3600);
                Cache::put('payment_recovery_last_run', time(), 3600);
                Log::channel('payment')->info('✓ Payment recovery (fast) completed');
            })
            ->onFailure(function () {
                Cache::put('payment_recovery_last_run', time(), 3600);
                Log::channel('payment')->error('✗ Payment recovery (fast) failed');
            });

        /**
         * 🔍 Deep Recovery - Check cancelled orders every hour
         * - Recovers payments incorrectly marked as cancelled
         * - More aggressive retry (5 attempts)
         * - Immediate refund if payment confirmed but verification impossible
         * - Checks last 48 hours
         */
        $schedule->command('payment:check-pending --check-cancelled --refund-after=0 --max-inquiry-fails=5 --hours=48')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->name('payment-recovery-deep')
            ->onSuccess(function () {
                Log::channel('payment')->info('✓ Payment recovery (deep) completed');
            })
            ->onFailure(function () {
                Log::channel('payment')->error('✗ Payment recovery (deep) failed');
            });

        /**
         * 🔎 Daily Audit at 9 AM
         * - Comprehensive check of all payment issues
         * - Generates report of suspicious orders
         * - Checks last 72 hours
         */
        $schedule->command('payment:audit --hours=72')
            ->dailyAt('09:00')
            ->name('payment-audit-daily')
            ->onSuccess(function () {
                Log::channel('payment')->info('✓ Payment audit completed');
            })
            ->onFailure(function () {
                Log::channel('payment')->error('✗ Payment audit failed');
            });

        /**
         * ⏰ Expire old unused payment tracks at 2 AM
         * - Marks unused tracks >48h as used (expired)
         * - Prevents old tracks from being checked in recovery
         * - Runs before cleanup (at 3 AM) to prepare tracks for deletion
         */
        $schedule->call(function () {
            \App\Models\PaymentTrack::expireOld(48);
        })
            ->dailyAt('02:00')
            ->name('expire-old-tracks')
            ->onSuccess(function () {
                Log::channel('payment')->info('✓ Old tracks expired');
            })
            ->onFailure(function () {
                Log::channel('payment')->error('✗ Expire old tracks failed');
            });

        /**
         * 🧹 Cleanup old payment tracks daily at 3 AM
         * - Removes trackIds older than 48 hours
         * - Optimizes database performance
         */
        $schedule->command('payment:cleanup-tracks --hours=48')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->name('payment-tracks-cleanup')
            ->onSuccess(function () {
                Log::channel('payment')->info('✓ Payment tracks cleanup completed');
            })
            ->onFailure(function () {
                Log::channel('payment')->error('✗ Payment tracks cleanup failed');
            });
            
        /**
         * 📊 Payment Health Check - Every 10 minutes
         * - Monitors payment system health
         * - Alerts if recovery jobs are failing or stuck
         * - Early detection of system issues
         */
        $schedule->call(function () {
            $lastRun = Cache::get('payment_recovery_last_run');
            $lastSuccess = Cache::get('payment_recovery_last_success');
            
            // Check if job is running at all
            if (!$lastRun || (time() - $lastRun) > 900) { // 15 minutes
                Log::channel('payment')->critical('🚨 Payment recovery not running!', [
                    'last_run' => $lastRun ? date('Y-m-d H:i:s', $lastRun) : 'never',
                    'minutes_ago' => $lastRun ? floor((time() - $lastRun) / 60) : 'N/A',
                ]);
            }
            
            // Check if job is running but never succeeding
            if ($lastRun && (!$lastSuccess || (time() - $lastSuccess) > 3600)) { // 1 hour
                Log::channel('payment')->warning('⚠️ Payment recovery running but not recovering', [
                    'last_success' => $lastSuccess ? date('Y-m-d H:i:s', $lastSuccess) : 'never',
                    'minutes_ago' => $lastSuccess ? floor((time() - $lastSuccess) / 60) : 'N/A',
                ]);
            }
            
            // Update health check timestamp
            Cache::put('payment_health_check', time(), 3600);
            
        })->everyTenMinutes()->name('payment-health-check');
    }

    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
