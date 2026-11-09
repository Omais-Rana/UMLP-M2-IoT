<?php

namespace App\Services\Routing;

class TransitiveClosure
{
    /**
     * Compute the transitive closure / all-pairs shortest path on a cost matrix.
     * Matrix cell value: non-negative number for valid edge, 0 on diagonal, -1 for no arc.
     * Returns an array with the same dimensions with minimum costs and optionally routes.
     *
     * @param  array<int, array<int, int|float|array{cost: int|float, route: list<int>}>>  $matrix
     * @return array<int, array<int, int|float|array{cost: int|float, route: list<int>}>>
     */
    public function compute(array $matrix): array
    {
        $n = count($matrix);
        if ($n === 0) {
            return [];
        }

        $isStructured = is_array($matrix[0][0]);

        /** @var array<int, array<int, array{cost: int|float, route: list<int>}>> $closure */
        $closure = [];

        for ($i = 0; $i < $n; $i++) {
            $closure[$i] = [];
            for ($j = 0; $j < $n; $j++) {
                if ($isStructured) {
                    /** @var array{cost: int|float, route: list<int>} $cell */
                    $cell = $matrix[$i][$j];
                    $closure[$i][$j] = [
                        'cost' => $cell['cost'],
                        'route' => $cell['route'] ?? ($cell['cost'] >= 0 ? [$i, $j] : []),
                    ];
                } else {
                    $cost = $matrix[$i][$j];
                    $closure[$i][$j] = [
                        'cost' => $cost,
                        'route' => ($cost >= 0 && $cost != -1) ? ($i === $j ? [$i] : [$i, $j]) : [],
                    ];
                }
            }
        }

        // Iterative relaxation until a full pass produces no modifications
        $modified = true;
        $maxPasses = $n * $n;
        $passCount = 0;

        while ($modified && $passCount < $maxPasses) {
            $modified = false;
            $passCount++;

            for ($i = 0; $i < $n; $i++) {
                for ($j = 0; $j < $n; $j++) {
                    $costIJ = $closure[$i][$j]['cost'];
                    if ($costIJ < 0) {
                        continue;
                    }

                    for ($k = 0; $k < $n; $k++) {
                        $costJK = $closure[$j][$k]['cost'];
                        if ($costJK < 0) {
                            continue;
                        }

                        $newCost = $costIJ + $costJK;
                        $currentCostIK = $closure[$i][$k]['cost'];

                        if ($currentCostIK < 0 || $newCost < $currentCostIK) {
                            $routeIJ = $closure[$i][$j]['route'];
                            $routeJK = $closure[$j][$k]['route'];

                            // Combine route: avoid duplicate junction node $j
                            $combinedRoute = $routeIJ;
                            if (! empty($routeJK)) {
                                $startIndex = ($routeJK[0] === $j) ? 1 : 0;
                                for ($r = $startIndex; $r < count($routeJK); $r++) {
                                    $combinedRoute[] = $routeJK[$r];
                                }
                            }

                            $closure[$i][$k] = [
                                'cost' => $newCost,
                                'route' => $combinedRoute,
                            ];
                            $modified = true;
                        }
                    }
                }
            }
        }

        // Return in same shape as input
        if (! $isStructured) {
            $result = [];
            for ($i = 0; $i < $n; $i++) {
                $result[$i] = [];
                for ($j = 0; $j < $n; $j++) {
                    $result[$i][$j] = $closure[$i][$j]['cost'];
                }
            }

            return $result;
        }

        return $closure;
    }

    /**
     * Standard Floyd-Warshall algorithm for verification in tests.
     *
     * @param  array<int, array<int, int|float>>  $matrix
     * @return array<int, array<int, int|float>>
     */
    public function floydWarshall(array $matrix): array
    {
        $n = count($matrix);
        $dist = $matrix;

        for ($k = 0; $k < $n; $k++) {
            for ($i = 0; $i < $n; $i++) {
                for ($j = 0; $j < $n; $j++) {
                    if ($dist[$i][$k] >= 0 && $dist[$k][$j] >= 0) {
                        $throughK = $dist[$i][$k] + $dist[$k][$j];
                        if ($dist[$i][$j] < 0 || $throughK < $dist[$i][$j]) {
                            $dist[$i][$j] = $throughK;
                        }
                    }
                }
            }
        }

        return $dist;
    }
}
