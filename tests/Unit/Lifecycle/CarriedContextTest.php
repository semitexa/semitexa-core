<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Lifecycle;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Lifecycle\CarriedContext;
use Semitexa\Core\Support\CoroutineLocal;

/**
 * What a request's work run elsewhere gets of its context
 * (tk-handler-execution-sync-async-queued): the keys the stores named, as the
 * request held them, and nothing else of the coroutine's. The names are
 * worker-wide and never forgotten (the stores name theirs once): each test
 * names keys of its own.
 */
final class CarriedContextTest extends TestCase
{
    #[Test]
    public function a_coroutine_of_its_own_gets_what_was_named_and_nothing_else(): void
    {
        $seen = [];
        \Swoole\Coroutine\run(static function () use (&$seen): void {
            CarriedContext::carry('test.tenant', 'test.locale');
            CoroutineLocal::set('test.tenant', 'acme');
            CoroutineLocal::set('test.connection', 'a socket of this coroutine');
            $captured = CarriedContext::capture();

            \Swoole\Coroutine::create(static function () use ($captured, &$seen): void {
                CarriedContext::runWith($captured, static function () use (&$seen): void {
                    $seen = [CoroutineLocal::get('test.tenant'), CoroutineLocal::get('test.locale'), CoroutineLocal::get('test.connection')];
                });
                $seen[] = CoroutineLocal::get('test.tenant');
            });
        });

        self::assertSame(['acme', null, null, null], $seen, 'the tenant while it runs, gone after; never the connection');
    }

    /** The request clears its own once the response is out; the work runs after that, in the same coroutine. */
    #[Test]
    public function after_the_request_cleared_it_the_work_gets_it_back_and_the_clearing_stays(): void
    {
        $seen = [];
        \Swoole\Coroutine\run(static function () use (&$seen): void {
            CarriedContext::carry('test.user', 'test.theme');
            CoroutineLocal::set('test.user', 'ann');
            $captured = CarriedContext::capture();
            CoroutineLocal::remove('test.user');
            CoroutineLocal::set('test.theme', 'set after the capture');

            CarriedContext::runWith($captured, static function () use (&$seen): void {
                $seen[] = [CoroutineLocal::get('test.user'), CoroutineLocal::get('test.theme')];
            });
            $seen[] = [CoroutineLocal::get('test.user'), CoroutineLocal::get('test.theme')];
        });

        self::assertSame([['ann', null], [null, 'set after the capture']], $seen);
    }
}
