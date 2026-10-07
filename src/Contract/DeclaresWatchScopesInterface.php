<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

/**
 * A route attribute that knows its feed's watch scopes — a field-driven feed
 * watches its model's table without also writing #[WatchScopes] by hand.
 */
interface DeclaresWatchScopesInterface
{
    /**
     * @param class-string $payloadClass the class the attribute sits on
     * @return list<string>
     */
    public function watchScopes(string $payloadClass): array;
}
