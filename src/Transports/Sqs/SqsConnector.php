<?php

namespace Admnio\Sunset\Transports\Sqs;

use Admnio\Sunset\Support\TransportRegistry;
use Illuminate\Queue\Connectors\ConnectorInterface;

class SqsConnector implements ConnectorInterface
{
    public function __construct(private TransportRegistry $transports)
    {
    }

    public function connect(array $config)
    {
        return $this->transports->get('sqs')->connect($config);
    }
}
