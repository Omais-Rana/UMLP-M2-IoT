<?php
// Initialize default values
$m = isset($_POST['amount']) ? (float)$_POST['amount'] : 9.5;
$coin_input = isset($_POST['coins']) ? $_POST['coins'] : "5, 2, 1.5";
$run_all = isset($_POST['run_all']);

// Process the coin list into an array of floats
$L = array_filter(array_map('floatval', array_map('trim', explode(',', $coin_input))));

$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Iterative Greedy Solution
    $start_time = microtime(true);
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

    echo "--- Checking the output ---\n";
    echo "Calculated sum: " . round($totalSum, 2) . " / Target: $m euros\n";
    $is_success = (round($totalSum, 2) == round($m, 2));
    if ($is_success) {
        echo "Status: Success - Exact change achieved.\n";
    } else {
        echo "Status: Failure - Greedy algorithm failed.\n";
    }

    $output = ob_get_clean();
    $results['greedy']['output'] = $output;
    $results['greedy']['time'] = number_format(microtime(true) - $start_time, 6);
    $results['greedy']['screen_mem'] = strlen($output);
    $results['greedy']['best_sol_mem'] = $is_success ? strlen(serialize($greedy_solution)) : 0;
    $results['greedy']['shared_mem'] = strlen(serialize($L)) + strlen(serialize($m));
    $results['greedy']['cuts'] = 0;

    // 2. Recursive Approach: Minimum Coins (Fitness & Cut)
    $start_time = microtime(true);
    ob_start();

    $best_count = PHP_INT_MAX;
    $best_solutions = [];
    $cuts_done = 0;

    function calculateWithCut($L, $m, $i, $S_fifo, $current_count, &$best_count, &$best_solutions, &$cuts_done) {
        $m = round($m, 2);
        if ($current_count >= $best_count) {
            $cuts_done++;
            return;
        }
        
        if ($m == 0) {
            if ($current_count < $best_count) {
                $best_count = $current_count;
                $best_solutions = [$S_fifo];
                echo "Best solution ($best_count coins): " . implode(", ", $S_fifo) . "\n";
            } elseif ($current_count == $best_count) {
                $best_solutions[] = $S_fifo;
                echo "Alternate optimal solution ($best_count coins): " . implode(", ", $S_fifo) . "\n";
            }
            return; 
        }
        
        if ($m < 0 || $i >= count($L)) {
            $cuts_done++;
            return;
        }

        $max_j = floor($m / $L[$i]);
        for ($j = $max_j; $j >= 0; $j--) {
            if ($j > 0) $S_fifo[] = "$j coins of {$L[$i]}"; 
            calculateWithCut($L, $m - ($j * $L[$i]), $i + 1, $S_fifo, $current_count + $j, $best_count, $best_solutions, $cuts_done);
            if ($j > 0) array_pop($S_fifo); 
        }
    }

    calculateWithCut($L, $m, 0, [], 0, $best_count, $best_solutions, $cuts_done);
    
    $output = ob_get_clean();
    $results['recursive_cut']['output'] = $output;
    $results['recursive_cut']['time'] = number_format(microtime(true) - $start_time, 6);
    $results['recursive_cut']['screen_mem'] = strlen($output);
    $results['recursive_cut']['best_sol_mem'] = strlen(serialize($best_solutions));
    $results['recursive_cut']['shared_mem'] = strlen(serialize($L)) + strlen(serialize($m)) + strlen(serialize($best_count));
    $results['recursive_cut']['cuts'] = $cuts_done;

    // 3. Recursive Approach: All Solutions
    if ($run_all) {
        $start_time = microtime(true);
        ob_start();

        $all_solutions = [];
        $cuts_all = 0;

        function calculateAll($L, $m, $i, $S_fifo, &$all_solutions, &$cuts_all) {
            $m = round($m, 2);
            if ($m == 0) {
                $all_solutions[] = $S_fifo;
                echo implode(", ", $S_fifo) . "\n";
                return;
            }
            if ($m < 0 || $i >= count($L)) {
                $cuts_all++;
                return;
            }

            $max_j = floor($m / $L[$i]);
            for ($j = $max_j; $j >= 0; $j--) {
                if ($j > 0) $S_fifo[] = "$j coins of {$L[$i]}"; 
                calculateAll($L, $m - ($j * $L[$i]), $i + 1, $S_fifo, $all_solutions, $cuts_all);
                if ($j > 0) array_pop($S_fifo); 
            }
        }

        calculateAll($L, $m, 0, [], $all_solutions, $cuts_all);
        
        $output = ob_get_clean();
        $results['recursive_all']['output'] = $output;
        $results['recursive_all']['time'] = number_format(microtime(true) - $start_time, 6);
        $results['recursive_all']['screen_mem'] = strlen($output);
        $results['recursive_all']['best_sol_mem'] = strlen(serialize($all_solutions));
        $results['recursive_all']['shared_mem'] = strlen(serialize($L)) + strlen(serialize($m));
        $results['recursive_all']['cuts'] = $cuts_all;
    } else {
        $results['recursive_all']['output'] = "Skipped to prevent server timeout. Check the box above to run.";
        $results['recursive_all']['time'] = "N/A";
        $results['recursive_all']['screen_mem'] = "N/A";
        $results['recursive_all']['best_sol_mem'] = "N/A";
        $results['recursive_all']['shared_mem'] = "N/A";
        $results['recursive_all']['cuts'] = "N/A";
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
        body { font-family: system-ui, -apple-system, sans-serif; background: #f4f4f9; color: #333; line-height: 1.6; padding: 20px; }
        .container { max-width: 950px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h1 { font-size: 1.6rem; margin-top: 0; color: #1e293b; }
        h2 { font-size: 1.3rem; margin-top: 25px; color: #334155; }
        h3 { font-size: 1.1rem; margin-bottom: 8px; color: #0f172a; display: flex; align-items: center; justify-content: space-between; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; color: #475569; }
        input[type="text"], input[type="number"] { width: 100%; padding: 9px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-size: 1rem; }
        button { background: #0284c7; color: #fff; padding: 10px 22px; border: none; border-radius: 6px; cursor: pointer; font-size: 1rem; font-weight: 600; }
        button:hover { background: #0369a1; }
        .result-box { background: #1e293b; color: #4ade80; padding: 15px; border-radius: 6px; overflow-x: auto; font-family: 'Consolas', 'Courier New', monospace; font-size: 0.95rem; margin-bottom: 12px; }
        
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 10px;
            margin-bottom: 25px;
        }
        .metric-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px 14px;
        }
        .metric-title {
            font-size: 0.78rem;
            text-transform: uppercase;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 4px;
        }
        .metric-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: #0f172a;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>Makes Change - Algorithm Performance & Metrics</h1>
    <form method="POST">
        <div class="form-group">
            <label for="amount">Target Amount (€)</label>
            <input type="number" step="0.01" name="amount" id="amount" value="<?= htmlspecialchars($m) ?>" required>
        </div>
        <div class="form-group">
            <label for="coins">Available Coins (Descending order recommended)</label>
            <input type="text" name="coins" id="coins" value="<?= htmlspecialchars($coin_input) ?>" required>
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" name="run_all" <?= $run_all ? 'checked' : '' ?>>
                Run "All Solutions" Algorithm (Warning: slow on large values)
            </label>
        </div>
        <button type="submit">Run & Compare Algorithms</button>
    </form>

    <?php if (!empty($results)): ?>
        <hr style="margin: 30px 0; border: none; border-top: 1px solid #e2e8f0;">
        <h2>Execution Results & Lab Metrics</h2>

        <!-- 1. Greedy -->
        <h3>1. Iterative Greedy Solution</h3>
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-title">1. Execution Time</div>
                <div class="metric-value"><?= $results['greedy']['time'] ?> s</div>
            </div>
            <div class="metric-card">
                <div class="metric-title">2. Screen Memory</div>
                <div class="metric-value"><?= is_numeric($results['greedy']['screen_mem']) ? $results['greedy']['screen_mem'] . ' B' : $results['greedy']['screen_mem'] ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-title">3. Best Sol. Memory</div>
                <div class="metric-value"><?= is_numeric($results['greedy']['best_sol_mem']) ? $results['greedy']['best_sol_mem'] . ' B' : $results['greedy']['best_sol_mem'] ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-title">4. Shared Memory</div>
                <div class="metric-value"><?= is_numeric($results['greedy']['shared_mem']) ? $results['greedy']['shared_mem'] . ' B' : $results['greedy']['shared_mem'] ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-title">5. Cuts Done</div>
                <div class="metric-value"><?= $results['greedy']['cuts'] ?></div>
            </div>
        </div>
        <pre class="result-box"><?= htmlspecialchars($results['greedy']['output']) ?></pre>

        <!-- 2. Recursive Cut -->
        <h3>2. Recursive Approach: Minimum Coins (Fitness & Branch Cut)</h3>
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-title">1. Execution Time</div>
                <div class="metric-value"><?= $results['recursive_cut']['time'] ?> s</div>
            </div>
            <div class="metric-card">
                <div class="metric-title">2. Screen Memory</div>
                <div class="metric-value"><?= is_numeric($results['recursive_cut']['screen_mem']) ? $results['recursive_cut']['screen_mem'] . ' B' : $results['recursive_cut']['screen_mem'] ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-title">3. Best Sol. Memory</div>
                <div class="metric-value"><?= is_numeric($results['recursive_cut']['best_sol_mem']) ? $results['recursive_cut']['best_sol_mem'] . ' B' : $results['recursive_cut']['best_sol_mem'] ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-title">4. Shared Memory</div>
                <div class="metric-value"><?= is_numeric($results['recursive_cut']['shared_mem']) ? $results['recursive_cut']['shared_mem'] . ' B' : $results['recursive_cut']['shared_mem'] ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-title">5. Cuts Done</div>
                <div class="metric-value"><?= $results['recursive_cut']['cuts'] ?></div>
            </div>
        </div>
        <pre class="result-box"><?= htmlspecialchars($results['recursive_cut']['output']) ?></pre>

        <!-- 3. Recursive All -->
        <h3>3. Recursive Approach: All Solutions</h3>
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-title">1. Execution Time</div>
                <div class="metric-value"><?= is_numeric($results['recursive_all']['time']) ? $results['recursive_all']['time'] . ' s' : $results['recursive_all']['time'] ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-title">2. Screen Memory</div>
                <div class="metric-value"><?= is_numeric($results['recursive_all']['screen_mem']) ? $results['recursive_all']['screen_mem'] . ' B' : $results['recursive_all']['screen_mem'] ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-title">3. Best Sol. Memory</div>
                <div class="metric-value"><?= is_numeric($results['recursive_all']['best_sol_mem']) ? $results['recursive_all']['best_sol_mem'] . ' B' : $results['recursive_all']['best_sol_mem'] ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-title">4. Shared Memory</div>
                <div class="metric-value"><?= is_numeric($results['recursive_all']['shared_mem']) ? $results['recursive_all']['shared_mem'] . ' B' : $results['recursive_all']['shared_mem'] ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-title">5. Cuts Done</div>
                <div class="metric-value"><?= $results['recursive_all']['cuts'] ?></div>
            </div>
        </div>
        <pre class="result-box"><?= htmlspecialchars($results['recursive_all']['output']) ?></pre>
    <?php endif; ?>
</div>

</body>
</html>
