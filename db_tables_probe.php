<?php
try {
    $pdo = new PDO(getenv("DB_CONNECTION") . ":host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT") . ";dbname=" . getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
    $tables = $pdo->query("select tablename from pg_tables where schemaname = current_schema() order by tablename")->fetchAll(PDO::FETCH_COLUMN);
    if (!$tables) { echo "no tables\n"; exit(0); }
    foreach ($tables as $t) {
        $count = $pdo->query("select count(*) from \"$t\"")->fetchColumn();
        $last = $pdo->query("select * from \"$t\" order by id desc limit 1")->fetch(PDO::FETCH_ASSOC);
        echo $t . " | count=" . $count . " | last=" . json_encode($last, JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (Exception $e) { echo "db error\n"; exit(1); }
