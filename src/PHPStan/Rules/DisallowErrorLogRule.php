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
 * Flags direct error_log() calls. Use LoggerInterface or StaticLoggerBridge instead.
 *
 * Exceptions: ServerLifecycleFallbackLogger and BootDiagnostics (pre-container boot),
 * FallbackErrorLogger, StaticLoggerBridge (the static fallback bridge), and AsyncJsonLogger.
 *
 * @implements Rule<FuncCall>
 */
final class DisallowErrorLogRule implements Rule
{
    /** Printed with every report of this rule: why it exists, and what taught us. */
    private const RATIONALE = 'Why: error_log() writes outside the application log: the entry has no channel and no request context, and `ai:ask logs` cannot see it. Policy since 2026-04-09 (the logger bridge); no incident on record.';

    private const ALLOWED_CLASSES = [
        'Semitexa\\Core\\Server\\ServerLifecycleFallbackLogger',
        'Semitexa\\Core\\Discovery\\BootDiagnostics',
        'Semitexa\\Core\\Log\\FallbackErrorLogger',
        'Semitexa\\Core\\Log\\StaticLoggerBridge',
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
                . 'or Semitexa\\Core\\Log\\StaticLoggerBridge for static contexts.'
            )->tip(self::RATIONALE)->identifier('semitexa.disallowErrorLog')->build(),
        ];
    }
}
