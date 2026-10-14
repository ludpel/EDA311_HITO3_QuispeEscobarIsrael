<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/DoublyLinkedList.php';
require dirname(__DIR__) . '/config/Database.php';
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
foreach ([[], [1], [3, 2, 1], [1, 2, 3], [4, 1, 4, 2, 0]] as $input) {
    $list = new DoublyLinkedList();
    foreach ($input as $index => $value) $list->append(['value' => $value, 'index' => $index]);
    check(count($list) === count($input), 'Conteo incorrecto');
    check(array_column(iterator_to_array($list->reverse()), 'value') === array_reverse($input), 'Enlaces anteriores incorrectos');
    $list->bubbleSort(fn($a, $b) => $a['value'] <=> $b['value']);
    $expected = $input;
    sort($expected); // Solo el oráculo de la prueba usa arreglos, no la implementación.
    $rows = iterator_to_array($list);
    check(array_column($rows, 'value') === $expected, 'Orden ascendente incorrecto');
    for ($i = 1; $i < count($rows); $i++) {
        if ($rows[$i - 1]['value'] === $rows[$i]['value']) check($rows[$i - 1]['index'] < $rows[$i]['index'], 'Ordenamiento inestable');
    }
    check(array_column(iterator_to_array($list->reverse()), 'value') === array_reverse($expected), 'Recorrido inverso incorrecto después de ordenar');
    $list->bubbleSort(fn($a, $b) => $b['value'] <=> $a['value']);
    check(array_column(iterator_to_array($list), 'value') === array_reverse($expected), 'Orden descendente incorrecto');
}
$key = recordKey(['a' => 5, 'b' => 'cliente', 'other' => 'omitido'], ['a', 'b']);
[$where, $values] = keyCondition($key, ['a', 'b']);
check($where === '`a` = ? AND `b` = ?' && $values === [5, 'cliente'], 'Clave compuesta incorrecta');
try { keyCondition(base64_encode('{"a":[]}'), ['a']); throw new LogicException('Se aceptó una clave inválida'); }
catch (RuntimeException $exception) { check(!$exception instanceof LogicException, 'Validación de claves incorrecta'); }
echo "OK: lista, recorrido inverso, Bubble Sort estable y claves compuestas.\n";
