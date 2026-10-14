<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/Database.php';
try {
    $db = connectDatabase();
    echo "Conexión MySQL correcta.\n";
    foreach (['clientes', 'pedidos', 'productos'] as $table) {
        $schema = tableSchema($db, $table);
        $count = $db->query('SELECT COUNT(*) FROM ' . identifier($table))->fetchColumn();
        echo $table . ': ' . $count . ' registros; PK: ' . implode(', ', $schema['primary']) . "\n";
        foreach ($schema['columns'] as $column) echo '  ' . $column['Field'] . ': ' . $column['Type'] . "\n";
    }
} catch (PDOException $exception) {
    fwrite(STDERR, 'No fue posible conectar o consultar. Código: ' . $exception->getCode() . "\n");
    exit(1);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
