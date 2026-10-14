<?php
declare(strict_types=1);

function identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function connectDatabase(): PDO
{
    $path = dirname(__DIR__) . '/config.local.php';
    if (!is_file($path)) throw new RuntimeException('Copia config.example.php como config.local.php y configura la conexión a MySQL.');
    $config = require $path;
    return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']), $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

function tableSchema(PDO $db, string $table): array
{
    $columns = $db->query('SHOW FULL COLUMNS FROM ' . identifier($table))->fetchAll();
    $primary = $db->query('SHOW INDEX FROM ' . identifier($table) . " WHERE Key_name = 'PRIMARY'")->fetchAll();
    usort($primary, fn($a, $b) => $a['Seq_in_index'] <=> $b['Seq_in_index']);
    $query = $db->prepare('SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL');
    $query->execute([$table]);
    return ['columns' => $columns, 'primary' => array_column($primary, 'Column_name'), 'foreign' => $query->fetchAll()];
}

function recordKey(array $row, array $primary): string
{
    return base64_encode(json_encode(array_intersect_key($row, array_flip($primary)), JSON_THROW_ON_ERROR));
}

function keyCondition(string $token, array $primary): array
{
    $decoded = base64_decode($token, true);
    $key = $decoded === false ? null : json_decode($decoded, true);
    if (!$primary || !is_array($key) || count($key) !== count($primary)) throw new RuntimeException('Clave del registro inválida.');
    $parts = $values = [];
    foreach ($primary as $field) {
        if (!array_key_exists($field, $key) || !is_scalar($key[$field])) throw new RuntimeException('Clave del registro inválida.');
        $parts[] = identifier($field) . ' = ?';
        $values[] = $key[$field];
    }
    return [implode(' AND ', $parts), $values];
}

function writable(array $column): bool
{
    return !preg_match('/auto_increment|VIRTUAL GENERATED|STORED GENERATED/i', $column['Extra']);
}

function formValues(array $columns, array $input, bool $editing, string $table = ''): array
{
    $values = [];
    foreach ($columns as $column) {
        if (!writable($column)) continue;
        $field = $column['Field'];
        $value = $input[$field] ?? '';
        if (!is_string($value)) throw new RuntimeException('Valor inválido en ' . $field);
        if ($value === '') {
            if ($column['Null'] === 'YES') { $values[$field] = null; continue; }
            if (!$editing && $column['Default'] !== null) continue;
            throw new RuntimeException('Completa el campo ' . $field . '.');
        }
        $type = strtolower($column['Type']);
        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)\b/', $type) && !preg_match('/^-?\d+$/D', $value)) throw new RuntimeException($field . ' debe ser un entero.');
        if (preg_match('/^(decimal|float|double)\b/', $type) && !is_numeric($value)) throw new RuntimeException($field . ' debe ser numérico.');
        if (str_contains($type, 'unsigned') && is_numeric($value) && $value < 0) throw new RuntimeException($field . ' no admite negativos.');
        if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $match) && (function_exists('mb_strlen') ? mb_strlen($value) : strlen($value)) > (int)$match[1]) throw new RuntimeException($field . ' excede la longitud permitida.');
        if ($type === 'date' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $match) || !checkdate((int)$match[2], (int)$match[3], (int)$match[1]))) throw new RuntimeException($field . ' requiere una fecha válida AAAA-MM-DD.');
        $values[$field] = $value;
    }
    if ($table === 'clientes') {
        if (isset($values['email']) && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Ingresa un correo electrónico válido.');
        if (isset($values['edad']) && $values['edad'] < 18) throw new RuntimeException('El cliente debe tener al menos 18 años.');
    }
    if ($table === 'pedidos' && isset($values['total']) && $values['total'] < 0) throw new RuntimeException('El total no puede ser negativo.');
    if ($table === 'productos') {
        if (isset($values['precio']) && $values['precio'] <= 0) throw new RuntimeException('El precio debe ser mayor que cero.');
        if (isset($values['stock']) && $values['stock'] < 0) throw new RuntimeException('El stock no puede ser negativo.');
    }
    return $values;
}
