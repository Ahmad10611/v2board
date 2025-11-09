<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use App\Models\PaymentTrack;
use App\Payments\ZibalPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CheckPendingPayments extends Command
{
    protected $signature = 'payment:check-pending 
                            {--refund-after=30 : Minutes until auto refund}
                            {--check-interval=5 : Minimum minutes between checks}
                            {--expire-after=30 : Minutes until order expiration}
                            {--check-cancelled : Check cancelled orders}
                            {--check-expired : Check expired orders}
                            {--hours=24 : Check orders from last N hours}
                            {--max-inquiry-fails=3 : Max inquiry failures before force refund}
                            {--debug : Show detailed output}';

    protected $description = 'Check and recover pending payments v2.3 - Fixed status 3 & 4 handling';

    public function handle()
    {
        $refundAfter = (int) $this->option('refund-after');
        $checkInterval = (int) $this->option('check-interval');
        $expireAfter = (int) $this->option('expire-after');
        $checkCancelled = $this->option('check-cancelled');
        $checkExpired = $this->option('check-expired');
        $hours = (int) $this->option('hours');
        $maxInquiryFails = (int) $this->option('max-inquiry-fails');
        $debug = $this->option('debug');

        if ($debug) {
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("🔍 Payment Recovery System v2.3");
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("Refund after: {$refundAfter} min");
            $this->info("Check interval: {$checkInterval} min");
            $this->info("Expire after: {$expireAfter} min");
            $this->info("Max inquiry fails: {$maxInquiryFails}");
            $this->info("Check hours: {$hours}");
            $this->info("Check cancelled: " . ($checkCancelled ? 'YES' : 'NO'));
            $this->info("Check expired: " . ($checkExpired ? 'YES' : 'NO'));
        }

        $stats = [
            'checked' => 0,
            'verified' => 0,
            'refunded' => 0,
            'expired' => 0,
            'cancelled' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        // Determine statuses to check
        $statusesToCheck = [0]; // Always check pending

        if ($checkCancelled) {
            $statusesToCheck[] = 2; // cancelled
        }

        if ($checkExpired) {
            $statusesToCheck[] = 4; // refunded (double check)
        }

        if ($debug) {
            $this->info("Checking statuses: " . implode(', ', $statusesToCheck));
        }

        // Get orders to check
        $pendingOrders = Order::whereIn('status', $statusesToCheck)
            ->where('created_at', '>=', now()->subHours($hours))
            ->orderBy('created_at', 'desc')
            ->get();

        if ($debug) {
            $this->info("\nFound {$pendingOrders->count()} orders to check");
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n");
        }

        foreach ($pendingOrders as $order) {
            $stats['checked']++;

            // Get trackId from database or cache
            // Try trade_no first (more reliable), then order_id as fallback
            $trackFromDb = PaymentTrack::where('trade_no', $order->trade_no)->first();
            
            if (!$trackFromDb) {
                $trackFromDb = PaymentTrack::where('order_id', $order->id)
                    ->where('order_id', '>', 0)
                    ->first();
            }
            
            $trackIdFromDb = $trackFromDb ? $trackFromDb->track_id : null;
            $trackIdFromCache = cache()->get("zibal_track_{$order->trade_no}");
            $trackId = $trackIdFromDb ?: $trackIdFromCache;

            if (!$trackId) {
                if ($debug) {
                    $this->line("⏭ Order {$order->trade_no}: No trackId found");
                }
                $stats['skipped']++;
                
                // Expire orders without trackId if too old
                $orderAge = now()->diffInMinutes(\Carbon\Carbon::parse($order->created_at));
                if ($orderAge >= $expireAfter && $order->status == 0) {
                    $this->expireOrder($order);
                    $stats['expired']++;
                }
                
                continue;
            }

            // Rate limiting check
            $lastCheckKey = "payment_last_check_{$order->id}";
            $lastCheck = Cache::get($lastCheckKey, 0);
            
            if ($lastCheck && (time() - $lastCheck) < ($checkInterval * 60)) {
                if ($debug) {
                    $remaining = ($checkInterval * 60) - (time() - $lastCheck);
                    $this->line("⏭ Order {$order->trade_no}: Too soon ({$remaining}s)");
                }
                $stats['skipped']++;
                continue;
            }

            Cache::put($lastCheckKey, time(), 3600);

            // Calculate order age
            $orderAge = now()->diffInMinutes(\Carbon\Carbon::parse($order->created_at));

            if ($debug) {
                $this->info("\n📋 Order: {$order->trade_no}");
                $this->line("  ID: {$order->id}");
                $this->line("  TrackID: {$trackId}");
                $this->line("  Status: {$order->status}");
                $this->line("  Age: {$orderAge} min");
                $this->line("  Amount: " . number_format($order->total_amount) . " تومان");
            }

            try {
                $paymentConfig = $this->getPaymentConfig();
                
                if (!$paymentConfig) {
                    if ($debug) {
                        $this->error("  ✗ Payment config not found");
                    }
                    $stats['failed']++;
                    continue;
                }

                $zibal = new ZibalPayment($paymentConfig);
                $inquiry = $zibal->inquiry($trackId);

                // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                // ✅ Track inquiry failures
                // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                $failCountKey = "inquiry_fail_{$order->id}";
                
                if ($inquiry === false) {
                    $failCount = Cache::get($failCountKey, 0) + 1;
                    Cache::put($failCountKey, $failCount, 3600);
                    
                    if ($debug) {
                        $this->warn("  ⚠ Inquiry failed (attempt {$failCount}/{$maxInquiryFails})");
                    }
                    
                    // Force refund after max failures AND sufficient age
                    if ($failCount >= $maxInquiryFails && $orderAge >= $refundAfter) {
                        if ($debug) {
                            $this->warn("  ⚠ Max inquiry fails + old order → forcing refund...");
                        }
                        
                        if ($this->refundToWallet($order, $trackId, 'inquiry_failed_max_retries')) {
                            $this->info("  ✓ Force refunded to wallet");
                            Cache::forget($failCountKey);
                            $stats['refunded']++;
                        } else {
                            $this->error("  ✗ Force refund failed");
                            $stats['failed']++;
                        }
                    } else {
                        $stats['failed']++;
                    }
                    continue;
                }

                // Clear fail count on successful inquiry
                Cache::forget($failCountKey);

                $status = $inquiry['status'] ?? null;

                if ($debug) {
                    $this->line("  Zibal Status: {$status}");
                    if (isset($inquiry['paidAt'])) {
                        $this->line("  Paid At: {$inquiry['paidAt']}");
                    }
                }

                // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                // Status 1 or 2 = successful payment at Zibal
                // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                if (in_array($status, [1, 2])) {
                    if ($debug) {
                        $this->info("  💰 Payment successful at Zibal!");
                    }

                    if ($order->status != 3) {
                        // Try to verify and complete order
                        $verifyResult = $this->attemptVerify($order, $trackId, $zibal);

                        if ($verifyResult) {
                            $this->info("  ✓✓ Verified and order completed!");
                            $stats['verified']++;
                        } else {
                            // Verify failed, refund to wallet if old enough
                            if ($orderAge >= $refundAfter) {
                                if ($debug) {
                                    $this->warn("  ⚠ Verify failed → refunding to wallet...");
                                }

                                if ($this->refundToWallet($order, $trackId, 'verify_failed')) {
                                    $this->info("  ✓ Refunded to wallet");
                                    $stats['refunded']++;
                                } else {
                                    $this->error("  ✗ Refund failed");
                                    $stats['failed']++;
                                }
                            } else {
                                if ($debug) {
                                    $remaining = $refundAfter - $orderAge;
                                    $this->line("  ⏳ Waiting {$remaining} min before refund");
                                }
                            }
                        }
                    } else {
                        if ($debug) {
                            $this->line("  ✓ Already paid (status=3)");
                        }
                    }
                } 
                // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                // Status -1 = Payment not initiated/cancelled before gateway
                // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                else if ($status === -1) {
                    if ($debug) {
                        $this->line("  🚫 Payment not initiated (status: -1)");
                    }

                    if ($checkCancelled && $order->status == 0) {
                        try {
                            $order->status = 2; // cancelled (no money charged)
                            $order->save();
                            
                            if ($debug) {
                                $this->info("  ✓ Order marked as cancelled");
                            }
                            
                            // Delete unused track
                            $track = PaymentTrack::where('trade_no', $order->trade_no)->first();
                            if ($track && !$track->is_used) {
                                $track->delete();
                                if ($debug) {
                                    $this->line("  ✓ Unused track deleted");
                                }
                            }
                            
                            $stats['cancelled']++;
                        } catch (\Exception $e) {
                            $this->error("  ✗ Failed to mark as cancelled: " . $e->getMessage());
                            $stats['failed']++;
                        }
                    } else {
                        $stats['skipped']++;
                    }
                } 
                // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                // 🆕 Status 3 = Cancelled by user at gateway
                // CRITICAL: No money was charged, so NO REFUND needed
                // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                else if ($status === 3) {
                    if ($debug) {
                        $this->line("  🚫 Payment cancelled by user at gateway (status: 3)");
                    }

                    // Only mark as cancelled - NO WALLET REFUND
                    if ($order->status == 0) {
                        try {
                            $order->status = 2; // cancelled (no money charged)
                            $order->save();
                            
                            if ($debug) {
                                $this->info("  ✓ Order marked as cancelled (no refund needed)");
                            }
                            
                            // Delete unused track to prevent future checks
                            $track = PaymentTrack::where('trade_no', $order->trade_no)->first();
                            if ($track && !$track->is_used) {
                                $track->delete();
                                if ($debug) {
                                    $this->line("  ✓ Unused track deleted");
                                }
                            }
                            
                            $stats['cancelled']++;
                        } catch (\Exception $e) {
                            $this->error("  ✗ Failed to cancel: " . $e->getMessage());
                            $stats['failed']++;
                        }
                    } else {
                        if ($debug) {
                            $this->line("  ⏭ Already processed (status={$order->status})");
                        }
                        $stats['skipped']++;
                    }
                } 
                // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                // 🆕 Status 4 = Payment failed/returned
                // CRITICAL: Money was not successfully charged, NO REFUND
                // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                else if ($status === 4) {
                    if ($debug) {
                        $this->line("  💳 Payment failed/returned at gateway (status: 4)");
                    }

                    // Mark as cancelled - NO WALLET REFUND
                    if ($order->status == 0) {
                        try {
                            $order->status = 2; // cancelled (payment failed)
                            $order->save();
                            
                            if ($debug) {
                                $this->info("  ✓ Order marked as cancelled (payment failed)");
                            }
                            
                            // Delete unused track
                            $track = PaymentTrack::where('trade_no', $order->trade_no)->first();
                            if ($track && !$track->is_used) {
                                $track->delete();
                                if ($debug) {
                                    $this->line("  ✓ Unused track deleted");
                                }
                            }
                            
                            $stats['cancelled']++;
                        } catch (\Exception $e) {
                            $this->error("  ✗ Failed to cancel: " . $e->getMessage());
                            $stats['failed']++;
                        }
                    } else {
                        $stats['skipped']++;
                    }
                }
                // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                // Status 0 = Still pending at Zibal
                // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                else if ($status === 0) {
                    if ($debug) {
                        $this->line("  ⏳ Payment still pending at Zibal (status: 0)");
                    }

                    // Expire old pending orders
                    if ($orderAge >= $expireAfter && $order->status == 0) {
                        $this->expireOrder($order);
                        $stats['expired']++;
                    }
                } 
                // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                // Unknown status - only refund if payment was successful
                // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                else {
                    if ($debug) {
                        $this->warn("  ⚠ Unknown Zibal status: {$status}");
                    }
                    
                    // Only refund if order is old enough AND we can't determine status
                    if ($orderAge >= $refundAfter) {
                        if ($debug) {
                            $this->warn("  ⚠ Unknown status + old order → forcing refund...");
                        }
                        
                        if ($this->refundToWallet($order, $trackId, 'unknown_status')) {
                            $this->info("  ✓ Force refunded to wallet");
                            $stats['refunded']++;
                        } else {
                            $this->error("  ✗ Force refund failed");
                            $stats['failed']++;
                        }
                    }
                }

            } catch (\Exception $e) {
                Log::channel('payment')->error('Payment recovery error', [
                    'order_id' => $order->id,
                    'trade_no' => $order->trade_no,
                    'track_id' => $trackId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                if ($debug) {
                    $this->error("  ✗ Exception: " . $e->getMessage());
                }

                $stats['failed']++;
            }
        }

        if ($debug) {
            $this->info("\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("📊 Summary:");
            $this->line("  Checked: {$stats['checked']}");
            $this->line("  Verified: {$stats['verified']}");
            $this->line("  Refunded: {$stats['refunded']}");
            $this->line("  Expired: {$stats['expired']}");
            $this->line("  Cancelled: {$stats['cancelled']}");
            $this->line("  Skipped: {$stats['skipped']}");
            $this->line("  Failed: {$stats['failed']}");
            $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        }

        Log::channel('payment')->info('Payment recovery completed', $stats);

        return 0;
    }

    private function attemptVerify(Order $order, string $trackId, ZibalPayment $zibal): bool
    {
        try {
            $params = [
                'trackId' => $trackId,
                'orderId' => $order->trade_no,
                'success' => 1,
            ];

            $result = $zibal->notify($params);

            if ($result && isset($result['trade_no'])) {
                // Update order manually since handleOrder might skip cancelled orders
                if ($order->status !== 3) {
                    $order->status = 3;
                    $order->paid_at = time();
                    $order->updated_at = time();
                    $order->save();
                    
                    Log::channel('payment')->info('✓ Order updated in recovery', [
                        'order_id' => $order->id,
                        'track_id' => $trackId,
                        'status' => 3,
                    ]);
                }
                
                return true;
            }

            Log::channel('payment')->warning('Verify returned false in recovery', [
                'order_id' => $order->id,
                'track_id' => $trackId,
                'result' => $result,
            ]);

            return false;

        } catch (\Exception $e) {
            Log::channel('payment')->error('Verify attempt failed', [
                'order_id' => $order->id,
                'track_id' => $trackId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function refundToWallet(Order $order, string $trackId, string $reason = 'recovery'): bool
    {
        try {
            DB::beginTransaction();

            $user = User::lockForUpdate()->find($order->user_id);

            if (!$user) {
                throw new \Exception("User not found: {$order->user_id}");
            }

            $oldBalance = $user->balance;
            $user->balance += $order->total_amount;
            $user->save();

            // Set status = 4 (refunded to wallet)
            $order->status = 4;
            $order->save();

            // Mark trackId as used
            $track = PaymentTrack::where('track_id', $trackId)->first();
            if ($track && !$track->is_used) {
                $track->markAsUsed();
            }

            cache()->forget("zibal_track_{$order->trade_no}");

            DB::commit();

            Log::channel('payment')->info('✓ Order refunded to wallet', [
                'order_id' => $order->id,
                'trade_no' => $order->trade_no,
                'track_id' => $trackId,
                'user_id' => $user->id,
                'amount' => $order->total_amount,
                'old_balance' => $oldBalance,
                'new_balance' => $user->balance,
                'reason' => $reason,
                'status_set' => 4,
            ]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::channel('payment')->error('✗ Refund failed', [
                'order_id' => $order->id,
                'track_id' => $trackId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    private function expireOrder(Order $order): bool
    {
        try {
            $order->status = 2; // cancelled (no refund needed - payment never succeeded)
            $order->save();

            cache()->forget("zibal_track_{$order->trade_no}");

            Log::channel('payment')->info('Order expired', [
                'order_id' => $order->id,
                'trade_no' => $order->trade_no,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::channel('payment')->error('Expire failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function getPaymentConfig(): ?array
    {
        $config = config('v2board.zibal');
        
        if ($config && isset($config['zibal_merchant'])) {
            return $config;
        }

        try {
            // Try multiple payment method names
            $paymentNames = ['ZibalPayment', 'ZibalPay', 'Zibal'];
            
            foreach ($paymentNames as $name) {
                $payment = DB::table('v2_payment')
                    ->where('payment', $name)
                    ->where('enable', 1)
                    ->first();

                if ($payment && $payment->config) {
                    $config = json_decode($payment->config, true);
                    if ($config && isset($config['zibal_merchant'])) {
                        return $config;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to get payment config', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
