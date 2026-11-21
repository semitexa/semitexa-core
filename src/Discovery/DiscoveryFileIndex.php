<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

/**
 * Which class each source file declares, remembered with the file's mtime, size and ctime, so a walk
 * of the tree reads only the files that changed (tk-discovery-registries-as-php-arrays).
 *
 * The walk itself still happens in every process: that is what finds a new file, a removed one
 * and an edited one, with no hook to forget. What it no longer does is open and tokenise 4000
 * unchanged files to learn again what they declare, which was 3.3-5.5 s of a worker's boot.
 *
 * A remembered answer is used only when the file's mtime, size and ctime are the ones recorded
 * AND both times are older than the walk that recorded it. They have one-second resolution: a
 * file saved in the second a walk ran, or after it, may have changed without showing it, so it
 * is read again and the table is written again with a later time. ctime is what catches a write
 * that kept the mtime and the size (cp -p, rsync -a, tar): no tool can keep it.
 *
 * @internal
 */
final class DiscoveryFileIndex
{
    private const STATE = 'files';

    /** @var array<array-key, mixed> path => [mtime, size, ctime, declared class or null] */
    private array $known = [];

    private int $knownBuiltAt = 0;

    /** @var array<string, array{0: int, 1: int, 2: int, 3: string|null}> */
    private array $seen = [];

    private bool $reread = false;

    private readonly int $startedAt;

    public function __construct(private readonly ?DiscoveryCacheStore $store)
    {
        $this->startedAt = time();

        $state = $store?->readState(self::STATE);
        if (is_array($state) && is_int($state['builtAt'] ?? null) && is_array($state['files'] ?? null)) {
            $this->knownBuiltAt = $state['builtAt'];
            $this->known = $state['files'];
        }
    }

    /**
     * @param callable(string): (string|false|null) $read tokenises the file and names what it declares; false when it could not read it
     */
    public function declaredClass(\SplFileInfo $file, callable $read): ?string
    {
        $path = $file->getPathname();
        if ($this->store === null) {
            return self::declared($read($path));
        }
        if (isset($this->seen[$path])) {
            return $this->seen[$path][3];
        }

        try {
            $mtime = $file->getMTime();
            $size = $file->getSize();
            $ctime = $file->getCTime();
        } catch (\RuntimeException) {
            // Gone between the listing and the stat: nothing to remember.
            return self::declared($read($path));
        }
        if ($mtime === false || $size === false) {
            return self::declared($read($path));
        }

        $known = $this->known[$path] ?? null;
        if (is_array($known)
            && ($known[0] ?? null) === $mtime
            && ($known[1] ?? null) === $size
            && ($known[2] ?? null) === $ctime
            && max($mtime, $ctime) < $this->knownBuiltAt
            && array_key_exists(3, $known)
            && ($known[3] === null || is_string($known[3]))
        ) {
            $class = $known[3];
        } else {
            $class = $read($path);
            $this->reread = true;
            if ($class === false) {
                // Not remembered: "nothing declared here" under this mtime and size would be
                // believed by every later walk, and the read may work again by then.
                return null;
            }
        }

        $this->seen[$path] = [$mtime, $size, $ctime, $class];

        return $class;
    }

    /**
     * Called once the walk has finished. An interrupted walk writes nothing: what it had read
     * so far is read again by the next one.
     */
    public function save(): void
    {
        if ($this->store === null) {
            return;
        }
        if (!$this->reread && $this->seen === $this->known) {
            return;
        }

        $this->store->writeState(self::STATE, ['builtAt' => $this->startedAt, 'files' => $this->seen]);
    }

    private static function declared(string|false|null $read): ?string
    {
        return $read === false ? null : $read;
    }
}
