<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

/**
 * Fixture: Factory* interfaces FactoryContractRule must and must not flag.
 * Every declaration here loads; the one that does not (a Factory* interface
 * narrowing ContractFactoryInterface::get()) lives in FactoryContractsNarrowing.php.
 */
enum FixtureChannelKind: string
{
    case Mail = 'mail';
    case Sms = 'sms';
}

interface FixtureChannel
{
}

interface FactoryFixtureChannel
{
    public function getDefault(): FixtureChannel;

    public function get(FixtureChannelKind $key): FixtureChannel;

    public function keys(): array;
}

interface FactoryWithoutGet
{
    public function getDefault(): FixtureChannel;
}

interface FactoryStringKeyed
{
    public function get(string $key): FixtureChannel;
}
