<?php
declare(strict_types=1);

// Read-only deployment diagnostics. Never sends SMS/email or modifies records.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
$root = dirname(__DIR__);
$failures = 0;
function checkRuntime(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) { $failures++; }
}
checkRuntime('PHP >= 8.1', PHP_VERSION_ID >= 80100);
foreach (['pdo_mysql','mbstring','dom','xml','fileinfo','curl','openssl','zip','gd'] as $extension) {
    checkRuntime('Extension: ' . $extension, extension_loaded($extension));
}
foreach (['public/uploads', 'vendor/dompdf/dompdf/lib/fonts'] as $directory) {
    checkRuntime('Writable: ' . $directory, is_dir($root . '/' . $directory) && is_writable($root . '/' . $directory));
}
checkRuntime('Temporary directory writable', is_writable(sys_get_temp_dir()));
$autoload = $root . '/vendor/autoload.php';
checkRuntime('Composer dependencies installed', is_file($autoload));
if (is_file($autoload)) {
    try {
        require $autoload;
        checkRuntime('PHPMailer available', class_exists(\PHPMailer\PHPMailer\PHPMailer::class));
        $pdf = new \Dompdf\Dompdf();
        $pdf->loadHtml('<html><body>WIDMS deployment check</body></html>');
        $pdf->render();
        checkRuntime('Basic PDF generation', str_starts_with($pdf->output(), '%PDF-'));
    } catch (Throwable $error) { checkRuntime('PDF/dependency check; inspect server configuration', false); }
}
$sms = require $root . '/config/sms.php';
$smtp = require $root . '/config/smtp.php';
checkRuntime('SMS enabled with credentials', !empty($sms['enabled']) && !empty($sms['user_id']) && !empty($sms['password']));
checkRuntime('SMTP credentials configured', !empty($smtp['host']) && !empty($smtp['username']) && !empty($smtp['password']) && !empty($smtp['from_email']));
try {
    require $root . '/config/database.php';
    $db = database();
    checkRuntime('Database connection', (int)$db->query('SELECT 1')->fetchColumn() === 1);
    checkRuntime('Active administrator exists', (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'")->fetchColumn() > 0);
} catch (Throwable $error) { checkRuntime('Database/admin check; verify configuration and import', false); }
echo "No messages sent and no operational records changed. Credential presence is not a delivery test.\n";
echo "CLI and web PHP may use different configuration; check both on the hosting server.\n";
exit($failures > 0 ? 1 : 0);
