<?php
declare(strict_types=1);

namespace LifeOS\Engine;

use LifeOS\DB;
use LifeOS\HttpError;

/**
 * Binary placement tree. Sponsor (enrollment tree) and placement are fully separate.
 * Placement is automatic: first free slot in breadth-first order from the sponsor's
 * subtree root (or global root if sponsor has none).
 */
final class Placement
{
    /** Ensures a lo_tree row exists for the user. */
    public static function ensureNode(int $userId, ?int $sponsorId): void
    {
        DB::run(
            'INSERT IGNORE INTO lo_tree (user_id, sponsor_id) VALUES (?, ?)',
            [$userId, $sponsorId]
        );
    }

    /**
     * Places $userId under the first available binary slot, starting the search
     * at $sponsorId's position (falling back to the global root).
     * Must be called inside a transaction.
     */
    public static function place(int $userId, ?int $sponsorId): void
    {
        $node = DB::one('SELECT * FROM lo_tree WHERE user_id = ? FOR UPDATE', [$userId]);
        if (!$node) throw new HttpError('Tree node missing', 500);
        if ($node['placement_parent_id'] !== null) return; // already placed

        $parentId = self::findSlot($sponsorId, $userId);
        if ($parentId === null) {
            // Very first node in the whole tree — becomes the root.
            DB::run('UPDATE lo_tree SET placement_parent_id = NULL, placement_side = NULL WHERE user_id = ?', [$userId]);
            return;
        }

        $parent = DB::one('SELECT left_child_id, right_child_id FROM lo_tree WHERE user_id = ? FOR UPDATE', [$parentId]);
        if ($parent['left_child_id'] === null) {
            // The parent's child pointer stores the CHILD's id.
            DB::run('UPDATE lo_tree SET left_child_id = ? WHERE user_id = ?', [$userId, $parentId]);
            $side = 'L';
        } elseif ($parent['right_child_id'] === null) {
            DB::run('UPDATE lo_tree SET right_child_id = ? WHERE user_id = ?', [$userId, $parentId]);
            $side = 'R';
        } else {
            throw new HttpError('Placement race detected, retry', 409);
        }
        DB::run('UPDATE lo_tree SET placement_parent_id = ?, placement_side = ? WHERE user_id = ?', [$parentId, $side, $userId]);
    }

    /**
     * Breadth-first search for the first node with a free slot, starting at $startId.
     * $excludeId is the node being placed — it can never become its own parent.
     */
    private static function findSlot(?int $startId, int $excludeId): ?int
    {
        $root = $startId;
        if ($root === null || $root === $excludeId || !DB::one('SELECT user_id FROM lo_tree WHERE user_id = ?', [$root])) {
            $row = DB::one('SELECT user_id FROM lo_tree WHERE placement_parent_id IS NULL AND placement_side IS NULL AND user_id <> ? ORDER BY user_id LIMIT 1', [$excludeId]);
            $root = $row ? (int) $row['user_id'] : null;
            if ($root === null) return null; // empty tree
        }
        $queue = [$root];
        while ($queue) {
            $batch = array_splice($queue, 0, 50);
            $in = implode(',', array_map('intval', $batch));
            $rows = DB::all("SELECT user_id, left_child_id, right_child_id FROM lo_tree WHERE user_id IN ($in)");
            foreach ($rows as $r) {
                if ((int) $r['user_id'] === $excludeId) continue; // never self-parent
                if ($r['left_child_id'] === null || $r['right_child_id'] === null) return (int) $r['user_id'];
                if ($r['left_child_id'] !== null) $queue[] = (int) $r['left_child_id'];
                if ($r['right_child_id'] !== null) $queue[] = (int) $r['right_child_id'];
            }
        }
        return null;
    }

    /** Upline chain via placement parents (for binary volume propagation). */
    public static function binaryUpline(int $userId, int $maxDepth = 500): array
    {
        $chain = [];
        $cur = DB::one('SELECT placement_parent_id, placement_side FROM lo_tree WHERE user_id = ?', [$userId]);
        while ($cur && $cur['placement_parent_id'] !== null && $maxDepth-- > 0) {
            $chain[] = ['user_id' => (int) $cur['placement_parent_id'], 'side' => $cur['placement_side']];
            $cur = DB::one('SELECT placement_parent_id, placement_side FROM lo_tree WHERE user_id = ?', [(int) $cur['placement_parent_id']]);
        }
        return $chain;
    }

    /** Upline chain via sponsors (for generation bonus). Index 0 = direct sponsor (generation 1). */
    public static function sponsorUpline(int $userId, int $maxDepth = 10): array
    {
        $chain = [];
        $cur = DB::one('SELECT sponsor_id FROM lo_tree WHERE user_id = ?', [$userId]);
        while ($cur && $cur['sponsor_id'] !== null && $maxDepth-- > 0) {
            $chain[] = (int) $cur['sponsor_id'];
            $cur = DB::one('SELECT sponsor_id FROM lo_tree WHERE user_id = ?', [(int) $cur['sponsor_id']]);
        }
        return $chain;
    }
}
