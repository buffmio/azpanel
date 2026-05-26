<?php

$config = parse_ini_file('/app/.env', true, INI_SCANNER_RAW);
$database = $config['DATABASE'] ?? [];

$host = $database['HOSTNAME'] ?? '127.0.0.1';
$port = $database['HOSTPORT'] ?? '3306';
$name = $database['DATABASE'] ?? '';
$username = $database['USERNAME'] ?? '';
$password = $database['PASSWORD'] ?? '';
$charset = $database['CHARSET'] ?? 'utf8mb4';

if ($name === '' || $username === '') {
    fwrite(STDERR, "数据库配置不完整，请检查 /app/.env\n");
    exit(1);
}

$dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
$pdo = null;

echo "等待数据库连接...\n";
for ($i = 0; $i < 60; ++$i) {
    try {
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        break;
    } catch (PDOException $e) {
        sleep(2);
    }
}

if ($pdo === null) {
    fwrite(STDERR, "数据库连接超时\n");
    exit(1);
}

$statement = $pdo->query("SHOW TABLES LIKE 'user'");
if ($statement !== false && $statement->fetchColumn() !== false) {
    echo "数据库已初始化，跳过 SQL 导入。\n";
    exit(0);
}

echo "初始化数据库表...\n";
foreach (['/app/database/azure.sql', '/app/database/config.sql'] as $sqlFile) {
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        fwrite(STDERR, "无法读取 {$sqlFile}\n");
        exit(1);
    }
    $pdo->exec($sql);
}
