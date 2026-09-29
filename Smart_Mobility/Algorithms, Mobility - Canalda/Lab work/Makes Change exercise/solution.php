<?php
require __DIR__ . '/config.php';
/** 
 * @var array $L 
 * @var float $m 
 */

echo "-> Iterative Greedy Solution \n";
$amount = $m;
$i = 0;
$totalSum = 0;

while (round($amount, 2) > 0 && $i < count($L)) {
    $j = floor(round($amount, 2) / $L[$i]);
    
    if ($j > 0) {
        echo "$j Coins of {$L[$i]} euros\n";
        $totalSum += $j * $L[$i];
    }
    
    $amount = fmod(round($amount, 2), $L[$i]);
    $i++;
}

echo "--- Checking the output ---\n";
echo "Target amount: $m euros\n";
echo "Calculated sum: " . round($totalSum, 2) . " euros\n";

if (round($totalSum, 2) == round($m, 2)) {
    echo "Status: Success - Exact change achieved.\n";
} else {
    echo "Status: Failure - Greedy algorithm failed \n";
}

echo "\n-> Recursive Approach: Minimum Coins \n";
$best_count = PHP_INT_MAX;

function calculateWithCut($L, $m, $i, $S_fifo, $current_count, &$best_count) {
    $m = round($m, 2);
    
    // Stop if this branch uses equal to or more coins than the best known solution
    if ($current_count >= $best_count) {
        return;
    }
    
    // If we reach exactly 0, we found a new best solution
    if ($m == 0) {
        $best_count = $current_count;
        echo "Best solution ($best_count coins): " . implode(", ", $S_fifo) . "\n";
        return; 
    }
    
    if ($m < 0 || $i >= count($L)) {
        return;
    }

    $max_j = floor($m / $L[$i]);
    
    for ($j = $max_j; $j >= 0; $j--) {
        if ($j > 0) {
            $S_fifo[] = "$j coins of {$L[$i]}"; 
        }
        
        calculateWithCut($L, $m - ($j * $L[$i]), $i + 1, $S_fifo, $current_count + $j, $best_count);
        
        if ($j > 0) {
            array_pop($S_fifo); 
        }
    }
}

calculateWithCut($L, $m, 0, [], 0, $best_count);


echo "\n-> Recursive Approach: All Solutions \n";
function calculateAll($L, $m, $i, $S_fifo) {
    $m = round($m, 2);
    
    if ($m == 0) {
        echo implode(", ", $S_fifo) . "\n";
        return;
    }
    if ($m < 0 || $i >= count($L)) {
        return;
    }

    $max_j = floor($m / $L[$i]);
    
    for ($j = $max_j; $j >= 0; $j--) {
        if ($j > 0) {
            $S_fifo[] = "$j coins of {$L[$i]}"; 
        }
        
        calculateAll($L, $m - ($j * $L[$i]), $i + 1, $S_fifo);
        
        if ($j > 0) {
            array_pop($S_fifo); 
        }
    }
}

calculateAll($L, $m, 0, []);

?>