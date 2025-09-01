<?php

namespace App\Libraries;

use CodeIgniter\Database\ConnectionInterface;
use Config\Services;
use InvalidArgumentException;
use RuntimeException;

/**
 * CategoryMPTT - CI4-native MPTT library
 *
 * - Transaction-only (InnoDB)
 * - Uses CodeIgniter cache for persistent caching (configurable)
 * - Supports softDelete (delete) and hardDelete (hardDelete)
 * - Implements API: add, copy, delete, hardDelete, move, update, get_*
 * - Includes verifyTree() and rebuildTree()
 *
 * Schema assumptions (adjust properties if different):
 *   id, parent_id, name, description, slug, lft, rgt, depth, created_at, updated_at, deleted_at
 */
class CategoryMPTT
{
    protected ConnectionInterface $db;

    // table & columns - adjust if your schema uses different names
    protected string $table = 'forum_categories';
    protected string $idCol = 'id';
    protected string $parentCol = 'parent_id';
    protected string $leftCol = 'lft';
    protected string $rightCol = 'rgt';
    protected string $depthCol = 'depth';
    protected string $deletedAtCol = 'deleted_at';

    // Caching: uses CI cache() service
    protected string $cacheKey = 'categories_tree';
    protected int $cacheTTL = 300; // seconds, adjust as needed


    // Per-request loaded tree (array of rows ordered by left)
    protected array $treeCache = [];
    protected bool $treeLoaded = false;

    public function __construct(?ConnectionInterface $db = null, array $options = [])
    {
        $this->db = $db ?? db_connect();

        // allow overriding via options
        foreach ($options as $k => $v) {
            if (property_exists($this, $k)) $this->{$k} = $v;
        }
    }

    // -------------------------
    // Cache / Load helpers
    // -------------------------
    protected function loadTree(): void
    {
        if ($this->treeLoaded) return;

        // Try CI cache first
        $cached = cache()->get($this->cacheKey);
        if (is_array($cached)) {
            $this->treeCache = $cached;
            $this->treeLoaded = true;
            return;
        }

        // Load from DB (ignore soft-deleted nodes)
        $rows = $this->db->table($this->table)
            ->where($this->deletedAtCol, null)
            ->orderBy($this->leftCol, 'ASC')
            ->get()
            ->getResultArray();

        $this->treeCache = $rows;
        $this->treeLoaded = true;
        cache()->save($this->cacheKey, $rows, $this->cacheTTL);
    }

    protected function invalidateCache(): void
    {
        $this->treeLoaded = false;
        cache()->delete($this->cacheKey);
    }

    protected function getNodeFromCache(int $id): ?array
    {
        $this->loadTree();
        foreach ($this->treeCache as $row) {
            if ((int)$row[$this->idCol] === $id) return $row;
        }
        return null;
    }

    protected function allOrdered(): array
    {
        $this->loadTree();
        return $this->treeCache;
    }

    // -------------------------
    // Public API
    // -------------------------

    /**
     * add($parent, $data) or add($data, $target = null, $position = 'lastChild')
     *
     * We accept either (parentId, data) where parentId may be 0/null for root,
     * or ($data, $targetId, $position) — but for simplicity we use signature add(array $data, ?int $targetId = null, $position)
     */
    public function add(array $data, ?int $targetId = null, string $position = 'lastChild'): int
    {
        $this->db->transStart();
        try {
            $newId = $this->insertNode($data, $targetId, $position);
            $this->db->transComplete();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        $this->invalidateCache();
        return $newId;
    }

    /**
     * copy($node, $parent, $position = 'lastChild')
     */
    public function copy(int $nodeId, ?int $targetId, string $position = 'lastChild'): int
    {
        $this->db->transStart();
        try {
            $newRootId = $this->copyNode($nodeId, $targetId, $position);
            $this->db->transComplete();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        $this->invalidateCache();
        return $newRootId;
    }

    /**
     * delete($node) - soft delete (mark deleted_at for node + descendants)
     */
    public function delete(int $nodeId): bool
    {
        // soft delete subtree
        $this->db->transStart();
        try {
            $node = $this->getNode($nodeId);
            if (!$node) {
                $this->db->transRollback();
                return false;
            }

            $now = date('Y-m-d H:i:s');

            // mark node and descendants as deleted
            $this->db->table($this->table)
                ->where($this->leftCol . ' >=', $node[$this->leftCol])
                ->where($this->rightCol . ' <=', $node[$this->rightCol])
                ->update([$this->deletedAtCol => $now]);

            $this->db->transComplete();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        $this->invalidateCache();
        return true;
    }

    /**
     * hardDelete($node) - permanently remove subtree and compact the tree
     */
    public function hardDelete(int $nodeId): bool
    {
        $this->db->transStart();
        try {
            $ok = $this->deleteSubtree($nodeId);
            if (!$ok) {
                $this->db->transRollback();
                return false;
            }
            $this->db->transComplete();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        $this->invalidateCache();
        return true;
    }

    /**
     * move($node, $target, $position = 'lastChild')
     */
    public function move(int $nodeId, int $targetId, string $position = 'lastChild'): bool
    {
        $this->db->transStart();
        try {
            $ok = $this->moveNode($nodeId, $targetId, $position);
            if (!$ok) {
                $this->db->transRollback();
                return false;
            }
            $this->db->transComplete();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        $this->invalidateCache();
        return true;
    }

    /**
     * update($node, $data) - updates fields (doesn't touch lft/rgt unless you set them)
     */
    public function update(int $nodeId, array $data): bool
    {
        $this->db->transStart();
        try {
            $this->db->table($this->table)->where($this->idCol, $nodeId)->update($data);
            $this->db->transComplete();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        $this->invalidateCache();
        return $this->db->affectedRows() > 0;
    }

    // -------------------------
    // Read methods
    // -------------------------

    public function get_descendants(int $nodeId, bool $includeSelf = false): array
    {
        $node = $this->getNode($nodeId);
        if (!$node) return [];

        $this->loadTree();
        $res = [];
        foreach ($this->treeCache as $r) {
            if ($includeSelf) {
                if ($r[$this->leftCol] >= $node[$this->leftCol] && $r[$this->rightCol] <= $node[$this->rightCol]) $res[] = $r;
            } else {
                if ($r[$this->leftCol] > $node[$this->leftCol] && $r[$this->rightCol] < $node[$this->rightCol]) $res[] = $r;
            }
        }
        return $res;
    }

    public function get_descendant_count(int $nodeId): int
    {
        $node = $this->getNode($nodeId);
        if (!$node) return 0;
        return (int)(($node[$this->rightCol] - $node[$this->leftCol] - 1) / 2);
    }

    public function get_parent(int $nodeId): ?array
    {
        $node = $this->getNode($nodeId);
        if (!$node) return null;

        $this->loadTree();
        // parent is ancestor with depth = node.depth - 1
        foreach ($this->treeCache as $r) {
            if ($r[$this->leftCol] < $node[$this->leftCol] && $r[$this->rightCol] > $node[$this->rightCol] && (int)$r[$this->depthCol] === ((int)$node[$this->depthCol] - 1)) {
                return $r;
            }
        }
        return null;
    }

    public function get_path(int $nodeId): array
    {
        $node = $this->getNode($nodeId);
        if (!$node) return [];
        $this->loadTree();
        $res = [];
        foreach ($this->treeCache as $r) {
            if ($r[$this->leftCol] <= $node[$this->leftCol] && $r[$this->rightCol] >= $node[$this->rightCol]) $res[] = $r;
        }
        return $res;
    }

    public function get_siblings(int $nodeId, bool $includeSelf = true): array
    {
        $parent = $this->get_parent($nodeId);
        $res = [];
        if ($parent === null) {
            // siblings are roots (depth = 0)
            $this->loadTree();
            foreach ($this->treeCache as $r) {
                if ((int)$r[$this->depthCol] === 0 && ($includeSelf || (int)$r[$this->idCol] !== $nodeId)) $res[] = $r;
            }
        } else {
            // children of parent have depth = parent.depth + 1 and between parent's lft/rgt
            $this->loadTree();
            foreach ($this->treeCache as $r) {
                if ((int)$r[$this->depthCol] === ((int)$parent[$this->depthCol] + 1) && $r[$this->leftCol] > $parent[$this->leftCol] && $r[$this->rightCol] < $parent[$this->rightCol]) {
                    if ($includeSelf || (int)$r[$this->idCol] !== $nodeId) $res[] = $r;
                }
            }
        }
        // ensure ordered by left
        usort($res, fn($a, $b) => $a[$this->leftCol] <=> $b[$this->leftCol]);
        return $res;
    }

    public function get_next_sibling(int $nodeId): ?array
    {
        $siblings = array_values($this->get_siblings($nodeId, true));
        for ($i = 0; $i < count($siblings); $i++) {
            if ((int)$siblings[$i][$this->idCol] === $nodeId) {
                return $siblings[$i + 1] ?? null;
            }
        }
        return null;
    }

    public function get_previous_sibling(int $nodeId): ?array
    {
        $siblings = array_values($this->get_siblings($nodeId, true));
        for ($i = 0; $i < count($siblings); $i++) {
            if ((int)$siblings[$i][$this->idCol] === $nodeId) {
                return $siblings[$i - 1] ?? null;
            }
        }
        return null;
    }

    public function get_tree(?int $rootId = null): array
    {
        if ($rootId === null) {
            $this->loadTree();
            return $this->treeCache;
        }
        return $this->get_descendants($rootId, true);
    }

    // to_list: returns associative array id => label with indentation
    public function to_list(?int $rootId = null, string $labelField = 'name', string $prefix = '--'): array
    {
        $nodes = $this->get_tree($rootId);
        $out = [];
        foreach ($nodes as $n) {
            $out[$n[$this->idCol]] = str_repeat($prefix, max(0, (int)$n[$this->depthCol])) . ' ' . $n[$labelField];
        }
        return $out;
    }

    // to_select: returns HTML option elements (string)
    public function to_select(?int $rootId = null, string $labelField = 'name', string $prefix = '&nbsp;&nbsp;'): string
    {
        $nodes = $this->get_tree($rootId);
        $html = '';
        foreach ($nodes as $n) {
            $label = str_repeat($prefix, max(0, (int)$n[$this->depthCol])) . esc($n[$labelField]);
            $html .= sprintf('<option value="%s">%s</option>' . PHP_EOL, $n[$this->idCol], $label);
        }
        return $html;
    }

    // -------------------------
    // Integrity: verify & rebuild
    // -------------------------

    /**
     * verifyTree() - returns bool whether tree is valid (ignores deleted nodes)
     */
    public function verifyTree(): bool
    {
        $rows = $this->db->table($this->table)
            ->where($this->deletedAtCol, null)
            ->orderBy($this->leftCol, 'ASC')
            ->get()
            ->getResultArray();

        if (empty($rows)) return true;

        $seen = [];
        foreach ($rows as $row) {
            $l = (int)$row[$this->leftCol];
            $r = (int)$row[$this->rightCol];
            if ($l >= $r) return false;
            if (isset($seen[$l]) || isset($seen[$r])) return false;
            $seen[$l] = true;
            $seen[$r] = true;
        }

        // depth consistency: using stack walk
        $stack = [];
        foreach ($rows as $row) {
            while (!empty($stack) && end($stack) < $row[$this->leftCol]) array_pop($stack);
            $expectedDepth = count($stack);
            if ((int)$row[$this->depthCol] !== $expectedDepth) return false;
            $stack[] = $row[$this->rightCol];
        }

        return true;
    }

    /**
     * rebuildTree() - rebuilds lft/rgt/depth from adjacency parent_id relationships.
     * Will skip soft-deleted nodes.
     */
    public function rebuildTree(): void
    {
        $this->db->transStart();
        try {
            // We'll fetch adjacency children by parent_id to avoid relying on invalid lft/rgt.
            $this->rebuildRecursive(null, 1, 0);
            $this->db->transComplete();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        $this->invalidateCache();
    }

    protected function rebuildRecursive(?int $parentId, int $left, int $depth): int
    {
        // get direct children ordered by name (or by left if you prefer)
        $children = $this->db->table($this->table)
            ->where($this->parentCol, $parentId)
            ->where($this->deletedAtCol, null)
            ->orderBy('name', 'ASC')
            ->get()
            ->getResultArray();

        $right = $left + 1;

        foreach ($children as $child) {
            $right = $this->rebuildRecursive((int)$child[$this->idCol], $right, $depth + 1);
        }

        if ($parentId !== null) {
            // set the parent's left/right/depth
            $this->db->table($this->table)
                ->where($this->idCol, $parentId)
                ->update([
                    $this->leftCol => $left,
                    $this->rightCol => $right,
                    $this->depthCol => $depth,
                ]);
        }

        return $right + 1;
    }

    // -------------------------
    // Internal node operations (core MPTT SQL operations)
    // -------------------------

    /**
     * insertNode($data, $targetId, $position)
     * Inserts a new node relative to $targetId according to $position.
     * If $targetId is null, inserts as root appended at far right.
     */
    protected function insertNode(array $data, ?int $targetId = null, string $position = 'lastChild'): int
    {
        // ensure slug
        if (!isset($data['slug']) && isset($data['name'])) {
            helper('text');
            $data['slug'] = url_title($data['name'], '-', true);
        }

        // set default parent if not present
        $parentForInsert = null;

        if ($targetId === null) {
            // append as root on far right
            $row = $this->db->table($this->table)->selectMax($this->rightCol)->get()->getRowArray();
            $maxRight = (int)($row[$this->rightCol] ?? 0);

            $l = $maxRight + 1;
            $r = $maxRight + 2;
            $depth = 0;
        } else {
            $target = $this->db->table($this->table)->where($this->idCol, $targetId)->where($this->deletedAtCol, null)->get()->getRowArray();
            if (!$target) throw new InvalidArgumentException("Target id {$targetId} not found");

            switch ($position) {
                case 'firstChild':
                    $destLeft = (int)$target[$this->leftCol] + 1;
                    $depth = (int)$target[$this->depthCol] + 1;
                    $parentForInsert = $target[$this->idCol];
                    break;
                case 'lastChild':
                    $destLeft = (int)$target[$this->rightCol];
                    $depth = (int)$target[$this->depthCol] + 1;
                    $parentForInsert = $target[$this->idCol];
                    break;
                case 'before':
                    $destLeft = (int)$target[$this->leftCol];
                    $depth = (int)$target[$this->depthCol];
                    $parentForInsert = $target[$this->parentCol] ?? null;
                    break;
                case 'after':
                    $destLeft = (int)$target[$this->rightCol] + 1;
                    $depth = (int)$target[$this->depthCol];
                    $parentForInsert = $target[$this->parentCol] ?? null;
                    break;
                default:
                    throw new InvalidArgumentException('Invalid position: ' . $position);
            }

            // open gap of size 2 at destLeft
            $this->db->simpleQuery("UPDATE {$this->table} SET {$this->rightCol} = {$this->rightCol} + 2 WHERE {$this->rightCol} >= ?", [$destLeft]);
            $this->db->simpleQuery("UPDATE {$this->table} SET {$this->leftCol}  = {$this->leftCol}  + 2 WHERE {$this->leftCol}  >= ?", [$destLeft]);

            $l = $destLeft;
            $r = $destLeft + 1;
        }

        // prepare insert data (set left/right/depth and parent_id)
        $ins = $data;
        $ins[$this->leftCol] = $l;
        $ins[$this->rightCol] = $r;
        $ins[$this->depthCol] = $depth;
        $ins[$this->parentCol] = $parentForInsert ?? null;

        $this->db->table($this->table)->insert($ins);
        $newId = (int)$this->db->insertID();

        return $newId;
    }

    /**
     * copyNode: duplicate subtree rooted at $nodeId and insert under target at $position
     */
    protected function copyNode(int $nodeId, ?int $targetId, string $position = 'lastChild'): int
    {
        // fetch node and its descendants (including self) - include lft/rgt/depth/parent
        $node = $this->db->table($this->table)->where($this->idCol, $nodeId)->where($this->deletedAtCol, null)->get()->getRowArray();
        if (!$node) throw new InvalidArgumentException("Node {$nodeId} not found");

        $descendants = $this->db->table($this->table)
            ->where($this->leftCol . ' >=', $node[$this->leftCol])
            ->where($this->rightCol . ' <=', $node[$this->rightCol])
            ->where($this->deletedAtCol, null)
            ->orderBy($this->leftCol, 'ASC')
            ->get()
            ->getResultArray();

        $nodeL = (int)$node[$this->leftCol];
        $nodeR = (int)$node[$this->rightCol];
        $width = $nodeR - $nodeL + 1;

        // determine destination left and new depth for root of copied subtree
        if ($targetId === null) {
            $row = $this->db->table($this->table)->selectMax($this->rightCol)->get()->getRowArray();
            $destLeft = ((int)$row[$this->rightCol]) + 1;
            $newDepthForRoot = 0;
            $newParentForRoot = null;
        } else {
            $target = $this->db->table($this->table)->where($this->idCol, $targetId)->where($this->deletedAtCol, null)->get()->getRowArray();
            if (!$target) throw new InvalidArgumentException("Target {$targetId} not found");
            switch ($position) {
                case 'firstChild':
                    $destLeft = (int)$target[$this->leftCol] + 1;
                    $newDepthForRoot = (int)$target[$this->depthCol] + 1;
                    $newParentForRoot = $target[$this->idCol];
                    break;
                case 'lastChild':
                    $destLeft = (int)$target[$this->rightCol];
                    $newDepthForRoot = (int)$target[$this->depthCol] + 1;
                    $newParentForRoot = $target[$this->idCol];
                    break;
                case 'before':
                    $destLeft = (int)$target[$this->leftCol];
                    $newDepthForRoot = (int)$target[$this->depthCol];
                    $newParentForRoot = $target[$this->parentCol] ?? null;
                    break;
                case 'after':
                    $destLeft = (int)$target[$this->rightCol] + 1;
                    $newDepthForRoot = (int)$target[$this->depthCol];
                    $newParentForRoot = $target[$this->parentCol] ?? null;
                    break;
                default:
                    throw new InvalidArgumentException('Invalid position: ' . $position);
            }
        }

        // Open gap of size $width at destLeft
        $this->db->simpleQuery("UPDATE {$this->table} SET {$this->rightCol} = {$this->rightCol} + {$width} WHERE {$this->rightCol} >= ?", [$destLeft]);
        $this->db->simpleQuery("UPDATE {$this->table} SET {$this->leftCol}  = {$this->leftCol}  + {$width} WHERE {$this->leftCol}  >= ?", [$destLeft]);

        $offset = $destLeft - $nodeL;
        $idMap = []; // oldId => newId

        // We will insert nodes in the same order as descendants so left/right positions are consistent
        foreach ($descendants as $old) {
            $new = $old;
            $oldId = (int)$old[$this->idCol];
            unset($new[$this->idCol]);

            // adjust lft/rgt/depth
            $new[$this->leftCol] = (int)$old[$this->leftCol] + $offset;
            $new[$this->rightCol] = (int)$old[$this->rightCol] + $offset;
            $new[$this->depthCol] = (int)$old[$this->depthCol] - (int)$node[$this->depthCol] + $newDepthForRoot;

            // parent_id will be adjusted after we have mapping
            // set parent to null for now; we'll update afterwards
            $new[$this->parentCol] = null;

            // remove soft-delete columns to avoid copying deleted_at
            if (isset($new[$this->deletedAtCol])) $new[$this->deletedAtCol] = null;

            $this->db->table($this->table)->insert($new);
            $newId = (int)$this->db->insertID();
            $idMap[$oldId] = $newId;
        }

        // Now update parent_id for inserted nodes
        foreach ($descendants as $old) {
            $oldId = (int)$old[$this->idCol];
            $newId = $idMap[$oldId];

            $oldParent = $old[$this->parentCol] ?? null;
            if ($oldParent === null || !isset($idMap[$oldParent])) {
                // parent was outside subtree -> use newParentForRoot for the root; or null for deeper that had parent outside (unlikely)
                if ($oldId === $nodeId) {
                    $updatedParent = $newParentForRoot ?? null;
                } else {
                    // parent outside subtree: map to newParentForRoot
                    $updatedParent = $newParentForRoot ?? null;
                }
            } else {
                // parent inside subtree: map to new id
                $updatedParent = $idMap[(int)$oldParent];
            }

            $this->db->table($this->table)->where($this->idCol, $newId)->update([$this->parentCol => $updatedParent]);
        }

        // return the new root id
        return $idMap[$nodeId];
    }

    /**
     * deleteSubtree (hard delete) - removes subtree and compacts left/right values
     */
    protected function deleteSubtree(int $nodeId): bool
    {
        $node = $this->db->table($this->table)->where($this->idCol, $nodeId)->where($this->deletedAtCol, null)->get()->getRowArray();
        if (!$node) return false;

        $l = (int)$node[$this->leftCol];
        $r = (int)$node[$this->rightCol];
        $width = $r - $l + 1;

        // delete subtree (hard)
        $this->db->table($this->table)->where($this->leftCol . ' >=', $l)->where($this->rightCol . ' <=', $r)->delete();

        // close the gap
        $this->db->simpleQuery("UPDATE {$this->table} SET {$this->leftCol}  = {$this->leftCol}  - ? WHERE {$this->leftCol}  > ?", [$width, $r]);
        $this->db->simpleQuery("UPDATE {$this->table} SET {$this->rightCol} = {$this->rightCol} - ? WHERE {$this->rightCol} > ?", [$width, $r]);

        return true;
    }

    /**
     * moveNode - move subtree rooted at nodeId to targetId at position
     *
     * Positions: 'firstChild' | 'lastChild' | 'before' | 'after'
     */
    protected function moveNode(int $nodeId, int $targetId, string $position = 'lastChild'): bool
    {
        if ($nodeId === $targetId) throw new InvalidArgumentException('Cannot move node relative to itself');

        // fetch node & target
        $node = $this->db->table($this->table)->where($this->idCol, $nodeId)->where($this->deletedAtCol, null)->get()->getRowArray();
        $target = $this->db->table($this->table)->where($this->idCol, $targetId)->where($this->deletedAtCol, null)->get()->getRowArray();

        if (!$node || !$target) throw new InvalidArgumentException('Node or target not found');

        $nodeL = (int)$node[$this->leftCol];
        $nodeR = (int)$node[$this->rightCol];
        $nodeD = (int)$node[$this->depthCol];
        $width = $nodeR - $nodeL + 1;

        // compute destLeft and newDepth
        switch ($position) {
            case 'firstChild':
                $destLeft = (int)$target[$this->leftCol] + 1;
                $newDepth = (int)$target[$this->depthCol] + 1;
                $newParent = $target[$this->idCol];
                break;
            case 'lastChild':
                $destLeft = (int)$target[$this->rightCol];
                $newDepth = (int)$target[$this->depthCol] + 1;
                $newParent = $target[$this->idCol];
                break;
            case 'before':
                $destLeft = (int)$target[$this->leftCol];
                $newDepth = (int)$target[$this->depthCol];
                $newParent = $target[$this->parentCol] ?? null;
                break;
            case 'after':
                $destLeft = (int)$target[$this->rightCol] + 1;
                $newDepth = (int)$target[$this->depthCol];
                $newParent = $target[$this->parentCol] ?? null;
                break;
            default:
                throw new InvalidArgumentException('Invalid position: ' . $position);
        }

        // Can't move inside its own subtree
        if ($destLeft >= $nodeL && $destLeft <= $nodeR) {
            throw new InvalidArgumentException('Destination is inside the node subtree');
        }

        // Step 1: mark subtree as negative (so it won't be affected by gap operations)
        $this->db->simpleQuery("UPDATE {$this->table} SET {$this->leftCol} = -{$this->leftCol}, {$this->rightCol} = -{$this->rightCol} WHERE {$this->leftCol} >= ? AND {$this->rightCol} <= ?", [$nodeL, $nodeR]);

        // Step 2: close the gap left by subtree
        $this->db->simpleQuery("UPDATE {$this->table} SET {$this->leftCol} = {$this->leftCol} - ? WHERE {$this->leftCol} > ?", [$width, $nodeR]);
        $this->db->simpleQuery("UPDATE {$this->table} SET {$this->rightCol} = {$this->rightCol} - ? WHERE {$this->rightCol} > ?", [$width, $nodeR]);

        // adjust destLeft if it was right of the subtree originally
        if ($destLeft > $nodeR) $destLeft -= $width;

        // Step 3: open gap at destLeft
        $this->db->simpleQuery("UPDATE {$this->table} SET {$this->leftCol} = {$this->leftCol} + ? WHERE {$this->leftCol} >= ?", [$width, $destLeft]);
        $this->db->simpleQuery("UPDATE {$this->table} SET {$this->rightCol} = {$this->rightCol} + ? WHERE {$this->rightCol} >= ?", [$width, $destLeft]);

        // Step 4: move subtree back by converting negative to positive and applying offset, adjust depths
        $offset = $destLeft - $nodeL;
        $depthDelta = $newDepth - $nodeD;

        $this->db->simpleQuery("UPDATE {$this->table} SET {$this->leftCol} = -{$this->leftCol} + ?, {$this->rightCol} = -{$this->rightCol} + ?, {$this->depthCol} = {$this->depthCol} + ? WHERE {$this->leftCol} < 0", [$offset, $offset, $depthDelta]);

        // Update parent_id for the moved root node only
        $this->db->table($this->table)->where($this->idCol, $nodeId)->update([$this->parentCol => $newParent]);

        return true;
    }

    // -------------------------
    // Helpers
    // -------------------------

    /**
     * getNode - direct DB fetch (ignores cache) convenience
     */
    public function getNode(int $id): ?array
    {
        // prefer cache for speed
        $n = $this->getNodeFromCache($id);
        if ($n) return $n;
        // fallback direct DB read (could be soft-deleted)
        return $this->db->table($this->table)->where($this->idCol, $id)->get()->getRowArray();
    }

    /**
     * Quick integrity diagnostic that returns detail (array)
     * If you want boolean use verifyTree()
     */
    public function diagnostic(): array
    {
        $rows = $this->db->table($this->table)->orderBy($this->leftCol)->get()->getResultArray();
        $errors = [];

        // check lft < rgt and uniqueness
        $seen = [];
        foreach ($rows as $row) {
            $l = (int)$row[$this->leftCol];
            $r = (int)$row[$this->rightCol];
            if ($l >= $r) $errors[] = "Node {$row[$this->idCol]} has lft >= rgt ({$l} >= {$r})";
            if (isset($seen[$l]) || isset($seen[$r])) $errors[] = "Duplicate boundary values near node {$row[$this->idCol]}";
            $seen[$l] = true;
            $seen[$r] = true;
        }

        // depth check
        $stack = [];
        foreach ($rows as $row) {
            while (!empty($stack) && end($stack) < $row[$this->leftCol]) array_pop($stack);
            $expectedDepth = count($stack);
            if ((int)$row[$this->depthCol] !== $expectedDepth) $errors[] = "Depth mismatch node {$row[$this->idCol]} expected {$expectedDepth} got {$row[$this->depthCol]}";
            $stack[] = $row[$this->rightCol];
        }

        return ['ok' => empty($errors), 'errors' => $errors, 'rows' => $rows];
    }
}
