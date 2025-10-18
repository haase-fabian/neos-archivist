<?php

declare(strict_types=1);

namespace PunktDe\Archivist;

/*
 * This file is part of the PunktDe.Archivist package.
 *
 * This package is open source software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Flow\Annotations as Flow;

#[Flow\Scope('singleton')]
class SortHierarchyStorage
{
    protected array $nodesToBeSorted = [];

    public function addNode(NodeAggregateId $nodeAggregateId, array $sortingInstruction): void
    {
        $this->nodesToBeSorted[$nodeAggregateId->value] = $sortingInstruction;
    }

    public function hasNode(NodeAggregateId $nodeAggregateId): bool
    {
        return array_key_exists($nodeAggregateId->value, $this->nodesToBeSorted);
    }

    public function getSortingInstruction(NodeAggregateId $nodeAggregateId): ?array {
        return $this->nodesToBeSorted[$nodeAggregateId->value] ?? null;
    }

    public function removeNode(NodeAggregateId $nodeAggregateId): void
    {
        unset($this->nodesToBeSorted[$nodeAggregateId->value]);
    }
}
