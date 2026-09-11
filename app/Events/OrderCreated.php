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
 * Broadcast when a purchase is accepted by the API.
 *
 * The order is still {@see \App\Enums\OrderStatus::PENDING} at this point -
 * the ProcessOrder job will finish processing and dispatch OrderCompleted
 * once the side-effects (email, third-party notification, analytics) settle.
 *
 * Broadcasts on:
 *  - public  : "orders"                (any UI subscribed to flash-sale stream)
 *  - private : "App.Models.User.{id}"  (the buyer; if their account exists)
 *
 * The private channel is only used when an authenticated user created the
 * order; otherwise we fall back to the user_email hash as an opaque channel
 * so subscribers (e.g. a websocket client tracking their own e-mail) still
 * receive updates.
 */
final class OrderCreated implements ShouldBroadcast
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
            // opaque, stable per-customer channel for anonymous flows
            $emailHash = hash('xxh128', (string) $this->order->user_email);
            $channels[] = new PrivateChannel('customers.'.$emailHash);
        }

        return $channels;
    }

    /** Compact payload; avoid leaking full order details on public channel. */
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
            'created_at'      => optional($this->order->created_at)->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.created';
    }
}
