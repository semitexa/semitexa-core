<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

/**
 * Where a container-managed class initializes itself.
 *
 * The container builds these objects with `newInstanceWithoutConstructor()` and
 * fills the `#[InjectAs*]` properties afterwards, so a constructor never runs on
 * them — anything written in one is silently skipped. That is the trap this
 * interface exists to replace: implement it and {@see initialize()} runs once,
 * immediately after every injected property is populated and before the object
 * is handed to anyone.
 *
 * Use it for work that depends on the injected dependencies (deriving a value,
 * warming a lookup, validating a combination of configuration). Do not use it to
 * do I/O: for an execution-scoped service this runs on every request, and the
 * container has no way to tell a slow initializer from a slow request.
 *
 * A no-arg constructor is still accepted on classes that are NOT container-managed
 * — DTOs, payloads, resources, value objects — where it is called normally.
 */
interface InitializesAfterInjectionInterface
{
    /**
     * Called once per instance, after injection, before first use.
     *
     * Throwing here aborts construction: the container reports the class rather
     * than handing back a half-built object.
     */
    public function initialize(): void;
}
