<?php

/**
 * Interface for widgets that support mock preview.
 *
 * Widgets implementing this can build a preview-safe mock subtree.
 *
 * @phpstan-type RenderTreeNode array{
 *     type: string,
 *     component: string,
 *     props: array<string, mixed>,
 *     slots: array<string, list<array<string, mixed>>>,
 *     strings?: array<string, mixed>,
 *     meta?: array<string, mixed>
 * }
 */
interface iMockable
{
	/**
	 * Build a preview-safe subtree for a single widget instance.
	 *
	 * @param array<string, mixed> $build_context
	 * @return RenderTreeNode
	 */
	public function buildMockTree(iTreeBuildContext $tree_build_context, WidgetConnection $connection, array $build_context = []): array;
}
