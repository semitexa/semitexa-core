<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ServiceContractRegistry;

class ServiceContractRegistryTest extends TestCase
{
    public function test_get_contracts_returns_interface_to_active_implementation(): void
    {
        $registry = new ServiceContractRegistry();
        $contracts = $registry->getContracts();

        $this->assertIsArray($contracts);
        foreach ($contracts as $interface => $impl) {
            $this->assertNotEmpty($interface);
            $this->assertNotEmpty($impl);
            $this->assertTrue(interface_exists($interface), "Contract {$interface} should be an interface");
            $this->assertTrue(class_exists($impl), "Implementation {$impl} should be a class");
            $this->assertTrue(is_subclass_of($impl, $interface), "{$impl} should implement {$interface}");
        }
    }

    public function test_get_contract_details_contains_implementations_and_active(): void
    {
        $registry = new ServiceContractRegistry();
        $details = $registry->getContractDetails();

        foreach ($details as $interface => $data) {
            $this->assertArrayHasKey('implementations', $data);
            $this->assertArrayHasKey('active', $data);
            $this->assertIsArray($data['implementations']);
            $this->assertContains($data['active'], array_column($data['implementations'], 'class'));
            foreach ($data['implementations'] as $item) {
                $this->assertArrayHasKey('module', $item);
                $this->assertArrayHasKey('class', $item);
            }
        }
    }

    public function test_get_contracts_is_derived_from_contract_details(): void
    {
        $registry = new ServiceContractRegistry();
        $contracts = $registry->getContracts();
        $details = $registry->getContractDetails();

        $this->assertSame(array_keys($details), array_keys($contracts));
        foreach ($details as $interface => $data) {
            $this->assertSame($data['active'], $contracts[$interface]);
        }
    }
}
