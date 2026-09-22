<?php

// Fail closed when the coverage report is absent, empty, or below the project target.
$report = simplexml_load_file($argv[1] ?? 'coverage.xml');
if ($report === false || !isset($report->project->metrics)) {
    fwrite(STDERR, "Missing or invalid Clover report.\n");
    exit(1);
}
$metrics = $report->project->metrics;
$total = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
printf("PHP line coverage: %.2f%% (%d/%d); required: 90%%\n", $total ? 100 * $covered / $total : 0, $covered, $total);
exit($total > 0 && $covered * 100 >= $total * 90 ? 0 : 1);
