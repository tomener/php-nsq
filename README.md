# NSQ publisher library for PHP

This library ONLY publishes messages to NSQ nodes. Requires standard php socket extension.

## Install

Add to composer.json:

``` json
{
    "require": {
        "tomener/php-nsq": "~0.2.0"
    }
}
```

## Usage example

``` php
<?php

include __DIR__ . '/vendor/autoload.php';

use Nsq\NsqPool;
use Nsq\Socket\PhpSocket;
use Nsq\Message\JsonMessage;

$nsq = new NsqPool(
    new PhpSocket('127.0.0.1', 4150),
    new PhpSocket('127.0.0.1', 4170)
);

$msg = [
    'nickname' => 'tomener',
    'sex' => 1
];

//single publish
$nsq->publish('topic_name', new JsonMessage($msg));

//defer publish
$nsq->publish('topic_name', new JsonMessage($msg), 60000); //延迟60秒

//multiple publish
$msgs = [
    new JsonMessage(['nickname' => 'tomener', 'sex' => 1]),
    new JsonMessage(['nickname' => 'lucy', 'sex' => 2]),
];
$nsq->multiPublish('topic_name', $msgs);
```
