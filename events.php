<?php

declare(strict_types=1);

use App\CallableEventDispatcherInterface;
use Plugin\AzuracastShowDownloader\EventHandler\PluginSubscriber;

return static function (CallableEventDispatcherInterface $dispatcher): void {
    $dispatcher->addServiceSubscriber(PluginSubscriber::class);
};
