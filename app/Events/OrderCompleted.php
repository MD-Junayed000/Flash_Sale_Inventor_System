<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast once an order has finished its async processing pipeline.
 *
 * Dispatched from {@see \App\Jobs\ProcessOrder::handle()} after side-effects
 * (analytics, email, fulfilment) complete successfully, or from the job's
 * failed() hook (with status = FAILED) so dashboards can reflect the
 * terminal state.
 *
 * Channels are the same as {@see OrderCreated} so a websocket client only
 * needs to subscribe once.
 */
final class OrderCompleted implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}

    /** @return array<int, Channel|PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new Channel('orders')];

        $userId = $this->order->user_id;
        if ($userId !== null) {
            $channels[] = new PrivateChannel('App.Models.User.'.$userId);
        } else {
            $emailHash = hash('xxh128', (string) $this->order->user_email);
            $channels[] = new PrivateChannel('customers.'.$emailHash);
        }

        return $channels;
    }

    public function broadcastWith(): array
    {
        return [
            'order_id'        => $this->order->id,
            'invoice_number'  => $this->order->invoice_number,
            'sku'             => $this->order->sku,
            'quantity'        => $this->order->quantity,
            'payable_amount'  => (string) $this->order->payable_amount,
            'discount_pct'    => (int)   $this->order->discount_percentage,
            'status'          => $this->order->status,
            'completed_at'    => optional($this->order->updated_at)->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.completed';
    }
}
