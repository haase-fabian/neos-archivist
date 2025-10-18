<?php
declare(strict_types=1);

namespace PunktDe\Archivist\Service;

/*
 * This file is part of the PunktDe.Archivist package.
 *
 * This package is open source software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Core\CommandHandler\Commands;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\PublishIndividualNodesFromWorkspace;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\PropertyValueEquals;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Utility as NodeUtility;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Ui\Domain\Model\Feedback\Operations\NodeCreated;
use Neos\Neos\Ui\Domain\Model\Feedback\Operations\UpdateNodeInfo;
use Neos\Neos\Ui\Domain\Model\FeedbackCollection;
use Psr\Log\LoggerInterface;
use PunktDe\Archivist\Exception\ArchivistConfigurationException;
use PunktDe\Archivist\SortHierarchyStorage;


class HierarchyService
{
    #[Flow\Inject]
    protected EelEvaluationService $eelEvaluationService;

    #[Flow\Inject]
    protected SortingService $sortingService;

    #[Flow\Inject]
    protected FeedbackCollection $feedbackCollection;

    #[Flow\Inject]
    protected LoggerInterface $logger;

    #[Flow\Inject]
    protected SortHierarchyStorage $sortHierarchyStorage;
    protected Commands $generatedCommands;

    /**
     * @param array $hierarchyConfiguration
     * @param array $context
     * @param bool $publishHierarchy Automatically publish the built hierarchy node to live workspace.
     * @return NodeInterface
     * @throws ArchivistConfigurationException
     * @throws \Neos\Eel\Exception
     */
    public function buildHierarchy(ContentSubgraphInterface $contentSubgraph, NodeTypeManager $nodeTypeManager, OriginDimensionSpacePoint $originDimensionSpacePoint, array $hierarchyConfiguration, array $context, bool $publishHierarchy = false): array
    {
        $parentNode = $context['hierarchyRoot']->aggregateId;

        $commands = Commands::createEmpty();
        foreach ($hierarchyConfiguration as $hierarchyLevelConfiguration) {
            [$parentNode, $newCommands] = $this->buildHierarchyLevel($contentSubgraph, $nodeTypeManager, $originDimensionSpacePoint, $parentNode, $hierarchyLevelConfiguration, $context, $publishHierarchy);
            $commands = $commands->merge($newCommands);
        }

        return [$parentNode, $commands];
    }

    /**
     * @throws ArchivistConfigurationException
     * @throws \Neos\Eel\Exception
     */
    protected function buildHierarchyLevel(ContentSubgraphInterface $contentSubgraph, NodeTypeManager $nodeTypeManager, OriginDimensionSpacePoint $originDimensionSpacePoint, NodeAggregateId $parentNode, array $hierarchyLevelConfiguration, array $context, bool $publishHierarchy): array
    {
        $this->evaluateHierarchyLevelConfiguration($hierarchyLevelConfiguration);

        $hierarchyLevelNodeType = $nodeTypeManager->getNodeType($hierarchyLevelConfiguration['type']);
        if (!($hierarchyLevelNodeType instanceof NodeType)) {
            throw new ArchivistConfigurationException(sprintf('NodeType "%s" was not defined', $hierarchyLevelConfiguration['type']), 1516371948);
        }

        $existingNode = $this->findExistingHierarchyNode($contentSubgraph, $parentNode, $hierarchyLevelConfiguration, $context);

        if ($existingNode instanceof NodeAggregateId) {
            $this->sortingService->sortChildren($contentSubgraph, $parentNode, $existingNode, $hierarchyLevelConfiguration['sorting'], $hierarchyLevelNodeType);
            return [$existingNode, Commands::createEmpty()];
        }

        $newNodeAggregateId = NodeAggregateId::create();
        $initialPropertyValues = [];

        if (isset($hierarchyLevelConfiguration['properties'])) {
            $initialPropertyValues = array_map(fn($property) => (string)$this->eelEvaluationService->evaluateIfValidEelExpression($property, $context), $hierarchyLevelConfiguration['properties']);
        }

        if (isset($hierarchyLevelConfiguration['sorting'])) {
//            $this->sortingService->sortChildren($contentSubgraph, $parentNode, $newNodeAggregateId, $hierarchyLevelConfiguration['sorting'], $hierarchyLevelNodeType);
            $this->sortHierarchyStorage->addNode($newNodeAggregateId, $hierarchyLevelConfiguration);
        }

        if (!isset($hierarchyLevelConfiguration['properties']['uriPathSegment']) && $hierarchyLevelNodeType->isOfType('Neos.Neos:Document')) {
            $initialPropertyValues['uriPathSegment'] = $initialPropertyValues['name'] ?? $initialPropertyValues['title'];
        }

        $commands = Commands::create(CreateNodeAggregateWithNode::create(
            $contentSubgraph->getWorkspaceName(),
            $newNodeAggregateId,
            $hierarchyLevelNodeType->name,
            $originDimensionSpacePoint,
            $parentNode,
            initialPropertyValues: PropertyValuesToWrite::fromArray($initialPropertyValues),
        ));

//        $hierarchyLevelNode = $parentNode->createNodeFromTemplate($hierarchyLevelNodeTemplate, $hierarchyLevelNodeName);
//
//        $this->logger->info(sprintf('Built hierarchy level on path %s with node type %s ', $hierarchyLevelNode->getPath(), $hierarchyLevelConfiguration['type']), LogEnvironment::fromMethodName(__METHOD__));

//        if (isset($hierarchyLevelConfiguration['sorting'])) {
//            $this->sortingService->sortChildren($subgraph, $newNodeAggregateId, $hierarchyLevelConfiguration['sorting'], $hierarchyLevelNodeType->name);
//        }

        if ($publishHierarchy === true) {
            $commands = $commands->append(PublishIndividualNodesFromWorkspace::create(
                $contentSubgraph->getWorkspaceName(),
                NodeAggregateIds::create($newNodeAggregateId),
            ));
        }

        return [$newNodeAggregateId, $commands];
    }

    /**
     * @param array $hierarchyLevelConfiguration
     * @throws ArchivistConfigurationException
     */
    protected function evaluateHierarchyLevelConfiguration(array $hierarchyLevelConfiguration): void
    {
        if (!isset($hierarchyLevelConfiguration['type'])) {
            throw new ArchivistConfigurationException('Missing "type" setting for archivist hierarchy', 1516371948);
        }

        if (!isset($hierarchyLevelConfiguration['properties']) || !is_array($hierarchyLevelConfiguration['properties']) || count($hierarchyLevelConfiguration['properties']) === 0) {
            throw new ArchivistConfigurationException('Please define some properties to set up the hierarchy node', 1516382105);
        }
    }

    /**
     * @throws ArchivistConfigurationException
     * @throws \Neos\Eel\Exception
     */
    protected function findExistingHierarchyNode(ContentSubgraphInterface $contentSubgraph, NodeAggregateId $parentNode, array $hierarchyLevelConfiguration, array $context): ?NodeAggregateId
    {
        if (!isset($hierarchyLevelConfiguration['identity'])) {
            return null;
        }

        $identifyingPropertyName = $hierarchyLevelConfiguration['identity'];

        if (!isset($hierarchyLevelConfiguration['properties'][$identifyingPropertyName])) {
            throw new ArchivistConfigurationException(sprintf('The defined identity "%s" was not found in your defined properties', $identifyingPropertyName), 1516371948);
        }

        $identifyingProperty = $hierarchyLevelConfiguration['properties'][$identifyingPropertyName];
        $identifyingValue = $this->eelEvaluationService->evaluateIfValidEelExpression($identifyingProperty, $context);

        return $contentSubgraph
            ->findChildNodes($parentNode, FindChildNodesFilter::create(nodeTypes: $hierarchyLevelConfiguration['type'],
                propertyValue: PropertyValueEquals::create(PropertyName::fromString($identifyingPropertyName), $identifyingValue, false)))
            ->first()
            ?->aggregateId;
    }


}
