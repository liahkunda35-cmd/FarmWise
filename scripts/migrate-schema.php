<?php
declare(strict_types=1);
require dirname(__DIR__) . '/backend/bootstrap.php';
Schema::migrate(db());
$cols = db()->columns('tasks');
echo 'tasks columns: ' . implode(', ', $cols) . PHP_EOL;
foreach (['related_module', 'related_id', 'created_by'] as $c) {
    echo $c . '=' . (in_array($c, $cols, true) ? 'yes' : 'NO') . PHP_EOL;
}
$sm = db()->columns('stock_movements');
echo 'stock_movements: created_by=' . (in_array('created_by', $sm, true) ? 'yes' : 'NO')
    . ' task_id=' . (in_array('task_id', $sm, true) ? 'yes' : 'NO') . PHP_EOL;
$so = db()->columns('sales_orders');
echo 'sales_orders: storage_lot_id=' . (in_array('storage_lot_id', $so, true) ? 'yes' : 'NO') . PHP_EOL;
$dp = db()->columns('dispatches');
echo 'dispatches: sales_order_id=' . (in_array('sales_order_id', $dp, true) ? 'yes' : 'NO') . PHP_EOL;
