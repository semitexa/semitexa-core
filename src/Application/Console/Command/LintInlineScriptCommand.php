<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Http\InlineScriptFinding;
use Semitexa\Core\Http\InlineScriptOwner;
use Semitexa\Core\Http\InlineScriptSweep;
use Semitexa\Core\Support\ProjectRoot;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Find inline `<script>` blocks that a strict `script-src` would refuse.
 *
 * The defect this exists for is silent by construction: the server renders
 * correct markup, returns 200, and the browser refuses the script. The SSR
 * deferred manifest shipped that way — the first paint of every deferred page
 * was unreachable for any consumer with a nonce policy, and nothing on the
 * server could tell.
 *
 * Two audiences, two verdicts:
 *   - a `packages/semitexa-*` emission FAILS. No consumer can fix it, and the
 *     framework asking its users to enable a policy it breaks is not a
 *     position worth defending.
 *   - an application's own emission is REPORTED. Whether it is broken depends
 *     on a policy only that project knows about, and turning 50 legitimate
 *     lines red is how a check gets switched off. `--strict` makes them fail
 *     too, which is the flag a project with a policy turns on.
 */
#[AsCommand(
    name: 'lint:inline-script',
    description: 'Find inline <script> blocks that a strict CSP would refuse.',
)]
final class LintInlineScriptCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('lint:inline-script')
            ->setDescription('Find inline <script> blocks that a strict CSP would refuse.')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Fail on application findings too, not only framework ones')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output findings as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $strict = (bool) $input->getOption('strict');
        $json = (bool) $input->getOption('json');

        $projectRoot = ProjectRoot::get();
        $findings = (new InlineScriptSweep())->sweep($projectRoot, [
            $projectRoot . '/packages',
            $projectRoot . '/src',
        ]);

        $framework = array_values(array_filter(
            $findings,
            static fn (InlineScriptFinding $f): bool => $f->owner === InlineScriptOwner::Framework
        ));
        $application = array_values(array_filter(
            $findings,
            static fn (InlineScriptFinding $f): bool => $f->owner === InlineScriptOwner::Application
        ));

        $blocking = $strict ? $findings : $framework;

        if ($json) {
            $output->writeln((string) json_encode([
                'clean' => $blocking === [],
                'strict' => $strict,
                'counts' => [
                    'framework' => count($framework),
                    'application' => count($application),
                ],
                'findings' => array_map(
                    static fn (InlineScriptFinding $f): array => $f->toArray(),
                    $findings
                ),
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $blocking === [] ? Command::SUCCESS : Command::FAILURE;
        }

        $io->title('Inline scripts under a strict CSP');

        if ($findings === []) {
            $io->success('No inline <script> without a nonce. A nonce policy will not break this build.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Owner', 'File', 'Line', 'Tag'],
            array_map(
                static fn (InlineScriptFinding $f): array => [$f->owner->value, $f->path, (string) $f->line, $f->snippet],
                $findings
            )
        );

        $io->writeln('Each of these runs only when script-src allows unsafe-inline. Give the tag a nonce');
        $io->writeln('(Semitexa\Core\Http\CspNonce::attribute()), or make it data — type="application/json"');
        $io->writeln('is not governed by script-src at all, and needs no policy from the host.');

        if ($framework !== []) {
            $io->error(sprintf('%d framework inline script(s) carry no nonce.', count($framework)));
        }

        if ($application !== []) {
            $message = sprintf('%d application inline script(s) carry no nonce.', count($application));
            $strict ? $io->error($message) : $io->warning($message . ' Reported, not blocking — pass --strict to gate on them.');
        }

        return $blocking === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
