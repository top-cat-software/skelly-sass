<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * API application kernel.
 *
 * Handles /api/* requests. Shares Domain/ and Infrastructure/ layers
 * with the Auth kernel via the same codebase.
 */
class ApiKernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/api/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $projectDir = $this->getProjectDir();

        $container->import($projectDir . '/config/packages/*.yaml');

        $envDir = $projectDir . '/config/packages/' . $this->environment;
        if (is_dir($envDir)) {
            $container->import($envDir . '/*.yaml');
        }

        $container->import($projectDir . '/config/api/services.yaml');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $projectDir = $this->getProjectDir();

        $routes->import($projectDir . '/config/api/routes.yaml');
    }
}
