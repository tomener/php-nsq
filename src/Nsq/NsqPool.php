<?php

namespace Nsq;

use Nsq\Message\MessageInterface;
use Nsq\Exception\SocketException;
use Nsq\Exception\PubException;
use Nsq\Socket\SocketInterface;

class NsqPool
{
    /**
     * Half + 1 of pool connections must receive a message
     */
    const NSQ_QUORUM = 'quorum';

    /**
     * At least one connection must receive message
     */
    const NSQ_AT_LEAST_ONE = 'at_least_one';

    /**
     * At most one connection can receive a message
     */
    const NSQ_ONLY_ONE = 'only_one';

    /**
     * All connections must receive a message
     */
    const NSQ_ALL = 'all';

    /**
     * @var array
     */
    protected $connections = array();

    protected $strategy;

    /**
     * May take a connection list as separate arguments
     */
    public function __construct()
    {
        // ensure that constructor arguments may be only a list of connections
        $this->connections = array_map(function (SocketInterface $connection) {
            return $connection;
        }, func_get_args());
        $this->strategy = self::NSQ_AT_LEAST_ONE;
    }

    /**
     * Add a socket connection to NSQ node
     *
     * @param SocketInterface $connection
     * @return NsqPool
     */
    public function addConnection(SocketInterface $connection)
    {
        $this->connections[] = $connection;
        return $this;
    }

    /**
     * Publish a message to NSQ
     *
     * @param $topic
     * @param MessageInterface $msg
     * @param int $defer millisecond
     */
    public function publish($topic, MessageInterface $msg, $defer = 0)
    {
        $this->doPublish($topic, array($msg), $this->strategy, $defer);
    }

    /**
     * Publish multiple messages to NSQ
     *
     * @param string $topic
     * @param array $msgs - elements are instance of \Nsq\Message\MessageInterface
     * @param string $strategy
     * @return void
     */
    public function multiPublish($topic, array $msgs, $strategy = self::NSQ_AT_LEAST_ONE)
    {
        $this->doPublish($topic, $msgs, $strategy);
    }

    /**
     * 设置策略
     *
     * @param $strategy
     */
    public function strategy($strategy)
    {
        $this->strategy = $strategy;
    }

    /**
     * Does the actual publishing work
     *
     * @param string $topic
     * @param array $msgs
     * @param string $strategy
     * @param string $defer
     *
     * @throws PubException - if strategy requirements are not met
     */
    protected function doPublish($topic, array $msgs, $strategy, $defer = 0)
    {
        $success = 0;
        $errs = array();
        if (count($this->connections) === 0) {
            $errs[] = "There are no NSQ connections in the pool.";
        }
        foreach ($this->connections as $connection) {
            try {
                if (count($msgs) > 1) {
                    $response = $connection->multiPublish($topic, $msgs);
                } else {
                    if ($defer == 0) {
                        $response = $connection->publish($topic, $msgs[0]);
                    } else {
                        $response = $connection->deferPublish($topic, $msgs[0], $defer);
                    }
                }
                if ($response->isOk()) {
                    $success++;
                }
                $errs[] = "{$connection} -> {$response->code()}";
                if (self::NSQ_ONLY_ONE === $strategy && $success === 1) {
                    return; // one node has received a message
                }
            } catch(SocketException $e) {
                // do nothing here, does not increment success count
                $errs[] = "{$connection} -> has failed with socket exception: {$e->getMessage()}.";
            }
        }
        if ($strategy === self::NSQ_QUORUM) {
            $required = ceil(count($this->connections) / 2) + 1;
        } elseif ($strategy === self::NSQ_ALL) {
            $required = count($this->connections);
        } else {
            $required = 1; // defaults to at least one
        }
        if ($required > $success) {
            throw new PubException("Required at least {$required} nodes to be successful, but only {$success} were, details:\n\t".implode("\n\t", $errs));
        }
    }
}
