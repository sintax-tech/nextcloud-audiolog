<?php
/**
 * Private server types referenced by public OCP interfaces (nextcloud/ocp
 * ships only OCP\). Declared empty so PHPUnit can mock those interfaces.
 */
declare(strict_types=1);

namespace OC\Hooks;

if (!interface_exists(Emitter::class)) {
    interface Emitter {
        public function listen($scope, $method, callable $callback);
        public function removeListener($scope = null, $method = null, ?callable $callback = null);
    }
}
