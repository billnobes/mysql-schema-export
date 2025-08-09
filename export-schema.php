<?php
/**
 * MySQL Schema Export Utility
 * 
 * Exports MySQL/MariaDB database schema to JSON format with comprehensive metadata
 * including columns, constraints, indexes, foreign keys, and DDL statements.
 *
 * @author Bill Nobes
 * @license MIT
 * @version 1.0.0
 */

// Parse command line arguments
function parseArgs($argv) {
    $options = [
        'host' => null,
        'database' => null,
        'user' => null,
        'password' => null,
        'port' => null,
        'output' => null,
        'filter' => null,
        'help' => false
    ];
    
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif (strpos($arg, '--') === 0) {
            $key = substr($arg, 2);
            $value = isset($argv[$i + 1]) ? $argv[$i + 1] : true;
            
            switch ($key) {
                case 'host':
                    $options['host'] = $value;
                    $i++;
                    break;
                case 'database':
                case 'db':
                    $options['database'] = $value;
                    $i++;
                    break;
                case 'user':
                    $options['user'] = $value;
                    $i++;
                    break;
                case 'password':
                    $options['password'] = $value;
                    $i++;
                    break;
                case 'port':
                    $options['port'] = $value;
                    $i++;
                    break;
                case 'output':
                    $options['output'] = $value;
                    $i++;
                    break;
                case 'filter':
                    $options['filter'] = $value;
                    $i++;
                    break;
            }
        }
    }
    
    return $options;
}

function showHelp() {
    echo "MySQL Schema Export Utility\n\n";
    echo "Usage: php export-schema.php [OPTIONS]\n\n";
    echo "Options:\n";
    echo "  --host HOST          Database host (default: localhost)\n";
    echo "  --database DB        Database name (required)\n";
    echo "  --user USER          Database user (required)\n";
    echo "  --password PASS      Database password\n";
    echo "  --port PORT          Database port (default: 3306)\n";
    echo "  --output DIR         Output directory (default: ./export)\n";
    echo "  --filter REGEX       Table name filter regex (default: /.*/ for all tables)\n";
    echo "  --help, -h           Show this help message\n\n";
    echo "Environment Variables:\n";
    echo "  DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT\n";
    echo "  OUTPUT_DIR, TABLE_NAME_REGEXP, SCHEMA_VERSION\n\n";
    echo "Examples:\n";
    echo "  php export-schema.php --database mydb --user root\n";
    echo "  php export-schema.php --db mydb --user root --filter '/user_.*/'\n";
    echo "  DB_NAME=mydb DB_USER=root php export-schema.php\n";
}

// Load configuration from config.ini if it exists
function loadConfigFile($filename = 'config.ini') {
    if (!file_exists($filename)) {
        return [];
    }
    
    $config = parse_ini_file($filename, true);
    if ($config === false) {
        echo "Warning: Could not parse config file: $filename" . PHP_EOL;
        return [];
    }
    
    return $config;
}

$configFile = loadConfigFile();
$cliArgs = parseArgs($argv ?? []);

if ($cliArgs['help']) {
    showHelp();
    exit(0);
}

// === CONFIGURATION ===
// Database connection settings - priority: CLI args > environment variables > config file > defaults
$DB_HOST = $cliArgs['host'] ?: getenv('DB_HOST') ?: ($configFile['database']['host'] ?? 'localhost');
$DB_NAME = $cliArgs['database'] ?: getenv('DB_NAME') ?: ($configFile['database']['name'] ?? '');
$DB_USER = $cliArgs['user'] ?: getenv('DB_USER') ?: ($configFile['database']['user'] ?? '');
$DB_PASS = $cliArgs['password'] ?: getenv('DB_PASS') ?: ($configFile['database']['password'] ?? '');
$DB_PORT = $cliArgs['port'] ?: getenv('DB_PORT') ?: ($configFile['database']['port'] ?? 3306);

// Table name filter (regex pattern)
$TABLE_NAME_REGEXP = $cliArgs['filter'] ?: getenv('TABLE_NAME_REGEXP') ?: ($configFile['export']['table_filter'] ?? '/.*/');

// Output settings
$OUTPUT_DIR = $cliArgs['output'] ?: getenv('OUTPUT_DIR') ?: ($configFile['export']['output_dir'] ?? (__DIR__ . '/export'));
$OUTPUT_FILE = $OUTPUT_DIR . "/schema_{$DB_NAME}.json";
$SCHEMA_VERSION = getenv('SCHEMA_VERSION') ?: date('Y-m-d');

// === SETUP ===
if (!is_dir($OUTPUT_DIR)) {
    mkdir($OUTPUT_DIR, 0777, true);
}

$dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// Validate required configuration
if (empty($DB_NAME) || empty($DB_USER)) {
    echo "Error: Database name and user are required." . PHP_EOL;
    echo "Use --database and --user arguments, or set DB_NAME and DB_USER environment variables." . PHP_EOL;
    echo "Run 'php export-schema.php --help' for usage information." . PHP_EOL;
    exit(1);
}

// Validate table filter regex
if (!empty($TABLE_NAME_REGEXP)) {
    if (@preg_match($TABLE_NAME_REGEXP, '') === false) {
        echo "Error: Invalid regex pattern in table filter: {$TABLE_NAME_REGEXP}" . PHP_EOL;
        exit(1);
    }
}

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
    echo "Connected to database: {$DB_NAME}@{$DB_HOST}" . PHP_EOL;
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . PHP_EOL);
}

// Prepare statements (reuse for speed)
$stmtColumns = $pdo->prepare("
    SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT, ORDINAL_POSITION
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
    ORDER BY ORDINAL_POSITION
");

$stmtConstraints = $pdo->prepare("
    SELECT tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE, kcu.COLUMN_NAME, kcu.ORDINAL_POSITION
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
    JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
      ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
     AND tc.TABLE_SCHEMA   = kcu.TABLE_SCHEMA
     AND tc.TABLE_NAME     = kcu.TABLE_NAME
    WHERE tc.TABLE_SCHEMA = ? AND tc.TABLE_NAME = ?
    ORDER BY tc.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
");

$stmtIndexes = $pdo->prepare("
    SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
    ORDER BY INDEX_NAME, SEQ_IN_INDEX
");

$stmtFKs = $pdo->prepare("
    SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
           kcu.REFERENCED_TABLE_NAME AS ref_table,
           kcu.REFERENCED_COLUMN_NAME AS ref_column,
           rc.UPDATE_RULE, rc.DELETE_RULE
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
    JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
      ON rc.CONSTRAINT_NAME  = kcu.CONSTRAINT_NAME
     AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
    WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ?
      AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
");

$stmtInfo = $pdo->prepare("
    SELECT TABLE_ROWS, ENGINE, TABLE_COLLATION
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
");

// === GET TABLES ===
$tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

$tablesOut = [];
$totalTables = count($tables);
$currentTable = 0;

echo "Processing $totalTables tables with filter: {$TABLE_NAME_REGEXP}..." . PHP_EOL;

foreach ($tables as $t) {
    $table = $t[0];
    
    // Skip tables that don't match the regex filter
    if (!preg_match($TABLE_NAME_REGEXP, $table)) {
        continue;
    }
    
    $currentTable++;
    
    echo "[$currentTable/$totalTables] Processing table: $table" . PHP_EOL;

    // Columns
    $stmtColumns->execute([$DB_NAME, $table]);
    $colsRaw = $stmtColumns->fetchAll();

    $columns = array_map(function ($c) {
        return [
            'name'      => $c['COLUMN_NAME'],
            'type'      => $c['COLUMN_TYPE'],
            'nullable'  => ($c['IS_NULLABLE'] === 'YES'),
            'default'   => $c['COLUMN_DEFAULT'],
            'extra'     => $c['EXTRA'],
            'comment'   => $c['COLUMN_COMMENT'],
            'position'  => (int)$c['ORDINAL_POSITION'],
        ];
    }, $colsRaw);

    // Constraints (PRIMARY KEY, UNIQUE, etc.)
    $stmtConstraints->execute([$DB_NAME, $table]);
    $consRaw = $stmtConstraints->fetchAll();
    $primaryKey = [];
    $uniqueConstraints = []; // name => [columns...]

    foreach ($consRaw as $row) {
        if ($row['CONSTRAINT_TYPE'] === 'PRIMARY KEY') {
            $primaryKey[(int)$row['ORDINAL_POSITION']] = $row['COLUMN_NAME'];
        } elseif ($row['CONSTRAINT_TYPE'] === 'UNIQUE') {
            $uniqueConstraints[$row['CONSTRAINT_NAME']][(int)$row['ORDINAL_POSITION']] = $row['COLUMN_NAME'];
        }
    }
    ksort($primaryKey);
    $primaryKey = array_values($primaryKey);

    $uniqueOut = [];
    foreach ($uniqueConstraints as $name => $cols) {
        ksort($cols);
        $uniqueOut[] = ['name' => $name, 'columns' => array_values($cols)];
    }

    // Indexes (including non-unique)
    $stmtIndexes->execute([$DB_NAME, $table]);
    $idxRaw = $stmtIndexes->fetchAll();

    $indexBuckets = []; // name => ['unique'=>bool, 'columns'=>seq=>name]
    foreach ($idxRaw as $r) {
        $name = $r['INDEX_NAME'];
        if (!isset($indexBuckets[$name])) {
            $indexBuckets[$name] = [
                'name'   => $name,
                'unique' => ($r['NON_UNIQUE'] == 0),
                'columns'=> []
            ];
        }
        $indexBuckets[$name]['columns'][(int)$r['SEQ_IN_INDEX']] = $r['COLUMN_NAME'];
    }

    // Flatten/ordered indexes, skip implicit PRIMARY (we already have primaryKey)
    $indexes = [];
    foreach ($indexBuckets as $name => $data) {
        if (strtoupper($name) === 'PRIMARY') continue;
        ksort($data['columns']);
        $indexes[] = [
            'name'    => $name,
            'unique'  => $data['unique'],
            'columns' => array_values($data['columns'])
        ];
    }

    // Foreign Keys
    $stmtFKs->execute([$DB_NAME, $table]);
    $fkRaw = $stmtFKs->fetchAll();

    $fkOut = [];
    // group by constraint name to handle multi-column FKs
    $fkBuckets = [];
    foreach ($fkRaw as $r) {
        $cn = $r['CONSTRAINT_NAME'];
        if (!isset($fkBuckets[$cn])) {
            $fkBuckets[$cn] = [
                'name'        => $cn,
                'columns'     => [],
                'ref_table'   => $r['ref_table'],
                'ref_columns' => [],
                'on_update'   => $r['UPDATE_RULE'],
                'on_delete'   => $r['DELETE_RULE'],
            ];
        }
        $fkBuckets[$cn]['columns'][]     = $r['COLUMN_NAME'];
        $fkBuckets[$cn]['ref_columns'][] = $r['ref_column'];
    }
    $fkOut = array_values($fkBuckets);

    // Table info
    $stmtInfo->execute([$DB_NAME, $table]);
    $info = $stmtInfo->fetch() ?: ['TABLE_ROWS'=>null,'ENGINE'=>null,'TABLE_COLLATION'=>null];

    // DDL
    $ddl = '';
    $ddlStmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
    if ($ddlStmt) {
        $row = $ddlStmt->fetch(PDO::FETCH_ASSOC);
        // MySQL returns keys 'Table' and 'Create Table'; numeric index 1 also works
        $ddl = $row['Create Table'] ?? ($row[1] ?? '');
    }

    $tablesOut[] = [
        'name'         => $table,
        'columns'      => $columns,
        'primary_key'  => $primaryKey,
        'unique'       => $uniqueOut,
        'indexes'      => $indexes,
        'foreign_keys' => $fkOut,
        'table_info'   => [
            'row_count_est' => isset($info['TABLE_ROWS']) ? (int)$info['TABLE_ROWS'] : null,
            'engine'        => $info['ENGINE'] ?? null,
            'collation'     => $info['TABLE_COLLATION'] ?? null,
        ],
        'ddl'          => $ddl,
    ];
}

// === PACKAGE & WRITE ===
$out = [
    'database'       => $DB_NAME,
    'generated_at'   => date('c'),
    'schema_version' => $SCHEMA_VERSION,
    'tables'         => $tablesOut,
];

$jsonOutput = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($jsonOutput === false) {
    echo "Error: Failed to encode JSON data" . PHP_EOL;
    exit(1);
}

if (file_put_contents($OUTPUT_FILE, $jsonOutput) === false) {
    echo "Error: Failed to write output file: $OUTPUT_FILE" . PHP_EOL;
    exit(1);
}

$filteredCount = count($tablesOut);
$fileSize = number_format(filesize($OUTPUT_FILE) / 1024, 1);
echo "Export complete: $OUTPUT_FILE" . PHP_EOL;
echo "Exported $filteredCount tables, {$fileSize} KB" . PHP_EOL;
