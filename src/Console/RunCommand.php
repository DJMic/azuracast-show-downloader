<?php

declare(strict_types=1);

namespace Plugin\AzuracastShowDownloader\Console;

use App\Entity\Station;
use App\Container\EntityManagerAwareTrait;
use Plugin\AzuracastShowDownloader\Service\Executor;
use Plugin\AzuracastShowDownloader\Service\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * docker compose exec web azuracast_cli show-downloader:run <station_id>            (list jobs)
 * docker compose exec web azuracast_cli show-downloader:run <station_id> <job_id>   (download now)
 */
#[AsCommand(
    name: self::NAME,
    description: 'Liste les émissions d\'une station ou lance immédiatement un téléchargement.'
)]
final class RunCommand extends Command
{
    use EntityManagerAwareTrait;

    public const string NAME = 'show-downloader:run';

    public function __construct(
        private readonly Executor $executor,
        private readonly Store $store
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('station', InputArgument::REQUIRED, 'ID ou nom court de la station')
            ->addArgument('job', InputArgument::OPTIONAL, 'ID de l\'émission à télécharger');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = (string)$input->getArgument('station');
        $repo = $this->em->getRepository(Station::class);
        $station = ctype_digit($key) ? $repo->find((int)$key) : $repo->findOneBy(['short_name' => $key]);
        if (!$station instanceof Station) {
            $io->error('Station introuvable : ' . $key);

            return 1;
        }

        $jobId = $input->getArgument('job');
        if ($jobId === null) {
            $rows = array_map(static fn(array $job): array => [
                $job['id'],
                $job['name'],
                $job['source']['type'],
                $job['enabled'] ? 'oui' : 'non',
            ], $this->store->read($station)['jobs']);
            $io->table(['ID', 'Émission', 'Source', 'Active'], $rows);

            return 0;
        }

        $result = $this->executor->runNow($station, (string)$jobId);
        if ($result['status'] === 'success') {
            $io->success($result['message']);

            return 0;
        }
        $io->warning('[' . $result['status'] . '] ' . $result['message']);

        return $result['status'] === 'skipped' ? 0 : 1;
    }
}
