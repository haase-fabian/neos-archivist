<?php

declare(strict_types=1);

namespace PunktDe\Archivist\CommandHook;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\Factory\CommandHookFactoryInterface;
use Neos\ContentRepository\Core\Factory\CommandHooksFactoryDependencies;
use Neos\Flow\Annotations as Flow;
use PunktDe\Archivist\Archivist;

class ArchivistCommandHookFactory implements CommandHookFactoryInterface
{

    public function build(CommandHooksFactoryDependencies $commandHooksFactoryDependencies): CommandHookInterface
    {
        $contentGraphReadModel = $commandHooksFactoryDependencies->contentGraphReadModel;
        $nodeTypeManager = $commandHooksFactoryDependencies->nodeTypeManager;

        return new ArchivistCommandHook(
            $contentGraphReadModel,
            new Archivist($nodeTypeManager),
        );
    }
}

