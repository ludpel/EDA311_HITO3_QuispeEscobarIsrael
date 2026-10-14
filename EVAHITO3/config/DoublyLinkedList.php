<?php
declare(strict_types=1);

final class Node
{
    public ?Node $previous = null;
    public ?Node $next = null;
    public function __construct(public array $data) {}
}

final class DoublyLinkedList implements IteratorAggregate, Countable
{
    private ?Node $head = null;
    private ?Node $tail = null;
    private int $length = 0;

    public function append(array $data): void
    {
        $node = new Node($data);
        $node->previous = $this->tail;
        if ($this->tail) {
            $this->tail->next = $node;
        } else {
            $this->head = $node;
        }
        $this->tail = $node;
        $this->length++;
    }

    public function getIterator(): Traversable
    {
        for ($node = $this->head; $node; $node = $node->next) {
            yield $node->data;
        }
    }

    public function reverse(): Traversable
    {
        for ($node = $this->tail; $node; $node = $node->previous) {
            yield $node->data;
        }
    }

    public function count(): int { return $this->length; }

    // Intercambia los datos de nodos adyacentes, sin ordenar un arreglo externo.
    public function bubbleSort(callable $compare): array
    {
        $comparisons = $swaps = $passes = 0;
        $end = null;
        if ($this->length < 2) return compact('comparisons', 'swaps', 'passes');
        do {
            $changed = false;
            $node = $this->head;
            $passes++;
            while ($node->next !== $end) {
                $comparisons++;
                if ($compare($node->data, $node->next->data) > 0) {
                    $temp = $node->data;
                    $node->data = $node->next->data;
                    $node->next->data = $temp;
                    $changed = true;
                    $swaps++;
                }
                $node = $node->next;
            }
            $end = $node;
        } while ($changed && $this->head->next !== $end);
        return compact('comparisons', 'swaps', 'passes');
    }
}
