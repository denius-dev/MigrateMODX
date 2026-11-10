<?php
echo "PHP " . PHP_VERSION . PHP_EOL;
foreach (array('127.0.0.1', 'localhost') as $host) {
    try {
        new PDO('mysql:host=' . $host, 'root', '');
        echo "OK empty root@$host\n";
    } catch (Throwable $e) {
        echo "FAIL root@$host: " . $e->getMessage() . "\n";
    }
}
