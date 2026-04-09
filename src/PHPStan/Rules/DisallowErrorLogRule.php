<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.disallowErrorLog
 *
 * Flags direct error_log() calls. Use LoggerInterface or SsrLogger instead.
 *
 * Exceptions: ServerLifecycleFallbackLogger and BootDiagnostics (pre-container boot),
 * and SsrLogger (the fallback wrapper itself).
 *
 * @implements Rule<FuncCall>
 */
final class DisallowErrorLogRule implements Rule
{
    private const ALLOWED_CLASSES = [
        'Semitexa\\Core\\Server\\ServerLifecycleFallbackLogger',
        'Semitexa\\Core\\Discovery\\BootDiagnostics',
        'Semitexa\\Ssr\\Log\\SsrLogger',
        'Semitexa\\Core\\Log\\AsyncJsonLogger',
    ];

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name) {
            return [];
        }

        if ($node->name->toLowerString() !== 'error_log') {
            return [];
        }

        $classReflection = $scope->getClassReflection();
        if ($classReflection !== null) {
            foreach (self::ALLOWED_CLASSES as $allowedClass) {
                if ($classReflection->getName() === $allowedClass) {
                    return [];
                }
            }
        }

        return [
            RuleErrorBuilder::message(
                'Direct error_log() calls are discouraged. '
                . 'Use Semitexa\\Core\\Log\\LoggerInterface (via #[InjectAsReadonly]) '
                . 'or Semitexa\\Ssr\\Log\\SsrLogger for static contexts.'
            )->identifier('semitexa.disallowErrorLog')->build(),
        ];
    }
}
