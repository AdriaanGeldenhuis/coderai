<?php
/**
 * CoderAI Cron Entry Point
 * Run via: php cron.php OR wget https://coderai.co.za/cron.php?key=SECRET
 *
 * Xneelo cPanel cron setup:
 * * * * * * /usr/bin/php /home/coderrczmk/public_html/cron.php >> /home/coderrczmk/cron.log 2>&1
 *
 * Or use wget with secret key:
 * * * * * * /usr/bin/wget -q -O /dev/null "https://coderai.co.za/cron.php?key=YOUR_SECRET_KEY"
 */

// Security: Allow CLI or authenticated web request
$isCli = php_sapi_name() === 'cli';
$validKey = false;

if (!$isCli) {
    // Check for secret key in web request
    $cronKey = $_GET['key'] ?? '';
    $expectedKey = getenv('CODERAI_CRON_KEY') ?: 'change-this-secret-key-in-production';

    if (empty($cronKey) || !hash_equals($expectedKey, $cronKey)) {
        http_response_code(403);
        die('Access denied');
    }
    $validKey = true;
}

// Bootstrap the application
define('CODERAI', true);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/services/JobProcessor.php';
require_once __DIR__ . '/app/services/QueueService.php';

// Set execution limits
set_time_limit(60);
ini_set('memory_limit', '256M');

// Log start
$startTime = microtime(true);
$memoryStart = memory_get_usage(true);

$db = Bootstrap::getDB();

// Create cron log entry
$db->prepare("INSERT INTO cron_logs (started_at) VALUES (NOW())")->execute();
$cronLogId = $db->lastInsertId();

try {
    // Process jobs
    $processor = new JobProcessor(55); // 55 seconds max to leave buffer
    $result = $processor->run();

    // Calculate stats
    $duration = round(microtime(true) - $startTime, 2);
    $memoryPeak = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

    // Update cron log
    $db->prepare("
        UPDATE cron_logs SET
            completed_at = NOW(),
            jobs_processed = ?,
            jobs_failed = ?,
            duration_seconds = ?,
            memory_peak_mb = ?
        WHERE id = ?
    ")->execute([
        $result['processed'],
        $result['failed'],
        $duration,
        $memoryPeak,
        $cronLogId
    ]);

    // Output for logging
    $output = sprintf(
        "[%s] Cron completed: %d processed, %d failed, %.2fs, %.2fMB\n",
        date('Y-m-d H:i:s'),
        $result['processed'],
        $result['failed'],
        $duration,
        $memoryPeak
    );

    if ($isCli) {
        echo $output;
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'processed' => $result['processed'],
            'failed' => $result['failed'],
            'duration' => $duration,
            'memory_mb' => $memoryPeak
        ]);
    }

} catch (Exception $e) {
    // Log error
    $db->prepare("
        UPDATE cron_logs SET
            completed_at = NOW(),
            notes = ?
        WHERE id = ?
    ")->execute(['Error: ' . $e->getMessage(), $cronLogId]);

    if ($isCli) {
        echo "[ERROR] " . $e->getMessage() . "\n";
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Schedule maintenance jobs (once daily)
$stmt = $db->prepare("
    SELECT COUNT(*) FROM jobs
    WHERE type = 'cleanup'
      AND status = 'pending'
      AND scheduled_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
");
$stmt->execute();
if ($stmt->fetchColumn() == 0) {
    // Queue daily cleanup
    QueueService::push(QueueService::JOB_CLEANUP, ['days_old' => 7], [
        'priority' => 10, // Low priority
        'scheduled_at' => date('Y-m-d 03:00:00', strtotime('+1 day'))
    ]);

    QueueService::push(QueueService::JOB_BACKUP_CLEANUP, ['hours_old' => 48], [
        'priority' => 10,
        'scheduled_at' => date('Y-m-d 03:30:00', strtotime('+1 day'))
    ]);
}
