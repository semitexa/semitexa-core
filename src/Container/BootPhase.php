<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

enum BootPhase: int
{
    case ClassmapLoad = 1;
    case ModuleDiscovery = 2;
    case AttributeScan = 3;
    case ContractResolution = 4;
    case ServiceRegistration = 5;
    case ScopeDetection = 6;
    case InjectionAnalysis = 7;
    case CycleDetection = 8;
    case ReadonlyBuild = 9;
    case ExecutionScopedBuild = 10;
    case ResolverBuild = 11;
    case FactoryBuild = 12;
    case Validation = 13;
    case Ready = 14;
}
