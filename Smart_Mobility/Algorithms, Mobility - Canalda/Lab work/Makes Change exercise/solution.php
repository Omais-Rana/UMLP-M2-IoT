<?php
require __DIR__ . '/config.php';
/** 
 * @var array $L 
 * @var float $m 
 */

echo "\n";
echo "-> 1. Iterative Greedy Solution\n";
echo "\n";

$start_time = microtime(true);
$mem_start = memory_get_usage();
ob_start();

$amount = $m;
$i = 0;
$totalSum = 0;
$greedy_solution = [];

while (round($amount, 2) > 0 && $i < count($L)) {
    $j = floor(round($amount, 2) / $L[$i]);
    
    if ($j > 0) {
        echo "$j Coins of {$L[$i]} euros\n";
        $totalSum += $j * $L[$i];
        $greedy_solution[] = "$j coins of {$L[$i]}";
    }
    
    $amount = fmod(round($amount, 2), $L[$i]);
    $i++;
}

echo "<<< Checking the output >>>\n";
echo "Target amount: $m euros\n";
echo "Calculated sum: " . round($totalSum, 2) . " euros\n";

$is_success = (round($totalSum, 2) == round($m, 2));
if ($is_success) {
    echo "Status: Success - Exact change achieved.\n";
} else {
    echo "Status: Failure - Greedy algorithm failed\n";
}

$output = ob_get_clean();
$time_taken = microtime(true) - $start_time;
$screen_memory = strlen($output); 
$best_sol_memory = $is_success ? strlen(serialize($greedy_solution)) : 0;
$shared_memory = strlen(serialize($L)) + strlen(serialize($m));
$cuts_done = 0; // Greedy does no branch cuts

echo $output;
echo "\n<<< Metrics >>>\n";
echo "1. Execution time: " . number_format($time_taken, 6) . " seconds\n";
echo "2. Print screen memory: $screen_memory bytes\n";
echo "3. Dynamic memory to keep best solution(s): $best_sol_memory bytes\n";
echo "4. Shared dynamic memory: $shared_memory bytes\n";
echo "5. Cuts done: $cuts_done\n";


echo "\n";
echo "-> 2. Recursive Approach: Minimum Coins (Fitness & Cut)\n";
echo "\n";

$start_time = microtime(true);
ob_start();

$best_count = PHP_INT_MAX;
$best_solutions = [];
$cuts_done = 0;

function calculateWithCut($L, $m, $i, $S_fifo, $current_count, &$best_count, &$best_solutions, &$cuts_done) {
    $m = round($m, 2);
    
    // Prune branch if current count is >= best found so far (Branch & Bound Cut)
    if ($current_count >= $best_count) {
        $cuts_done++;
        return;
    }
    
    // Base case: target amount met
    if ($m == 0) {
        if ($current_count < $best_count) {
            $best_count = $current_count;
            $best_solutions = [$S_fifo]; // new optimal found, replace older worse ones
            echo "Best solution ($best_count coins): " . implode(", ", $S_fifo) . "\n";
        } elseif ($current_count == $best_count) {
            $best_solutions[] = $S_fifo;
            echo "Alternate optimal solution ($best_count coins): " . implode(", ", $S_fifo) . "\n";
        }
        return; 
    }
    
    // Infeasible branch cut (over-spent or exhausted coins)
    if ($m < 0 || $i >= count($L)) {
        $cuts_done++;
        return;
    }

    $max_j = floor($m / $L[$i]);
    
    for ($j = $max_j; $j >= 0; $j--) {
        if ($j > 0) {
            $S_fifo[] = "$j coins of {$L[$i]}"; 
        }
        
        calculateWithCut($L, $m - ($j * $L[$i]), $i + 1, $S_fifo, $current_count + $j, $best_count, $best_solutions, $cuts_done);
        
        if ($j > 0) {
            array_pop($S_fifo); 
        }
    }
}

calculateWithCut($L, $m, 0, [], 0, $best_count, $best_solutions, $cuts_done);

$output = ob_get_clean();
$time_taken = microtime(true) - $start_time;
$screen_memory = strlen($output);
$best_sol_memory = strlen(serialize($best_solutions));
$shared_memory = strlen(serialize($L)) + strlen(serialize($m)) + strlen(serialize($best_count));

echo $output;
echo "\n<<< Metrics >>>\n";
echo "1. Execution time: " . number_format($time_taken, 6) . " seconds\n";
echo "2. Print screen memory: $screen_memory bytes\n";
echo "3. Dynamic memory to keep best solution(s): $best_sol_memory bytes\n";
echo "4. Shared dynamic memory: $shared_memory bytes\n";
echo "5. Cuts done: $cuts_done\n";


echo "\n";
echo "-> 3. Recursive Approach: All Solutions\n";
echo "\n";

$start_time = microtime(true);
ob_start();

$all_solutions = [];
$cuts_done = 0; // Exhaustive search without bounding cuts; records invalid branch terminations

function calculateAll($L, $m, $i, $S_fifo, &$all_solutions, &$cuts_done) {
    $m = round($m, 2);
    
    if ($m == 0) {
        $all_solutions[] = $S_fifo;
        echo implode(", ", $S_fifo) . "\n";
        return;
    }
    if ($m < 0 || $i >= count($L)) {
        $cuts_done++;
        return;
    }

    $max_j = floor($m / $L[$i]);
    
    for ($j = $max_j; $j >= 0; $j--) {
        if ($j > 0) {
            $S_fifo[] = "$j coins of {$L[$i]}"; 
        }
        
        calculateAll($L, $m - ($j * $L[$i]), $i + 1, $S_fifo, $all_solutions, $cuts_done);
        
        if ($j > 0) {
            array_pop($S_fifo); 
        }
    }
}

calculateAll($L, $m, 0, [], $all_solutions, $cuts_done);

$output = ob_get_clean();
$time_taken = microtime(true) - $start_time;
$screen_memory = strlen($output);
$best_sol_memory = strlen(serialize($all_solutions));
$shared_memory = strlen(serialize($L)) + strlen(serialize($m));

echo $output;
echo "\n<<< Metrics >>>\n";
echo "1. Execution time: " . number_format($time_taken, 6) . " seconds\n";
echo "2. Print screen memory: $screen_memory bytes\n";
echo "3. Dynamic memory to keep best solution(s): $best_sol_memory bytes\n";
echo "4. Shared dynamic memory: $shared_memory bytes\n";
echo "5. Cuts done: $cuts_done\n";

?>
