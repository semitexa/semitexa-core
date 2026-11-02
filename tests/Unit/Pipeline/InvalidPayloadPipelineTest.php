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
use Semitexa\Core\Tests\Unit\Pipeline\InvalidPayload\FreshPageHandler;
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

    /** A browser's form post prefers HTML; JSON and a wildcard keep the JSON 422. */
    #[Test]
    public function only_a_request_preferring_html_goes_on(): void
    {
        self::assertTrue(PayloadAdmission::prefersHtml(self::request('text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8')));
        self::assertFalse(PayloadAdmission::prefersHtml(self::request('application/json')));
        self::assertFalse(PayloadAdmission::prefersHtml(self::request('*/*')), 'a wildcard keeps JSON');
    }

    /**
     * Decided on the route as bound for the response's profile, with a
     * ResourceResponse to answer on (its status is the pipeline's to set).
     */
    #[Test]
    public function only_a_route_whose_bound_handler_answers_on_a_resource_response_is_answered(): void
    {
        $invalid = new PayloadValidationException(self::ERRORS, new FormPayload());

        self::assertTrue(PayloadAdmission::answersInvalid($invalid, self::route([ShowsFormAgainHandler::class]), new FormPage()));
        self::assertFalse(PayloadAdmission::answersInvalid($invalid, self::route([SavesFormHandler::class]), new FormPage()), 'no handler answers');
        self::assertFalse(PayloadAdmission::answersInvalid($invalid, self::route([ShowsFormAgainHandler::class]), new \stdClass()), 'no ResourceResponse to set 422 on');
        self::assertFalse(PayloadAdmission::answersInvalid(new PayloadValidationException(self::ERRORS), self::route([ShowsFormAgainHandler::class]), new FormPage()), 'no payload to show again');
    }

    /** A handler that hands back a fresh resource at 200 still answers 422. */
    #[Test]
    public function an_answer_at_200_becomes_422(): void
    {
        $payload = new FormPayload();
        $context = new RequestPipelineContext(requestDto: $payload, route: self::route([FreshPageHandler::class]), request: self::request('text/html'), resourceDto: new FormPage());
        $context->invalidPayload = new PayloadValidationException(self::ERRORS, $payload);
        HandlerReflectionCache::warm(FreshPageHandler::class);
        $handlers = [FreshPageHandler::class => new FreshPageHandler()];

        (new PipelineExecutor(new FixedContainer($handlers), new FixedContainer([PipelineListenerRegistry::class => new NoListeners()] + $handlers)))->execute($context);

        self::assertInstanceOf(FormPage::class, $context->resourceDto);
        self::assertSame(422, $context->resourceDto->getStatusCode());
    }
}
