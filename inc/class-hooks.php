<?php

namespace HM\Cavalcade\Runner;

/**
 * Hooks system for the Runner.
 *
 * This is a very lightweight clone of WordPress' hook system.
 */
class Hooks
{
    /**
     * Registered callbacks.
     *
     * @var array<int, callable[]> Indexed array of priority => list of callbacks.
     */
    protected $callbacks = [];

    /**
     * Register a callback for a hook.
     *
     * @param string $hook Hook to register callback for.
     * @param callable $callback Function to call when hook is triggered.
     * @param int $priority Priority to register at.
     */
    public function register($hook, $callback, $priority = 10)
    {
        $this->callbacks[ $hook ][ $priority ][] = $callback;
        ksort($this->callbacks[ $hook ]);
    }

    /**
     * Run a hook's callbacks.
     *
     * @param string $hook Hook to run.
     * @param mixed $value Main value to pass.
     * @param mixed ...$args Other arguments to pass.
     * @return mixed Filtered value after running through callbacks.
     */
    public function run($hook, $value = null, ...$args)
    {
        if (! isset($this->callbacks[ $hook ])) {
            return $value;
        }
        foreach ($this->callbacks[ $hook ] as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $value = $callback($value, ...$args);
            }
        }

        return $value;
    }
}
