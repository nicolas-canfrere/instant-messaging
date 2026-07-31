<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Console;

use App\Media\Application\Command\PurgeOrphanMediaCommand;
use App\Media\Application\Command\PurgeOrphanMediaCommandHandler;
use App\Shared\Application\Bus\CommandDispatcherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Se lance a la main :
 *
 *     docker compose run --rm backend bin/console media:purge-orphans
 *
 * Pas de planificateur : il n'y en a aucun dans le projet, et en ajouter un
 * pour une seule commande serait de l'infrastructure non justifiee (spec §7.3).
 */
#[AsCommand(
    name: 'media:purge-orphans',
    description: 'Efface les medias que plus aucun message ne porte, octets compris',
)]
final class PurgeOrphanMediaConsoleCommand extends Command
{
    public function __construct(private readonly CommandDispatcherInterface $commands)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->commands->dispatch(new PurgeOrphanMediaCommand());

        // CQS : une commande ne rend rien, donc aucun compte a afficher ici. Le
        // detail — combien, lesquels, et s'il en reste — est dans le journal,
        // ou il sert aussi bien a l'exploitation qu'a l'operateur devant son
        // terminal.
        $io->success(sprintf(
            'Purge lancee (seuil %s, plafond %d medias). Voir le journal pour le detail.',
            PurgeOrphanMediaCommandHandler::AGE_THRESHOLD,
            PurgeOrphanMediaCommandHandler::BATCH_SIZE,
        ));

        return Command::SUCCESS;
    }
}
