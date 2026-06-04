<?php

declare(strict_types=1);

namespace Th3Mouk\MaterializedViewBundle\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Th3Mouk\MaterializedViewBundle\Th3MoukMaterializedViewBundle;

final class MaterializedViewBundleTestKernel extends Kernel
{
    /**
     * @param array<string, mixed> $matviewConfig
     */
    public function __construct(string $environment = 'test', bool $debug = true, private readonly array $matviewConfig = [])
    {
        parent::__construct($environment, $debug);
    }

    /**
     * @return iterable<BundleInterface>
     */
    #[Override]
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new DoctrineMigrationsBundle();
        yield new Th3MoukMaterializedViewBundle();
    }

    #[Override]
    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/matview-bundle-kernel/'.$this->fingerprint().'/cache';
    }

    #[Override]
    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/matview-bundle-kernel/'.$this->fingerprint().'/log';
    }

    #[Override]
    public function getProjectDir(): string
    {
        return sys_get_temp_dir().'/matview-bundle-kernel/'.$this->fingerprint();
    }

    #[Override]
    public function getBuildDir(): string
    {
        return sys_get_temp_dir().'/matview-bundle-kernel/'.$this->fingerprint().'/build';
    }

    #[Override]
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'test' => true,
                'secret' => 'matview-test',
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => false],
                'messenger' => [
                    'default_bus' => 'messenger.bus.default',
                    'buses' => ['messenger.bus.default' => null],
                ],
            ]);

            $container->loadFromExtension('doctrine', [
                'dbal' => [
                    'driver' => 'pdo_pgsql',
                    'url' => 'pgsql://user:pass@127.0.0.1:5432/matview_test_kernel',
                    'server_version' => '16',
                ],
                'orm' => [
                    'auto_mapping' => false,
                ],
            ]);

            $container->loadFromExtension('th3mouk_materialized_view', $this->matviewConfig);
        });
    }

    private function fingerprint(): string
    {
        return substr(md5(serialize($this->matviewConfig)), 0, 12);
    }
}
