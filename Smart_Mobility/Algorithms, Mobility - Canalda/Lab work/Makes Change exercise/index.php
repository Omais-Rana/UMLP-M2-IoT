<?php
// Initialize default values
$m = isset($_POST['amount']) ? (float)$_POST['amount'] : 9.5;
$coin_input = isset($_POST['coins']) ? $_POST['coins'] : "5, 2, 1.5";
$run_all = isset($_POST['run_all']);

// Process the coin list into an array of floats
$L = array_filter(array_map('floatval', array_map('trim', explode(',', $coin_input))));

$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---------------------------------------------------------
    // 1. Iterative Greedy Solution
    // ---------------------------------------------------------
    $start_time = microtime(true);
    ob_start();
    
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
    echo "Calculated sum: " . round($totalSum, 2) . " / Target: $m euros\n";
    if (round($totalSum, 2) == round($m, 2)) {
        echo "Status: Success - Exact change achieved.\n";
    } else {
        echo "Status: Failure - Greedy algorithm failed.\n";
    }

    $results['greedy']['output'] = ob_get_clean();
    $results['greedy']['time'] = number_format(microtime(true) - $start_time, 6);

    // ---------------------------------------------------------
    // 2. Recursive Approach: Minimum Coins (Fitness & Cut)
    // ---------------------------------------------------------
    $start_time = microtime(true);
    ob_start();

    $best_count = PHP_INT_MAX;

    function calculateWithCut($L, $m, $i, $S_fifo, $current_count, &$best_count) {
        $m = round($m, 2);
        if ($current_count >= $best_count) return;
        
        if ($m == 0) {
            $best_count = $current_count;
            echo "Best solution ($best_count coins): " . implode(", ", $S_fifo) . "\n";
            return; 
        }
        
        if ($m < 0 || $i >= count($L)) return;

        $max_j = floor($m / $L[$i]);
        for ($j = $max_j; $j >= 0; $j--) {
            if ($j > 0) $S_fifo[] = "$j coins of {$L[$i]}"; 
            calculateWithCut($L, $m - ($j * $L[$i]), $i + 1, $S_fifo, $current_count + $j, $best_count);
            if ($j > 0) array_pop($S_fifo); 
        }
    }

    calculateWithCut($L, $m, 0, [], 0, $best_count);
    
    $results['recursive_cut']['output'] = ob_get_clean();
    $results['recursive_cut']['time'] = number_format(microtime(true) - $start_time, 6);

    // ---------------------------------------------------------
    // 3. Recursive Approach: All Solutions
    // ---------------------------------------------------------
    if ($run_all) {
        $start_time = microtime(true);
        ob_start();

        function calculateAll($L, $m, $i, $S_fifo) {
            $m = round($m, 2);
            if ($m == 0) {
                echo implode(", ", $S_fifo) . "\n";
                return;
            }
            if ($m < 0 || $i >= count($L)) return;

            $max_j = floor($m / $L[$i]);
            for ($j = $max_j; $j >= 0; $j--) {
                if ($j > 0) $S_fifo[] = "$j coins of {$L[$i]}"; 
                calculateAll($L, $m - ($j * $L[$i]), $i + 1, $S_fifo);
                if ($j > 0) array_pop($S_fifo); 
            }
        }

        calculateAll($L, $m, 0, []);
        
        $results['recursive_all']['output'] = ob_get_clean();
        $results['recursive_all']['time'] = number_format(microtime(true) - $start_time, 6);
    } else {
        $results['recursive_all']['output'] = "Skipped to prevent server timeout. Check the box above to run.";
        $results['recursive_all']['time'] = "N/A";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Makes Change Problem</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f4f4f9; color: #333; line-height: 1.6; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h1 { font-size: 1.5rem; margin-top: 0; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; }
        input[type="text"], input[type="number"] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { background: #007bff; color: #fff; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; }
        button:hover { background: #0056b3; }
        .result-box { background: #2d2d2d; color: #4af626; padding: 15px; border-radius: 4px; overflow-x: auto; margin-bottom: 20px; }
        .time-badge { background: #e2e8f0; color: #475569; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; float: right; }
        .warning { color: #d9534f; font-size: 0.9rem; margin-left: 10px; }
    </style>
</head>
<body>

<div class="container">
    <h1>Change Maker Configuration</h1>
    <form method="POST">
        <div class="form-group">
            <label for="amount">Target Amount</label>
            <input type="number" step="0.01" name="amount" id="amount" value="<?= htmlspecialchars($m) ?>" required>
        </div>
        <div class="form-group">
            <label for="coins">Available Coins</label>
            <input type="text" name="coins" id="coins" value="<?= htmlspecialchars($coin_input) ?>" required>
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" name="run_all" <?= $run_all ? 'checked' : '' ?>>
                Run "All Solutions" Algorithm
            </label>
        </div>
        <button type="submit">Run Algorithms</button>
    </form>

    <?php if (!empty($results)): ?>
        <hr style="margin: 30px 0;">
        <h2>Execution Results</h2>

        <h3>1. Iterative Greedy Solution 
            <span class="time-badge">Time: <?= $results['greedy']['time'] ?> sec</span>
        </h3>
        <pre class="result-box"><?= htmlspecialchars($results['greedy']['output']) ?></pre>

        <h3>2. Recursive Approach: Minimum Coins (Cut) 
            <span class="time-badge">Time: <?= $results['recursive_cut']['time'] ?> sec</span>
        </h3>
        <pre class="result-box"><?= htmlspecialchars($results['recursive_cut']['output']) ?></pre>

        <h3>3. Recursive Approach: All Solutions 
            <span class="time-badge">Time: <?= $results['recursive_all']['time'] ?> sec</span>
        </h3>
        <pre class="result-box"><?= htmlspecialchars($results['recursive_all']['output']) ?></pre>
    <?php endif; ?>
</div>

</body>
</html>