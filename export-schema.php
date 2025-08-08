<?php
/**
 * Single-File Schema Export (All Tables -> one JSON)
 * MySQL/MariaDB via PDO
 *
 * Output: ./schema_export/schema_all.json
 */

// === CONFIG ===
$DB_HOST = 'd.pripyat.dev';
$DB_NAME = 'matter';
$DB_USER = 'wnobes';
$DB_PASS = 'Category-whilst-pilgrim';

// Table name filter (regex pattern)
$TABLE_NAME_REGEXP = '/.*/';  // Use '/.*/' for all tables, '/string.*/' for selected tables only



$OUTPUT_DIR = __DIR__ . '/export';
$OUTPUT_FILE = $OUTPUT_DIR . "/schema_{$DB_NAME}.json";
$SCHEMA_VERSION = date('Y-m-d'); // bump manually if you prefer

// === SETUP ===
if (!is_dir($OUTPUT_DIR)) {
    mkdir($OUTPUT_DIR, 0777, true);
}

$dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
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

echo "Processing $totalTables tables..." . PHP_EOL;

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

file_put_contents(
    $OUTPUT_FILE,
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

echo "Export complete: $OUTPUT_FILE" . PHP_EOL;
