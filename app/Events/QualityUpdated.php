<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QualityUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $qualityData;

    public function __construct($qualityData = null)
    {
        $this->qualityData = $qualityData;
    }

    public function broadcastOn()
    {
        return new Channel('quality-channel');
    }

    public function broadcastAs()
    {
        return 'quality-updated';
    }
}
