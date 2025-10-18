<?php

namespace PunktDe\Archivist;

/*
 * This file is part of the PunktDe.Archivist package.
 *
 * This package is open source software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\CommandHandler\Commands;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeMove\Dto\RelationDistributionStrategy;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Eel\Exception;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Neos\Ui\Domain\Model\Feedback\Operations\UpdateNodeInfo;
use Neos\Neos\Ui\Domain\Model\FeedbackCollection;
use Psr\Log\LoggerInterface;
use PunktDe\Archivist\Exception\ArchivistConfigurationException;
use PunktDe\Archivist\Service\EelEvaluationService;
use PunktDe\Archivist\Service\HierarchyService;
use PunktDe\Archivist\Service\SortingService;

class Archivist
{
    #[Flow\Inject]
    protected HierarchyService $hierarchyService;

    #[Flow\Inject]
    protected EelEvaluationService $eelEvaluationService;

    #[Flow\Inject]
    protected SortingService $sortingService;

    #[Flow\Inject]
    protected LoggerInterface $logger;

    #[Flow\Inject]
    protected ThrowableStorageInterface $throwableStorage;

    protected array $nodesInProcessing = [];

    #[Flow\Inject]
    protected FeedbackCollection $feedbackCollection;

    public function __construct(protected ?NodeTypeManager $nodeTypeManager = null)
    {
    }


    /**
     * @throws ArchivistConfigurationException
     * @throws Exception
     */
    public function organizeNode(ContentSubgraphInterface $contentSubgraph, OriginDimensionSpacePoint $originDimensionSpacePoint, NodeAggregateId $triggeringNodeAggregateId, array $sortingInstructions): Commands
    {

        if (!$triggeringNode = $contentSubgraph->findNodeById($triggeringNodeAggregateId)) {
            throw new \InvalidArgumentException($triggeringNodeAggregateId);
        }

        if (isset($sortingInstructions['condition'])) {
            $condition = $this->eelEvaluationService->evaluate($sortingInstructions['condition'], ['node' => $triggeringNode]);
            if ($condition !== true) {
                return Commands::createEmpty();
            }
        }

        /** @var Node $affectedNode */
        if (isset($sortingInstructions['affectedNode'])) {
            $affectedNode = $this->eelEvaluationService->evaluate($sortingInstructions['affectedNode'], ['node' => $triggeringNode]);

            if (!($affectedNode instanceof Node)) {
                $this->logger->info(sprintf('A node of type %s (%s) triggered node organization but the affectedNode was not found.', $triggeringNode->nodeTypeName, $triggeringNode->aggregateId), LogEnvironment::fromMethodName(__METHOD__));
                return Commands::createEmpty();
            }
        } else {
            $affectedNode = $triggeringNode;
        }
        $this->sendNodeMovedFeedback($affectedNode);
        $parentNodeAggregateId = $contentSubgraph->findParentNode($affectedNode->aggregateId)->aggregateId;

        /** @var Node $affectedNode */
        $affectedNodeAggregateId = $affectedNode->aggregateId;

        $this->lockNodeForProcessing($affectedNodeAggregateId);

        $commands = Commands::createEmpty();
        $moveNodeProperties = [];

        $this->logger->info(sprintf('Organizing node of type %s (%s)', $affectedNode->nodeTypeName, $affectedNode->aggregateId), LogEnvironment::fromMethodName(__METHOD__));
        $context = $this->buildBaseContext($contentSubgraph, $triggeringNode, $sortingInstructions);

        if (isset($sortingInstructions['context']) && is_array($sortingInstructions['context'])) {
            $context = $this->buildCustomContext($context, $sortingInstructions['context']);
        }

        if (isset($sortingInstructions['hierarchy']) && is_array($sortingInstructions['hierarchy']) && $this->nodeTypeManager !== null) {
            [$hierarchyNodeAggregateId, $newCommands] = $this->hierarchyService->buildHierarchy($contentSubgraph, $this->nodeTypeManager, $originDimensionSpacePoint, $sortingInstructions['hierarchy'], $context, $sortingInstructions['publishHierarchy'] ?? false);
            $commands = $commands->merge($newCommands);

            if ($hierarchyNodeAggregateId !== $parentNodeAggregateId) {
                $this->sendNodeMovedFeedback($contentSubgraph->findNodeById($parentNodeAggregateId));
                $this->sendNodeMovedFeedback($contentSubgraph->findNodeById($hierarchyNodeAggregateId));

                $moveNodeProperties += ['newParentNodeAggregateId' => $hierarchyNodeAggregateId];
                $this->logger->info(sprintf('Moved affected node %s to parent %s', $affectedNode->nodeTypeName, $hierarchyNodeAggregateId->value), LogEnvironment::fromMethodName(__METHOD__));
            }

            $parentNodeAggregateId = $hierarchyNodeAggregateId;
        }

        $command = $this->sortNode($contentSubgraph, $parentNodeAggregateId, $affectedNodeAggregateId, $sortingInstructions, $moveNodeProperties);

        return $command ? $commands->append($command) : $commands;
    }

    public function sortNode(ContentSubgraphInterface $contentSubgraph, NodeAggregateId $parentNodeAggregateId, NodeAggregateId $affectedNodeAggregateId, array $sortingInstructions, array $moveNodeProperties = []): ?MoveNodeAggregate
    {
        if (isset($sortingInstructions['sorting'])) {
            $moveNodeProperties += $this->sortingService->sortChildren($contentSubgraph, $parentNodeAggregateId, $affectedNodeAggregateId, $sortingInstructions['sorting'], null);
        }

        if (empty($moveNodeProperties)) {
            return null;
        }

        return MoveNodeAggregate::create(
            $contentSubgraph->getWorkspaceName(),
            $contentSubgraph->getDimensionSpacePoint(),
            $affectedNodeAggregateId,
            RelationDistributionStrategy::default(),
            newParentNodeAggregateId: $moveNodeProperties['newParentNodeAggregateId'] ?? null,
            newPrecedingSiblingNodeAggregateId: $moveNodeProperties['newPrecedingSiblingNodeAggregateId'] ?? null,
            newSucceedingSiblingNodeAggregateId: $moveNodeProperties['newSucceedingSiblingNodeAggregateId'] ?? null,
        );
    }

    public function isNodeInProcess(NodeAggregateId $nodeAggregateId): bool
    {
        return isset($this->nodesInProcessing[$nodeAggregateId->value]);
    }

    protected function lockNodeForProcessing(NodeAggregateId $nodeAggregateId): void
    {
        $this->nodesInProcessing[$nodeAggregateId->value] = true;
    }

    protected function releaseNodeProcessingLock(NodeAggregateId $nodeAggregateId): void
    {
        unset($this->nodesInProcessing[$nodeAggregateId->value]);
    }

    /**
     * @throws ArchivistConfigurationException
     * @throws \Neos\Eel\Exception
     */
    protected function buildBaseContext(ContentSubgraphInterface $contentSubgraph, Node $node, array $sortingInstructions): array
    {
        $context = [
            'documentNode' => $contentSubgraph->findClosestNode($node->aggregateId, FindClosestNodeFilter::create('Neos.Neos:Document')),
            'site' => $contentSubgraph->findClosestNode($node->aggregateId, FindClosestNodeFilter::create('Neos.Neos:Site')),
            'node' => $node
        ];

        if (!isset($sortingInstructions['hierarchyRoot'])) {
            throw new ArchivistConfigurationException('You need to set an eel expression to determine the "hierarchyRoot" node to sort the node into.', 1516348967);
        }

        $hierarchyRoot = $this->eelEvaluationService->evaluateIfValidEelExpression($sortingInstructions['hierarchyRoot'], $context);
        if (!($hierarchyRoot instanceof Node)) {
            throw new ArchivistConfigurationException('The hierarchyRoot node defined was not found.', 1516348968);
        }

        $context['hierarchyRoot'] = $hierarchyRoot;

        return $context;
    }

    /**
     * @param array $baseContext
     * @param array $contextConfiguration
     * @return array
     * @throws \Neos\Eel\Exception
     */
    protected function buildCustomContext(array $baseContext, array $contextConfiguration): array
    {
        $customContext = $baseContext;
        foreach ($contextConfiguration as $variableName => $contextConfigurationExpression) {
            $customContext[$variableName] = $this->eelEvaluationService->evaluate($contextConfigurationExpression, $baseContext);
        }
        return $customContext;
    }

    public function sendNodeMovedFeedback(Node|null $node): void
    {
        if ($node === null) {
            return;
        }

        $updateNodeInfo = new UpdateNodeInfo();
        $updateNodeInfo->setNode($node);
        $this->feedbackCollection->add($updateNodeInfo);
    }
}


