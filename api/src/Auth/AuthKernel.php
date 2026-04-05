<?php

declare(strict_types=1);

namespace App\Auth;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Auth application kernel.
 *
 * Handles /auth/* requests. Shares Domain/ and Infrastructure/ layers
 * with the API kernel via the same codebase.
 */
final class AuthKernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/auth/' . $this->environment;
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

        $container->import($projectDir . '/config/auth/services.yaml');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $projectDir = $this->getProjectDir();

        $routes->import($projectDir . '/config/auth/routes.yaml');
    }
}
