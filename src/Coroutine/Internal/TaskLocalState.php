<?php

declare(strict_types=1);

namespace Infocyph\Runwire\Coroutine\Internal;

use Infocyph\Runwire\Coroutine\Enum\TaskLocalInheritance;
use Infocyph\Runwire\Coroutine\TaskLocal;

/** @internal */
final class TaskLocalState
{
    /** @var array<int, array{key: TaskLocal, value: mixed}> */
    private array $entries = [];

    /**
     * Remove all task-local values.
     */
    public function clear(): void
    {
        $this->entries = [];
    }

    /**
     * Fork inheritable task-local values into a child state.
     */
    public function fork(): self
    {
        $child = new self();

        foreach ($this->entries as $id => $entry) {
            if ($entry['key']->inheritance() !== TaskLocalInheritance::SNAPSHOT) {
                continue;
            }

            $child->entries[$id] = $entry;
        }

        return $child;
    }

    /**
     * Return the value associated with a task-local key.
     */
    public function get(TaskLocal $key): mixed
    {
        $id = spl_object_id($key);
        if (!isset($this->entries[$id])) {
            return $key->default();
        }

        return $this->entries[$id]['value'];
    }

    /**
     * Determine whether a value is stored for the key.
     */
    public function has(TaskLocal $key): bool
    {
        return isset($this->entries[spl_object_id($key)]);
    }

    /**
     * Remove a task-local value when present.
     */
    public function remove(TaskLocal $key): bool
    {
        $id = spl_object_id($key);
        if (!isset($this->entries[$id])) {
            return false;
        }

        unset($this->entries[$id]);

        return true;
    }

    /**
     * Store a task-local value.
     */
    public function set(TaskLocal $key, mixed $value): void
    {
        $this->entries[spl_object_id($key)] = [
            'key' => $key,
            'value' => $value,
        ];
    }
}
