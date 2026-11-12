<?php
// Pengaturan bawaan Laragon: user root tanpa password.
$cfg = ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'db' => 'warung_kasir'];

header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = new PDO("mysql:host={$cfg['host']};charset=utf8mb4", $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$cfg['db']}` CHARACTER SET utf8mb4");
    $pdo->exec("USE `{$cfg['db']}`");
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id BIGINT PRIMARY KEY, name VARCHAR(120) NOT NULL,
        buy INT NOT NULL DEFAULT 0, sell INT NOT NULL, stock INT NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales (
        id INT AUTO_INCREMENT PRIMARY KEY, t BIGINT NOT NULL,
        total INT NOT NULL, items LONGTEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (k VARCHAR(30) PRIMARY KEY, v VARCHAR(255))");

    $a = $_GET['a'] ?? 'all';
    $in = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($a === 'all') {
        $set = $pdo->query("SELECT k, v FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $products = $pdo->query("SELECT id, name, buy, sell, stock FROM products ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $rows = $pdo->query("SELECT t, total, items FROM sales ORDER BY id DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
        $sales = array_reverse(array_map(fn($r) => ['t' => (int)$r['t'], 'total' => (int)$r['total'], 'items' => json_decode($r['items'], true)], $rows));
        echo json_encode(['shop' => $set['shop'] ?? 'Warung Saya', 'fresh' => !isset($set['seeded']), 'products' => $products, 'sales' => $sales]);
    } elseif ($a === 'sync') {
        $pdo->beginTransaction();
        $up = $pdo->prepare("INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
        $up->execute(['shop', substr((string)($in['shop'] ?? 'Warung Saya'), 0, 100)]);
        $up->execute(['seeded', '1']);
        $ids = [];
        $ins = $pdo->prepare("INSERT INTO products (id, name, buy, sell, stock) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name), buy = VALUES(buy), sell = VALUES(sell), stock = VALUES(stock)");
        foreach (($in['products'] ?? []) as $p) {
            $ins->execute([(int)$p['id'], substr((string)$p['name'], 0, 120), (int)$p['buy'], (int)$p['sell'], (int)$p['stock']]);
            $ids[] = (int)$p['id'];
        }
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM products WHERE id NOT IN ($marks)")->execute($ids);
        } else {
            $pdo->exec("DELETE FROM products");
        }
        $pdo->commit();
        echo json_encode(['ok' => true]);
    } elseif ($a === 'sale') {
        $pdo->prepare("INSERT INTO sales (t, total, items) VALUES (?, ?, ?)")
            ->execute([(int)$in['t'], (int)$in['total'], json_encode($in['items'])]);
        echo json_encode(['ok' => true]);
    } elseif ($a === 'reset') {
        $pdo->exec("DELETE FROM sales");
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Aksi tidak dikenal']);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
