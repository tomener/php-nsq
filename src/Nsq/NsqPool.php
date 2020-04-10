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

    /**
     * May take a connection list as separate arguments
     */
    public function __construct()
    {
        // ensure that constructor arguments may be only a list of connections
        $this->connections = array_map(function (SocketInterface $connection) {
            return $connection;
        }, func_get_args());
    }

    /**
     * Add a socket connection to NSQ node
     *
     * @param SocketInterface $connection
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
     * @param string $strategy
     */
    public function publish($topic, MessageInterface $msg, $strategy = self::NSQ_AT_LEAST_ONE)
    {
        $this->doPublish($topic, array($msg), $strategy);
    }

    /**
     * Publish a defer message to NSQ
     *
     * @param $topic
     * @param MessageInterface $msg
     * @param $defer
     * @param string $strategy
     */
    public function publishDefer($topic, MessageInterface $msg, $defer, $strategy = self::NSQ_AT_LEAST_ONE)
    {
        $this->doPublish($topic, array($msg), $strategy, $defer);
    }

    /**
     * Publish multiple messages to NSQ
     *
     * @param string $topic
     * @param array $msgs - elements are instance of \Nsq\Message\MessageInterface
     * @param string $strategy
     * @return void
     */
    public function mpublish($topic, array $msgs, $strategy = self::NSQ_AT_LEAST_ONE)
    {
        $this->doPublish($topic, $msgs, $strategy);
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
                    $response = $connection->mpublish($topic, $msgs);
                } else {
                    if ($defer == 0) {
                        $response = $connection->publish($topic, $msgs[0]);
                    } else {
                        $response = $connection->publishDefer($topic, $msgs[0], $defer);
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

