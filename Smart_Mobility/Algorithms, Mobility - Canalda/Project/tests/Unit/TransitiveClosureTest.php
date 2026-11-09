<?php

namespace Tests\Unit;

use App\Services\Routing\TransitiveClosure;
use Tests\TestCase;

class TransitiveClosureTest extends TestCase
{
    public function test_transitive_closure_matches_expected_four_node_problem(): void
    {
        $input = [
            [0,  1,  8, -1],
            [-1, 0,  5,  2],
            [1, -1,  0, -1],
            [3,  2, -1,  0],
        ];

        $expected = [
            [0, 1, 6, 3],
            [5, 0, 5, 2],
            [1, 2, 0, 4],
            [3, 2, 7, 0],
        ];

        $service = new TransitiveClosure;
        $actual = $service->compute($input);

        $this->assertSame($expected, $actual);

        // Verification against Floyd-Warshall
        $fwActual = $service->floydWarshall($input);
        $this->assertSame($expected, $fwActual);
        $this->assertSame($fwActual, $actual);
    }

    public function test_transitive_closure_with_structured_cells_retains_paths(): void
    {
        $input = [
            [
                ['cost' => 0, 'route' => [0]],
                ['cost' => 1, 'route' => [0, 1]],
                ['cost' => 8, 'route' => [0, 2]],
                ['cost' => -1, 'route' => []],
            ],
            [
                ['cost' => -1, 'route' => []],
                ['cost' => 0, 'route' => [1]],
                ['cost' => 5, 'route' => [1, 2]],
                ['cost' => 2, 'route' => [1, 3]],
            ],
            [
                ['cost' => 1, 'route' => [2, 0]],
                ['cost' => -1, 'route' => []],
                ['cost' => 0, 'route' => [2]],
                ['cost' => -1, 'route' => []],
            ],
            [
                ['cost' => 3, 'route' => [3, 0]],
                ['cost' => 2, 'route' => [3, 1]],
                ['cost' => -1, 'route' => []],
                ['cost' => 0, 'route' => [3]],
            ],
        ];

        $service = new TransitiveClosure;
        $result = $service->compute($input);

        // Node 0 to node 2 should take route [0, 1, 2] with cost 6 (cheaper than direct 8)
        $this->assertSame(6, $result[0][2]['cost']);
        $this->assertSame([0, 1, 2], $result[0][2]['route']);

        // Node 0 to node 3 should take route [0, 1, 3] with cost 3
        $this->assertSame(3, $result[0][3]['cost']);
        $this->assertSame([0, 1, 3], $result[0][3]['route']);
    }
}
