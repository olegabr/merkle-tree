<?php
/**
 * @copyright 2026 EthereumICO
 * @license http://opensource.org/licenses/BSD-2-Clause BSD 2-Clause License
 */
declare(strict_types=1);

namespace Pleo\Merkle\Tests;

use PHPUnit\Framework\TestCase;
use Pleo\Merkle\FixedSizeTree;
use Pleo\Merkle\GrowableBinaryTree;
use RangeException;
use RuntimeException;

/**
 * GrowableBinaryTree had no tests of its own: it was only exercised through
 * FixedSizeTree. These tests pin down its contract, including the degenerate
 * (odd width) case that is resolved by lock().
 */
class GrowableBinaryTreeTest extends TestCase
{
    /**
     * @var callable
     */
    private $hasher;

    protected function setUp(): void
    {
        $this->hasher = function (string $data): string {
            return md5($data);
        };
    }

    private function growable(int $leaves, bool $locked = true): GrowableBinaryTree
    {
        $tree = new GrowableBinaryTree($this->hasher);
        for ($i = 0; $i < $leaves; $i++) {
            $tree->addLeafNode();
        }
        if ($locked) {
            $tree->lock();
        }

        return $tree;
    }

    public function testRootOfATwoLeafTreeIsTheHashOfBothLeaves()
    {
        $tree = $this->growable(2);

        $this->assertFalse($tree->root());
        $this->assertNull($tree->set(0, 'a'));
        $this->assertFalse($tree->root());
        $tree->set(1, 'b');

        $this->assertSame(md5('ab'), $tree->root());
    }

    public function testRootStaysUnresolvedUntilTheWholeTopPairIsFilled()
    {
        $tree = $this->growable(4);

        $tree->set(0, 'a');
        $tree->set(1, 'b');
        $this->assertFalse($tree->root());

        $tree->set(2, 'c');
        $this->assertFalse($tree->root());

        $tree->set(3, 'd');
        $this->assertSame(md5(md5('ab') . md5('cd')), $tree->root());
    }

    public function testUnlockedOddLeafDoesNotProduceAParent()
    {
        $tree = $this->growable(3, false);

        $tree->set(0, 'a');
        $tree->set(1, 'b');
        // The tree only becomes one level deep when the third leaf is added,
        // and without lock() the odd leaf is not duplicated, so the root of
        // the (still empty) upper row stays unresolved.
        $this->assertFalse($tree->root());

        $this->assertNull($tree->set(2, 'c'));
        $this->assertFalse($tree->root());
    }

    public function testLockedOddLeafDuplicatesItselfAndResolvesTheRoot()
    {
        $tree = $this->growable(3, true);

        $tree->set(0, 'a');
        $tree->set(1, 'b');
        $tree->set(2, 'c');

        $this->assertSame(md5(md5('ab') . md5('cc')), $tree->root());
    }

    public function testFiveWideTreeResolvesItsDegenerateBranches()
    {
        $tree = $this->growable(5);

        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $value) {
            $tree->set($i, $value);
        }

        $left = md5(md5('ab') . md5('cd'));
        $right = md5(md5('ee') . md5('ee'));

        $this->assertSame(md5($left . $right), $tree->root());
    }

    /**
     * @dataProvider widths
     */
    public function testGrowableTreeMatchesFixedSizeTree(int $width)
    {
        $values = [];
        for ($i = 0; $i < $width; $i++) {
            $values[$i] = 'value-' . $i;
        }

        $fixed = new FixedSizeTree($width, $this->hasher);
        foreach ($values as $i => $value) {
            $fixed->set($i, $value);
        }

        $growable = $this->growable($width);
        foreach ($values as $i => $value) {
            $growable->set($i, md5($value));
        }

        $this->assertSame($fixed->hash(), $growable->root());
    }

    /**
     * @return array<array{int}>
     */
    public function widths(): array
    {
        return [[1], [2], [3], [4], [5], [6], [7], [8], [13]];
    }

    public function testSettingAnIndexBeyondTheTreeThrows()
    {
        $tree = $this->growable(2);

        $this->expectException(RangeException::class);
        $tree->set(2, 'a');
    }

    public function testSettingANegativeIndexThrows()
    {
        $tree = $this->growable(2);

        $this->expectException(RangeException::class);
        $tree->set(-1, 'a');
    }

    public function testSettingTheSameIndexTwiceThrows()
    {
        $tree = $this->growable(2);
        $tree->set(0, 'a');

        $this->expectException(RuntimeException::class);
        $tree->set(0, 'b');
    }
}
