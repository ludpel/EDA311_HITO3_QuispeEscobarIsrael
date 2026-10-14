<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config/Database.php';
require __DIR__ . '/config/DoublyLinkedList.php';
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$tables = ['clientes', 'pedidos', 'productos'];
$table = is_string($_GET['table'] ?? null) && in_array($_GET['table'], $tables, true) ? $_GET['table'] : 'clientes';
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$error = null;
$schema = null;
$list = new DoublyLinkedList();
$edit = null;
$stats = null;
$message = $_SESSION['message'] ?? null;
unset($_SESSION['message']);
try {
    $db = connectDatabase();
    $schema = tableSchema($db, $table);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) throw new RuntimeException('El formulario expiró. Recarga la página.');
        $action = $_POST['action'] ?? '';
        if (!in_array($action, ['create', 'update', 'delete'], true)) throw new RuntimeException('Operación inválida.');
        if ($action === 'delete' || $action === 'update') {
            [$where, $keys] = keyCondition((string)($_POST['key'] ?? ''), $schema['primary']);
        }
        if ($action === 'delete') {
            $query = $db->prepare('DELETE FROM ' . identifier($table) . ' WHERE ' . $where);
            $query->execute($keys);
        } else {
            $values = formValues($schema['columns'], (array)($_POST['fields'] ?? []), $action === 'update', $table);
            if ($action === 'create') {
                $sql = 'INSERT INTO ' . identifier($table) . ' (' . implode(', ', array_map('identifier', array_keys($values))) . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')';
                $params = array_values($values);
            } else {
                if (!$values) throw new RuntimeException('No hay campos editables.');
                $sql = 'UPDATE ' . identifier($table) . ' SET ' . implode(', ', array_map(fn($field) => identifier($field) . ' = ?', array_keys($values))) . ' WHERE ' . $where;
                $params = array_merge(array_values($values), $keys);
            }
            $db->prepare($sql)->execute($params);
        }
        $_SESSION['message'] = 'Operación realizada correctamente en MySQL.';
        header('Location: index.php?table=' . urlencode($table));
        exit;
    }
    if (isset($_GET['edit'])) {
        [$where, $keys] = keyCondition((string)$_GET['edit'], $schema['primary']);
        $query = $db->prepare('SELECT * FROM ' . identifier($table) . ' WHERE ' . $where);
        $query->execute($keys);
        $edit = $query->fetch();
        if (!$edit) throw new RuntimeException('El registro ya no existe.');
    }
    $query = $db->query('SELECT * FROM ' . identifier($table));
    while ($row = $query->fetch()) $list->append($row);
    $fields = array_column($schema['columns'], 'Field');
    $sort = is_string($_GET['sort'] ?? null) && in_array($_GET['sort'], $fields, true) ? $_GET['sort'] : ($schema['primary'][0] ?? $fields[0]);
    $direction = ($_GET['direction'] ?? '') === 'desc' ? 'desc' : 'asc';
    $sorted = ($_GET['view'] ?? 'sorted') !== 'original';
    $type = $schema['columns'][array_search($sort, $fields, true)]['Type'];
    $numeric = (bool)preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|float|double)\b/i', $type);
    if ($sorted) $stats = $list->bubbleSort(function ($a, $b) use ($sort, $direction, $numeric) {
        $left = $a[$sort]; $right = $b[$sort];
        if ($left === null || $right === null) $result = ($left !== null) <=> ($right !== null);
        else $result = $numeric ? $left <=> $right : strcasecmp((string)$left, (string)$right);
        return $direction === 'desc' ? -$result : $result;
    });
} catch (PDOException $exception) {
    $driverCode = (int)($exception->errorInfo[1] ?? 0);
    $error = match ($driverCode) {
        2002, 2003 => 'El servidor MySQL no responde. Comprueba la red o VPN de la universidad y el host y puerto de la conexión en Workbench.',
        1045 => 'MySQL rechazó el acceso. Verifica el usuario, la contraseña y los permisos de conexión desde este equipo.',
        1049 => 'La base configurada no existe en ese servidor. Verifica su nombre en SCHEMAS de Workbench.',
        1146 => 'No se encontró una de las tablas requeridas en la base configurada.',
        default => match ((string)$exception->getCode()) {
        '23000' => 'MySQL rechazó la operación por una clave duplicada o una relación entre tablas. Revisa los valores y los registros relacionados.',
        '22001', '22003', '22007' => 'Un valor no cumple el tipo, rango o longitud definido en MySQL.',
        default => 'No se pudo consultar MySQL. Revisa la conexión, los permisos y la existencia de las tablas clientes, pedidos y productos.',
        },
    };
    error_log('CRUD: ' . $exception->getMessage());
} catch (RuntimeException $exception) { $error = $exception->getMessage(); }
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Gestión · EDA311</title><link rel="stylesheet" href="assets/style.css"></head>
<body><aside><a class="brand" href="index.php">E<span>DA</span> / 311</a><p class="eyebrow">PROYECTO · HITO 3</p><nav><?php foreach ($tables as $item): ?><a class="<?= $table === $item ? 'active' : '' ?>" href="?table=<?= h($item) ?>"><?= h(ucfirst($item)) ?><span>↗</span></a><?php endforeach; ?></nav><div class="aside-note">PHP + MySQL<br>Lista doblemente enlazada<br>Bubble Sort</div></aside>
<main><header><div><p class="eyebrow">ADMINISTRACIÓN DE DATOS</p><h1><?= h(ucfirst($table)) ?></h1><p>Consulta y administra los registros de tu base de datos.</p></div><a class="button" href="?table=<?= h($table) ?>&new=1#form">+ Nuevo registro</a></header>
<?php if ($message): ?><p class="notice success" role="status"><?= h($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>
<?php if ($schema && !$error): ?>
<section class="summary"><div><strong><?= count($list) ?></strong><span>Registros en la lista</span></div><div><strong><?= count($schema['columns']) ?></strong><span>Campos detectados</span></div><div><strong><?= $stats ? $stats['comparisons'] : '—' ?></strong><span>Comparaciones de Bubble Sort</span></div></section>
<?php if (!$schema['primary']): ?><p class="notice">La tabla no tiene clave primaria. Define una clave en MySQL para habilitar edición y eliminación seguras.</p><?php endif; ?>
<?php if ($edit || isset($_GET['new'])): ?>
<section class="panel" id="form"><h2><?= $edit ? 'Modificar registro' : 'Registrar nuevo' ?></h2><form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>"><?php if ($edit): ?><input type="hidden" name="key" value="<?= h(recordKey($edit, $schema['primary'])) ?>"><?php endif; ?><div class="form-grid">
<?php foreach ($schema['columns'] as $column): if (!writable($column)) continue; $field = $column['Field']; $value = $edit[$field] ?? ''; ?>
<label><?= h($field) ?><small><?= h($column['Type']) ?> · <?= $column['Null'] === 'YES' ? 'opcional; vacío = NULL' : 'obligatorio' ?><?= !$edit && $column['Default'] !== null ? ' (vacío usa el valor predeterminado)' : '' ?></small><input name="fields[<?= h($field) ?>]" value="<?= h($value) ?>" <?= $column['Null'] !== 'YES' && ($edit || $column['Default'] === null) ? 'required' : '' ?>></label>
<?php endforeach; ?></div><div class="actions"><button type="submit">Guardar registro</button><a href="?table=<?= h($table) ?>">Cancelar</a></div></form></section>
<?php endif; ?>
<section class="panel"><div class="section-heading"><h2>Registros</h2><span class="badge"><?= $sorted ? 'Ordenados en la lista' : 'Orden de lectura de MySQL' ?></span></div><form class="toolbar" method="get"><input type="hidden" name="table" value="<?= h($table) ?>"><label>Campo<select name="sort"><?php foreach ($fields as $field): ?><option <?= $sort === $field ? 'selected' : '' ?>><?= h($field) ?></option><?php endforeach; ?></select></label><label>Dirección<select name="direction"><option value="asc">Ascendente</option><option value="desc" <?= $direction === 'desc' ? 'selected' : '' ?>>Descendente</option></select></label><label>Vista<select name="view"><option value="sorted">Bubble Sort</option><option value="original" <?= !$sorted ? 'selected' : '' ?>>Sin ordenar</option></select></label><button>Aplicar</button></form>
<div class="table-wrap"><table><thead><tr><?php foreach ($fields as $field): ?><th><?= h($field) ?></th><?php endforeach; ?><th>Acciones</th></tr></thead><tbody><?php foreach ($list as $row): ?><tr><?php foreach ($fields as $field): ?><td><?= $row[$field] === null ? '<span class="muted">NULL</span>' : h($row[$field]) ?></td><?php endforeach; ?><td><?php if ($schema['primary']): $key = recordKey($row, $schema['primary']); ?><div class="row-actions"><a href="?table=<?= h($table) ?>&edit=<?= urlencode($key) ?>#form">Editar</a><form method="post" onsubmit="return confirm('¿Eliminar este registro de MySQL?')"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="key" value="<?= h($key) ?>"><button class="delete">Eliminar</button></form></div><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?><?php if (!count($list)): ?><tr><td colspan="<?= count($fields) + 1 ?>" class="empty">Todavía no hay registros. Comienza creando uno.</td></tr><?php endif; ?></tbody></table></div>
<?php if ($stats): ?><p class="muted">Criterio: <?= h($sort) ?>, <?= h($direction) ?>. <?= $stats['passes'] ?> pasadas · <?= $stats['swaps'] ?> intercambios. Los datos se ordenan directamente entre nodos adyacentes.</p><?php endif; ?></section>
<details class="panel"><summary>Explorar la estructura real de <?= h($table) ?></summary><p>Clave primaria: <?= h(implode(', ', $schema['primary']) ?: 'No definida') ?></p><div class="table-wrap"><table><thead><tr><th>Campo</th><th>Tipo</th><th>NULL</th><th>Predeterminado</th><th>Extra</th></tr></thead><tbody><?php foreach ($schema['columns'] as $column): ?><tr><?php foreach (['Field', 'Type', 'Null', 'Default', 'Extra'] as $attribute): ?><td><?= h($column[$attribute] ?? 'NULL') ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><h3>Relaciones</h3><?php foreach ($schema['foreign'] as $foreign): ?><p><?= h($foreign['COLUMN_NAME']) ?> → <?= h($foreign['REFERENCED_TABLE_NAME']) ?>.<?= h($foreign['REFERENCED_COLUMN_NAME']) ?></p><?php endforeach; ?><?php if (!$schema['foreign']): ?><p>No se detectaron claves foráneas declaradas.</p><?php endif; ?></details>
<?php elseif (!$schema && !is_file(__DIR__ . '/config.local.php')): ?><section class="panel"><h2>Configura tu conexión</h2><p>Copia <code>config.example.php</code> a <code>config.local.php</code> y escribe los datos de conexión a MySQL.</p></section>
<?php elseif (!$schema): ?><section class="panel"><h2>Verifica el acceso al servidor</h2><p>Comprueba la conexión en MySQL Workbench, el acceso a la red y el puerto del servidor.</p><a class="button" href="?table=<?= h($table) ?>">Reintentar conexión</a></section><?php endif; ?>
<footer>EDA311 · Estructuras de datos · Hito 3</footer></main></body></html>
