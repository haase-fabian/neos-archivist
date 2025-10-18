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

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Eel\Exception;
use Neos\Flow\Annotations as Flow;

class SortingService
{
    #[Flow\Inject]
    protected EelEvaluationService $eelEvaluationService;

    /**
     * @throws Exception
     */
    public function sortChildren(ContentSubgraphInterface $contentSubgraph, NodeAggregateId $parentNodeAggregateId, NodeAggregateId $nodeToBeSorted, string $eelOrProperty, ?NodeType $nodeTypeFilter): array
    {
        if ($this->eelEvaluationService->isValidExpression($eelOrProperty)) {
            $eelExpression = $eelOrProperty;
        } else {
            $eelExpression = sprintf('${String.toLowerCase(q(a).property("%s")) < String.toLowerCase(q(b).property("%s"))}', $eelOrProperty, $eelOrProperty);
        }

        return $this->moveNodeToCorrectPosition($contentSubgraph, $parentNodeAggregateId, $nodeToBeSorted, $eelExpression, $nodeTypeFilter);
    }

    /**
     * @throws Exception
     */
    protected function moveNodeToCorrectPosition(ContentSubgraphInterface $contentSubgraph, NodeAggregateId $parentNodeAggregateId, NodeAggregateId $nodeAggregateIdToBeSorted, string $eelExpression, ?NodeType $nodeTypeFilter): array
    {
        if (!$parentNode = $contentSubgraph->findNodeById($parentNodeAggregateId)) {
            return [];
        }

        $nodeToBeSorted = $contentSubgraph->findNodeById($nodeAggregateIdToBeSorted);
        $nodes = $contentSubgraph->findChildNodes($parentNode->aggregateId, FindChildNodesFilter::create($nodeTypeFilter?->name->value ?? 'Neos.Neos:Document'));

        $count = $nodes->count();
        for ($i = 0; $i < $count; $i++) {
            $currentNode = $nodes->offsetGet($i);
            if ($currentNode?->aggregateId === $nodeToBeSorted?->aggregateId) {

                $node = $nodes->offsetGet($i - 1);
                if ($node !== null && $this->eelEvaluationService->evaluate($eelExpression, ['a' => $nodeToBeSorted, 'b' => $node])) {
                    break; // nodeToBeSorted should come before its preceding node
                }

                $node = $nodes->offsetGet($i + 1);
                if ($node !== null && $this->eelEvaluationService->evaluate($eelExpression, ['a' => $node, 'b' => $nodeToBeSorted])) {
                    break; // nodeToBeSorted should come after its succeeding node
                }

                return []; // nodeToBeSorted is already at the correct position
            }
        }


        for ($i = 0; $i < $count; $i++) {
            $newPrecedingSiblingNode = $nodes->offsetGet($i - 1);
            $newSucceedingSiblingNode = $nodes->offsetGet($i);

            if ($newSucceedingSiblingNode === $nodeToBeSorted || $newPrecedingSiblingNode === $nodeToBeSorted) {
                continue;
            }

            if ($this->eelEvaluationService->evaluate($eelExpression, ['a' => $nodeToBeSorted, 'b' => $newSucceedingSiblingNode])) {
                return array_filter([
                    'newPrecedingSiblingNodeAggregateId' => $newPrecedingSiblingNode?->aggregateId,
                    'newSucceedingSiblingNodeAggregateId' => $newSucceedingSiblingNode?->aggregateId,
                ]);
            }
        }

        return [];
    }
}
