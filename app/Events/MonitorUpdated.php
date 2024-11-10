<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MonitorUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $monitorData;

    public function __construct($monitorData = null)
    {
        $this->monitorData = $monitorData;
    }

    public function broadcastOn()
    {
        return new Channel('monitor-channel');
    }

    public function broadcastAs()
    {
        return 'monitor-updated';
    }
}
