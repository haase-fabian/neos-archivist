<?php

declare(strict_types=1);

namespace PunktDe\Archivist\CommandHook;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\CommandHandler\Commands;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\EventStore\PublishedEvents;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use PunktDe\Archivist\Archivist;
use PunktDe\Archivist\SortHierarchyStorage;

class ArchivistCommandHook implements CommandHookInterface
{
    #[Flow\Inject]
    protected SortHierarchyStorage $sortHierarchyStorage;

    #[Flow\InjectConfiguration(path: 'sortingInstructions')]
    protected array $sortingInstructions = [];

    public function __construct(
        private readonly ContentGraphReadModelInterface $contentGraphReadModel,
        private readonly Archivist                      $archivist,
    )
    {
    }

    public function onBeforeHandle(CommandInterface $command): CommandInterface
    {
        return $command;
    }

    public function onAfterHandle(CommandInterface $command, PublishedEvents $events): Commands
    {
        return match ($command::class) {
            CreateNodeAggregateWithNode::class => $this->organizeNode($command->workspaceName, $command->originDimensionSpacePoint, $command->nodeAggregateId, $command->nodeName),
            MoveNodeAggregate::class => $this->organizeNode($command->workspaceName, OriginDimensionSpacePoint::fromDimensionSpacePoint($command->dimensionSpacePoint), $command->nodeAggregateId),
            SetNodeProperties::class => $this->organizeNode($command->workspaceName, $command->originDimensionSpacePoint, $command->nodeAggregateId),
            default => Commands::createEmpty(),
        };
    }

    public function organizeNode(WorkspaceName $workspaceName, OriginDimensionSpacePoint $originDimensionSpacePoint, NodeAggregateId $nodeAggregateId, NodeTypeName $nodeTypeName = null): Commands
    {
        if ($this->archivist->isNodeInProcess($nodeAggregateId)) {
            return Commands::createEmpty();
        }

        $contentSubgraph = $this->contentGraphReadModel
            ->getContentGraph($workspaceName)
            ->getSubgraph($originDimensionSpacePoint->toDimensionSpacePoint(), VisibilityConstraints::createEmpty());

        if ($this->sortHierarchyStorage->hasNode($nodeAggregateId)) {
            $sortingInstruction = $this->sortHierarchyStorage->getSortingInstruction($nodeAggregateId);
            $this->sortHierarchyStorage->removeNode($nodeAggregateId);

            $parent = $contentSubgraph->findParentNode($nodeAggregateId);

            $this->archivist->sendNodeMovedFeedback($parent);
            $this->archivist->sendNodeMovedFeedback($contentSubgraph->findNodeById($nodeAggregateId));

            return Commands::create($this->archivist->sortNode($contentSubgraph, $parent?->aggregateId, $nodeAggregateId, $sortingInstruction));
        }

        $nodeTypeName ??= $contentSubgraph->findNodeById($nodeAggregateId)->nodeTypeName;
        if (!array_key_exists($nodeTypeName?->value, $this->sortingInstructions ?? [])) {
            return Commands::createEmpty();
        }

        $sortingInstructions = $this->sortingInstructions[$nodeTypeName->value];

        if (array_key_exists('hierarchyRoot', $sortingInstructions)) {
            $sortingInstructions = [$sortingInstructions];
        }

        $commands = Commands::createEmpty();
        foreach ($sortingInstructions as $sortingInstruction) {
            $newCommands = $this->archivist->organizeNode($contentSubgraph, $originDimensionSpacePoint, $nodeAggregateId, $sortingInstruction);
            $commands = $commands->merge($newCommands);
        }

        return $commands;
    }

}
