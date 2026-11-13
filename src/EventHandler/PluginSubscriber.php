<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\EventHandler;

use App\Enums\StationPermissions;
use App\Event\BuildConsoleCommands;
use App\Event\BuildRoutes;
use App\Event\GetSyncTasks;
use App\Middleware;
use Plugin\AzuracastShowDownloader\Console\RunCommand;
use Plugin\AzuracastShowDownloader\Controller\PanelController;
use Plugin\AzuracastShowDownloader\Middleware\InjectNavigation;
use Plugin\AzuracastShowDownloader\Sync\DownloadTask;
use Slim\Routing\RouteCollectorProxy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class PluginSubscriber implements EventSubscriberInterface
{
    public const string ROUTE_PREFIX = '/show-downloader';

    public static function getSubscribedEvents(): array
    {
        return [
            BuildRoutes::class => 'routes',
            GetSyncTasks::class => 'syncTasks',
            BuildConsoleCommands::class => 'commands',
        ];
    }

    public function syncTasks(GetSyncTasks $event): void
    {
        $event->addTask(DownloadTask::class);
    }

    public function commands(BuildConsoleCommands $event): void
    {
        $event->addAliases([RunCommand::NAME => RunCommand::class]);
    }

    public function routes(BuildRoutes $event): void
    {
        $app = $event->getApp();

        // Adds the station sidebar entry to AzuraCast's own pages.
        $app->add(new InjectNavigation($app->getBasePath()));

        $app->get(
            self::ROUTE_PREFIX . '/assets/{name:(?:navigation|app)[.](?:js|css)}',
            PanelController::class . ':assetAction'
        );

        $app->group(
            self::ROUTE_PREFIX . '/{station_id}',
            function (RouteCollectorProxy $group): void {
                $group->get('/state', PanelController::class . ':stateAction');
                $group->get('/folders', PanelController::class . ':foldersAction');
                $group->get('/history', PanelController::class . ':historyAction');
                $group->post('/jobs', PanelController::class . ':saveAction');
                $group->post('/jobs/{job_id}/delete', PanelController::class . ':deleteAction');
                $group->post('/jobs/{job_id}/run', PanelController::class . ':runAction');
                $group->post('/test', PanelController::class . ':testAction');
                $group->post('/history/clear', PanelController::class . ':clearHistoryAction');
            }
        )
            ->add(new Middleware\Permissions(StationPermissions::Media, true))
            ->add(Middleware\RequireStation::class)
            ->add(Middleware\GetStation::class)
            ->add(Middleware\RequireLogin::class)
            ->add(Middleware\Auth\StandardAuth::class)
            ->add(Middleware\InjectSession::class);
    }
}
