<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config/database.php';

// Legacy dummy-data cleanup scripts are intentionally excluded: automatic
// installation must never erase live stock, requests, returns, or rules.
$migrations = [
    'schema.sql',
    'migration_registration_requests.sql',
    'migration_stock_receiving.sql',
    'migration_supplier_workflow.sql',
    'migration_correction_requests.sql',
    'migration_intermediate_payment_corrections.sql',
    'migration_user_profiles.sql',
    'migration_activity_log.sql',
    'migration_master_data.sql',
    'migration_registration_geography.sql',
    'migration_sso_assignment_management.sql',
    'migration_beneficiaries.sql',
    'migration_goods_workflow.sql',
    'migration_aid_distribution.sql',
    'migration_special_workflows.sql',
    'migration_er_alignment.sql',
    'migration_contact_lens_bulk_workflow.sql',
    'migration_southern_geography.sql',
    'migration_galle_service_divisions.sql',
    'migration_aid_request_drafts.sql',
    'migration_optional_beneficiary_nic.sql',
    'migration_vision_camp_delivery_workflow.sql',
    'migration_disability_types.sql',
    'migration_beneficiary_identification.sql',
    'migration_item_eligibility_schema.sql',
    'migration_service_division_gn_optional.sql',
    'migration_item_beneficiary_details.sql',
    'migration_backfill_request_eligibility.sql',
    'migration_supplier_validity.sql',
    'migration_supplier_deactivation_reason.sql',
    'migration_supplier_item_validity.sql',
    'migration_supplier_product_deactivation.sql',
    'migration_supplier_agreement_history.sql',
    'migration_vision_impairment_defaults.sql',
    'migration_stock_receipt_power.sql',
    'migration_stock_receipt_power_breakdown.sql',
    'migration_stock_receipt_check_number.sql',
    'migration_unique_payment_references.sql',
    'migration_direct_aid_requests.sql',
    'migration_user_notifications.sql',
    'migration_notification_reads.sql',
    'migration_multi_division_goods_requests.sql',
    'migration_optical_power_workflow.sql',
    'migration_returnable_items.sql',
    'migration_admin_direct_distribution.sql',
    'migration_admin_direct_releases.sql',
    'migration_default_settings.sql',
    'migration_registration_sms.sql',
    'migration_registration_salary_number.sql',
    'migration_schema_structure_alignment.sql',
    'migration_schema_column_order.sql',
    'migration_required_user_phone.sql',
    'migration_notification_sms_queue.sql',
    'migration_spectacle_camps.sql',
    'migration_camp_participant_gender.sql',
    'migration_camp_quantity_only.sql',
    'migration_vision_camp_supplier_receipts.sql',
    'migration_sso_aid_distribution_permission.sql',
    'migration_division_owned_sso_pools.sql',
    'migration_return_stock_acceptance.sql',
    'migration_spectacle_categories.sql',
    'migration_shared_subject_officer_actions.sql',
    'migration_vision_camp_stock_batches.sql',
    'migration_vision_camp_distribution_times.sql',
    'migration_vision_camp_distribution_place.sql',
    'migration_vision_camp_letter_templates.sql',
    'migration_contact_lens_quantity_only.sql',
    'migration_spectacle_camp_service_divisions.sql',
];

if (!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', DB_NAME)) {
    throw new RuntimeException('WIDMS_DB_NAME must contain only letters, numbers, and underscores (maximum 64 characters).');
}
if (in_array(strtolower(DB_NAME), ['mysql', 'information_schema', 'performance_schema', 'sys'], true)) {
    throw new RuntimeException('WIDMS_DB_NAME cannot be a database reserved for the database server.');
}

// Validate every file before creating or changing a database. In particular,
// no migration may switch away from the configured database.
$preparedMigrations = [];
foreach ($migrations as $file) {
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        throw new RuntimeException("Missing migration: $file");
    }
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read migration: $file");
    }
    $sql = preg_replace('/^[ \t]*USE[ \t]+`?widms`?[ \t]*;[ \t]*\r?$/mi', '', $sql);
    if ($sql === null || preg_match('/^[ \t]*USE[ \t]+/mi', $sql)) {
        throw new RuntimeException("Migration $file contains an unsupported database switch; refusing to execute it.");
    }
    $preparedMigrations[$file] = $sql;
}
if (isset($argv[1]) && !in_array($argv[1], ['--check', '--status'], true)) {
    throw new RuntimeException('Unknown option. Use --check or --status for a read-only check.');
}
if (($argv[1] ?? '') === '--check') {
    validateSqlSplitter();
    validateCompatibleAlterOperations();
    echo count($preparedMigrations) . " migration files validated; no database changes made.\n";
    exit(0);
}
if (($argv[1] ?? '') === '--status') {
    $existing = database();
    $appliedFiles = array_fill_keys($existing->query('SELECT migration_file FROM widms_schema_migrations')->fetchAll(PDO::FETCH_COLUMN), true);
    foreach ($migrations as $file) {
        if (!isset($appliedFiles[$file])) {
            echo "Pending $file\n";
        }
    }
    echo "Read-only migration status; no database changes made.\n";
    exit(0);
}

$serverDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT);
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $server = new PDO($serverDsn, DB_USER, DB_PASS, $options);
    $server->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db = database();
} catch (PDOException $exception) {
    throw new RuntimeException(
        'Cannot create/connect to WIDMS database. Check that MySQL is running and the configured user has CREATE DATABASE permission.',
        0,
        $exception
    );
}

$db->exec(
    'CREATE TABLE IF NOT EXISTS widms_schema_migrations (
        migration_file VARCHAR(191) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$applied = array_fill_keys($db->query('SELECT migration_file FROM widms_schema_migrations')->fetchAll(PDO::FETCH_COLUMN), true);
$record = $db->prepare('INSERT INTO widms_schema_migrations (migration_file) VALUES (:file)');

if (isset($applied['schema.sql'])) {
    bootstrapFirstAdministrator($db);
}

foreach ($migrations as $file) {
    if (isset($applied[$file])) {
        echo "Already applied $file\n";
        continue;
    }

    try {
        executeMigration($db, $preparedMigrations[$file]);
        $record->execute(['file' => $file]);
        echo "Applied $file\n";
    } catch (Throwable $exception) {
        $details = $exception->getMessage();
        if ($exception instanceof PDOException && isset($exception->errorInfo) && is_array($exception->errorInfo)) {
            $sqlState = (string) ($exception->errorInfo[0] ?? '');
            $driverCode = (string) ($exception->errorInfo[1] ?? '');
            $driverMessage = (string) ($exception->errorInfo[2] ?? '');
            $details = trim("SQLSTATE $sqlState; MySQL error $driverCode; $driverMessage");
        }
        throw new RuntimeException("Migration failed in $file: $details", 0, $exception);
    }

    if ($file === 'schema.sql') {
        bootstrapFirstAdministrator($db);
    }
}

/** Execute SQL statements with MySQL 8.4 information_schema guards for DDL
 * that the legacy migration files expressed with unsupported IF EXISTS clauses. */
function executeMigration(PDO $db, string $sql): void
{
    foreach (splitSqlStatements($sql) as $statement) {
        $statement = trim($statement);
        $statement = preg_replace('/\A(?:(?:\s+)|(?:--[^\r\n]*(?:\r?\n|$))|(?:#[^\r\n]*(?:\r?\n|$))|(?:\/\*.*?\*\/))*/s', '', $statement) ?? $statement;
        $statement = ltrim($statement);
        if ($statement === '') {
            continue;
        }
        executeCompatibleStatement($db, $statement);
    }
}

function splitSqlStatements(string $sql): array
{
    // Migration files contain no stored routines; semicolons inside quoted
    // strings and comments are ignored so PDO never needs client DELIMITER.
    $parts = [];
    $buffer = '';
    $quote = null;
    $lineComment = false;
    $blockComment = false;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';
        if ($lineComment) {
            $buffer .= $char;
            if ($char === "\n") $lineComment = false;
            continue;
        }
        if ($blockComment) {
            $buffer .= $char;
            if ($char === '*' && $next === '/') { $buffer .= $next; $i++; $blockComment = false; }
            continue;
        }
        if ($quote !== null) {
            $buffer .= $char;
            if ($char === '\\' && $quote !== '`' && $next !== '') { $buffer .= $next; $i++; continue; }
            if ($char === $quote) {
                if ($next === $quote) { $buffer .= $next; $i++; }
                else $quote = null;
            }
            continue;
        }
        if (($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) || $char === '#') {
            $buffer .= $char;
            if ($char === '-') { $buffer .= $next; $i++; }
            $lineComment = true;
            continue;
        }
        if ($char === '/' && $next === '*') { $buffer .= '/*'; $i++; $blockComment = true; continue; }
        if ($char === "'" || $char === '"' || $char === '`') { $quote = $char; $buffer .= $char; continue; }
        if ($char === ';') { $parts[] = $buffer; $buffer = ''; continue; }
        $buffer .= $char;
    }
    if (trim($buffer) !== '') $parts[] = $buffer;
    return $parts;
}

function validateSqlSplitter(): void
{
    $fixture = "-- line comment; ignored\nSELECT 'a;b', 'it\\'s; safe', 'doubled ''quote;ok'''; /* block; comment */ SELECT 2; # line comment; ignored\nSELECT 3;";
    $parts = splitSqlStatements($fixture);
    if (count($parts) !== 3
        || strpos($parts[0], "'a;b'") === false
        || strpos($parts[0], "it\\'s; safe") === false
        || strpos($parts[0], "doubled ''quote;ok''") === false
        || strpos($parts[1], '/* block; comment */') === false
        || strpos($parts[2], '# line comment; ignored') === false
        || strpos($parts[2], 'SELECT 3') === false) {
        throw new RuntimeException('SQL statement splitter self-check failed for comments, quoted semicolons, or escaped quotes.');
    }
}

function validateCompatibleAlterOperations(): void
{
    $cases = [
        ['users', 'ADD COLUMN phone VARCHAR(25) NULL', 'columns', 'phone', 'ADD COLUMN `phone` VARCHAR(25) NULL', false],
        ['users', 'ADD COLUMN IF NOT EXISTS phone VARCHAR(25) NULL', 'columns', 'phone', 'ADD COLUMN `phone` VARCHAR(25) NULL', false],
        ['users', 'ADD PRIMARY KEY (id)', 'statistics', 'PRIMARY', 'ADD PRIMARY KEY (id)', false],
        ['child', 'ADD FOREIGN KEY (parent_id) REFERENCES parent(id)', null, null, 'ADD FOREIGN KEY (parent_id) REFERENCES parent(id)', false],
        ['users', 'ADD INDEX IF NOT EXISTS idx_email (email)', 'statistics', 'idx_email', 'ADD INDEX `idx_email` (email)', false],
        ['users', 'ADD UNIQUE KEY IF NOT EXISTS uq_email (email)', 'statistics', 'uq_email', 'ADD UNIQUE INDEX `uq_email` (email)', false],
        ['users', 'ADD CONSTRAINT IF NOT EXISTS chk_phone CHECK (phone IS NOT NULL)', 'table_constraints', 'chk_phone', 'ADD CONSTRAINT `chk_phone` CHECK (phone IS NOT NULL)', false],
        ['users', 'DROP COLUMN IF EXISTS old_phone', 'columns', 'old_phone', 'DROP COLUMN `old_phone`', true],
    ];
    foreach ($cases as [$table, $operation, $expectedKind, $expectedName, $expectedSql, $objectExists]) {
        $checks = [];
        $actual = planCompatibleAlterOperation(
            $table,
            $operation,
            static function (string $kind, string $checkedTable, string $name) use (&$checks, $objectExists): bool {
                $checks[] = [$kind, $checkedTable, $name];
                return $objectExists;
            }
        );
        $expectedChecks = $expectedKind === null ? [] : [[$expectedKind, $table, $expectedName]];
        if ($actual !== $expectedSql || $checks !== $expectedChecks) {
            throw new RuntimeException('ALTER TABLE compatibility self-check failed for: ' . $operation);
        }
    }
}

function executeCompatibleStatement(PDO $db, string $sql): void
{
    if (preg_match('/\ACREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+(`?[A-Za-z0-9_]+`?)\s+ON\s+(`?[A-Za-z0-9_]+`?)\s*(.+)\z/is', trim($sql), $createIndex)) {
        $unique = empty($createIndex[1]) ? '' : 'UNIQUE ';
        $name = trim($createIndex[2], '`');
        $table = trim($createIndex[3], '`');
        if (!schemaObjectExists($db, 'statistics', $table, $name)) {
            executeSqlStatement($db, 'CREATE ' . $unique . 'INDEX `' . $name . '` ON `' . $table . '` ' . $createIndex[4]);
        }
        return;
    }
    if (!preg_match('/\AALTER\s+TABLE\s+(`?[A-Za-z0-9_]+`?)\s+(.+)\z/is', trim($sql), $m)) {
        executeSqlStatement($db, $sql);
        return;
    }
    $table = trim($m[1], '`');
    $operations = splitTopLevelComma($m[2]);
    $pending = [];
    foreach ($operations as $operation) {
        $planned = planCompatibleAlterOperation(
            $table,
            $operation,
            static fn (string $kind, string $checkedTable, string $name): bool => schemaObjectExists($db, $kind, $checkedTable, $name)
        );
        if ($planned !== null) $pending[] = $planned;
    }
    if ($pending) executeSqlStatement($db, 'ALTER TABLE `' . $table . '` ' . implode(', ', $pending));
}

function executeSqlStatement(PDO $db, string $sql): void
{
    // EXECUTE of a prepared SELECT (used by conditional DDL migrations) can
    // return a result set. PDO::exec leaves it pending and blocks later
    // DEALLOCATE/PREPARE statements, so always close the statement cursor.
    $statement = $db->query($sql);
    if ($statement instanceof PDOStatement) $statement->closeCursor();
}

function planCompatibleAlterOperation(string $table, string $operation, callable $exists): ?string
{
    $operation = trim($operation);
    if (preg_match('/\ADROP\s+COLUMN\s+(?:IF\s+EXISTS\s+)?(`?[A-Za-z0-9_]+`?)\z/i', $operation, $drop)) {
        $column = trim($drop[1], '`');
        return $exists('columns', $table, $column) ? 'DROP COLUMN `' . $column . '`' : null;
    }
    // Check the specific key/index/constraint forms before columns. In
    // particular, PRIMARY and FOREIGN are keywords, never column names.
    if (preg_match('/\AADD\s+PRIMARY\s+KEY\b(.*)\z/is', $operation, $primary)) {
        return $exists('statistics', $table, 'PRIMARY') ? null : 'ADD PRIMARY KEY' . $primary[1];
    }
    if (preg_match('/\AADD\s+(UNIQUE\s+)?(?:INDEX|KEY)\s+(?:IF\s+NOT\s+EXISTS\s+)?(`?[A-Za-z0-9_]+`?)\s*(.*)\z/is', $operation, $index)) {
        $name = trim($index[2], '`');
        if ($exists('statistics', $table, $name)) return null;
        return 'ADD ' . (empty($index[1]) ? '' : 'UNIQUE ') . 'INDEX `' . $name . '` ' . $index[3];
    }
    if (preg_match('/\AADD\s+CONSTRAINT\s+(?:IF\s+NOT\s+EXISTS\s+)?(`?[A-Za-z0-9_]+`?)\s+(.+)\z/is', $operation, $constraint)) {
        $name = trim($constraint[1], '`');
        return $exists('table_constraints', $table, $name) ? null : 'ADD CONSTRAINT `' . $name . '` ' . $constraint[2];
    }
    if (preg_match('/\AADD\s+FOREIGN\s+KEY\b/i', $operation)) {
        return $operation;
    }
    if (preg_match('/\AADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?(`?[A-Za-z0-9_]+`?)\s+(.+)\z/is', $operation, $column)) {
        $name = trim($column[1], '`');
        return $exists('columns', $table, $name) ? null : 'ADD COLUMN `' . $name . '` ' . $column[2];
    }
    return $operation;
}

function splitTopLevelComma(string $sql): array
{
    $parts = []; $start = 0; $depth = 0; $quote = null; $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $c = $sql[$i];
        if ($quote !== null) {
            if ($c === '\\' && $quote !== '`') { $i++; continue; }
            if ($c === $quote) { if (($sql[$i + 1] ?? '') === $quote) $i++; else $quote = null; }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; continue; }
        if ($c === '(') $depth++;
        elseif ($c === ')') $depth--;
        elseif ($c === ',' && $depth === 0) { $parts[] = substr($sql, $start, $i - $start); $start = $i + 1; }
    }
    $parts[] = substr($sql, $start);
    return $parts;
}

function schemaObjectExists(PDO $db, string $kind, string $table, string $name): bool
{
    $queries = [
        'columns' => 'SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1',
        'statistics' => 'SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1',
        'table_constraints' => 'SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name=? AND constraint_name=? LIMIT 1',
    ];
    $check = $db->prepare($queries[$kind]);
    $check->execute([$table, $name]);
    return $check->fetchColumn() !== false;
}

// Also recover an install interrupted immediately after schema.sql.
bootstrapFirstAdministrator($db);
importInitialGeography($db, $preparedMigrations);
echo "WIDMS schema is current.\n";

function importInitialGeography(PDO $db, array $preparedMigrations): void
{
    // Schema comparison creates a disposable database without operational data.
    if (getenv('WIDMS_SKIP_GEOGRAPHY_IMPORT') === '1') {
        echo "Geography import skipped by WIDMS_SKIP_GEOGRAPHY_IMPORT.\n";
        return;
    }

    $counts = [
        (int) $db->query('SELECT COUNT(*) FROM districts')->fetchColumn(),
        (int) $db->query('SELECT COUNT(*) FROM ds_divisions')->fetchColumn(),
        (int) $db->query('SELECT COUNT(*) FROM gn_divisions')->fetchColumn(),
    ];
    if ($counts !== [0, 0, 0]) {
        echo "Geography already has records; automatic import skipped. Run database/import_southern_geography_fixed.php to reconcile an existing or partial hierarchy.\n";
        return;
    }

    echo "Importing Southern Province districts, DS Divisions, and GN Divisions...\n";
    try {
        require __DIR__ . '/import_southern_geography_fixed.php';
        // This migration originally ran before geography was imported. Apply
        // its idempotent service-centre inserts now that Galle exists.
        executeMigration($db, $preparedMigrations['migration_galle_service_divisions.sql']);
    } catch (Throwable $exception) {
        throw new RuntimeException(
            'Automatic geography import failed. Check the internet connection and rerun database/migrate.php: ' . $exception->getMessage(),
            0,
            $exception
        );
    }
}

function bootstrapFirstAdministrator(PDO $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if ((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
        return;
    }

    // Bootstrap only a genuinely empty installation. Never replace an
    // existing account or turn a missing admin into an account takeover path.
    require_once __DIR__ . '/../includes/sms.php';
    $interactive = function_exists('stream_isatty') && stream_isatty(STDIN);
    $username = trim((string) (getenv('WIDMS_ADMIN_USERNAME') ?: ''));
    if ($username === '' && $interactive) {
        echo 'First administrator email (username): ';
        $username = trim((string) fgets(STDIN));
    }
    $username = strtolower($username);
    if (strlen($username) > 120 || !filter_var($username, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('A valid first administrator email is required. Enter it when prompted or set WIDMS_ADMIN_USERNAME.');
    }

    $phone = trim((string) (getenv('WIDMS_ADMIN_PHONE') ?: ''));
    if ($phone === '' && $interactive) {
        echo 'First administrator mobile number (07XXXXXXXX or +947XXXXXXXX): ';
        $phone = trim((string) fgets(STDIN));
    }
    if (widmsSmsPhone($phone) === null || strlen($phone) > 25) {
        throw new RuntimeException('A valid first administrator mobile number is required. Set WIDMS_ADMIN_PHONE and rerun database/migrate.php.');
    }

    $password = (string) (getenv('WIDMS_ADMIN_PASSWORD') ?: '');
    if ($password === '' && $interactive) {
        echo 'First administrator password (minimum 8 characters; input is visible): ';
        $password = rtrim((string) fgets(STDIN), "\r\n");
        echo 'Confirm password: ';
        $confirmation = rtrim((string) fgets(STDIN), "\r\n");
        if ($password !== $confirmation) {
            throw new RuntimeException('The administrator passwords do not match. No administrator was created.');
        }
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('A first administrator password of at least 8 characters is required. Enter it when prompted or set WIDMS_ADMIN_PASSWORD.');
    }

    $insert = $db->prepare(
        "INSERT INTO users (full_name, username, phone, password_hash, role, status)
         VALUES ('System Administrator', :username, :phone, :password_hash, 'admin', 'active')"
    );
    $insert->execute([
        'username' => $username,
        'phone' => $phone,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    echo "First administrator created. Username: $username\n";
    echo "Use the password entered during setup to sign in.\n";
}
