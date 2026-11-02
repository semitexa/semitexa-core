<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Exception\PayloadValidationException;
use Semitexa\Core\Pipeline\HandlerReflectionCache;
use Semitexa\Core\Pipeline\PayloadAdmission;
use Semitexa\Core\Pipeline\PipelineExecutor;
use Semitexa\Core\Pipeline\PipelineListenerRegistry;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Request;
use Semitexa\Core\Tests\Unit\Pipeline\InvalidPayload\FixedContainer;
use Semitexa\Core\Tests\Unit\Pipeline\InvalidPayload\FormLog;
use Semitexa\Core\Tests\Unit\Pipeline\InvalidPayload\FormPage;
use Semitexa\Core\Tests\Unit\Pipeline\InvalidPayload\FormPayload;
use Semitexa\Core\Tests\Unit\Pipeline\InvalidPayload\NoListeners;
use Semitexa\Core\Tests\Unit\Pipeline\InvalidPayload\SavesFormHandler;
use Semitexa\Core\Tests\Unit\Pipeline\InvalidPayload\ShowsFormAgainHandler;

/**
 * An HTML form whose payload failed validation is shown again by its handler
 * (HandlesInvalidPayloadInterface, bench-html-form-validation-422) instead of
 * a JSON 422; everything else keeps the 422.
 */
final class InvalidPayloadPipelineTest extends TestCase
{
    private const ERRORS = ['name' => ['Name may not be blank.']];

    protected function setUp(): void
    {
        FormLog::$calls = [];
        // As boot does for every discovered handler.
        HandlerReflectionCache::warm(ShowsFormAgainHandler::class);
        HandlerReflectionCache::warm(SavesFormHandler::class);
    }

    /** @param list<class-string> $handlers */
    private static function route(array $handlers): DiscoveredRoute
    {
        return new DiscoveredRoute(
            path: '/contact',
            methods: ['POST'],
            name: 'contact.send',
            requestClass: FormPayload::class,
            responseClass: FormPage::class,
            handlers: array_map(static fn (string $h): array => ['class' => $h], $handlers),
            type: 'http-request',
            transport: null,
            produces: null,
            consumes: null,
            module: 'core',
        );
    }

    private static function request(string $accept): Request
    {
        return new Request(method: 'POST', uri: '/contact', headers: ['Accept' => $accept], query: [], post: [], server: [], cookies: []);
    }

    #[Test]
    public function only_the_handler_that_answers_an_invalid_payload_runs_on_a_422_page(): void
    {
        $payload = new FormPayload();
        $payload->name = 'Ann';
        $context = new RequestPipelineContext(
            requestDto: $payload,
            route: self::route([ShowsFormAgainHandler::class, SavesFormHandler::class]),
            request: self::request('text/html'),
            resourceDto: new FormPage(),
        );
        $context->invalidPayload = new PayloadValidationException(self::ERRORS, $payload);
        $handlers = [ShowsFormAgainHandler::class => new ShowsFormAgainHandler(), SavesFormHandler::class => new SavesFormHandler()];

        (new PipelineExecutor(new FixedContainer($handlers), new FixedContainer([PipelineListenerRegistry::class => new NoListeners()] + $handlers)))->execute($context);

        self::assertSame(['invalid'], FormLog::$calls, 'neither handle() nor the other handler runs for an invalid request');
        $page = $context->resourceDto;
        self::assertInstanceOf(FormPage::class, $page);
        self::assertSame(['name' => 'Ann', 'errors' => self::ERRORS, 'status' => 422], $page->shown, 'what was submitted, the errors, and 422 already set');
        self::assertSame(422, $page->getStatusCode());
    }

    #[Test]
    public function a_valid_request_runs_handle_as_before(): void
    {
        $context = new RequestPipelineContext(requestDto: new FormPayload(), route: self::route([ShowsFormAgainHandler::class, SavesFormHandler::class]), request: self::request('text/html'), resourceDto: new FormPage());
        $handlers = [ShowsFormAgainHandler::class => new ShowsFormAgainHandler(), SavesFormHandler::class => new SavesFormHandler()];

        (new PipelineExecutor(new FixedContainer($handlers), new FixedContainer([PipelineListenerRegistry::class => new NoListeners()] + $handlers)))->execute($context);

        self::assertSame(['handle', 'save'], FormLog::$calls);
    }

    /** HTML preferred and a handler that answers: yes. Anything else keeps the JSON 422. */
    #[Test]
    public function only_an_html_request_to_a_route_with_an_answering_handler_is_answered(): void
    {
        $invalid = new PayloadValidationException(self::ERRORS, new FormPayload());
        $browser = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

        self::assertTrue(PayloadAdmission::answersInvalid($invalid, self::route([ShowsFormAgainHandler::class]), self::request($browser)));
        self::assertFalse(PayloadAdmission::answersInvalid($invalid, self::route([ShowsFormAgainHandler::class]), self::request('application/json')));
        self::assertFalse(PayloadAdmission::answersInvalid($invalid, self::route([ShowsFormAgainHandler::class]), self::request('*/*')), 'a wildcard keeps JSON');
        self::assertFalse(PayloadAdmission::answersInvalid($invalid, self::route([SavesFormHandler::class]), self::request($browser)), 'no handler answers');
        self::assertFalse(PayloadAdmission::answersInvalid(new PayloadValidationException(self::ERRORS), self::route([ShowsFormAgainHandler::class]), self::request($browser)), 'no payload to show again');
    }
}
