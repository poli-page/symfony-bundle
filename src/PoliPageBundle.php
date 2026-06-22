<?php

declare(strict_types=1);

namespace PoliPage\Symfony;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class PoliPageBundle extends AbstractBundle
{
    protected string $extensionAlias = 'poli_page';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('api_key')
                    ->isRequired()
                    ->validate()
                        // Why: env vars take a circuitous path. The validator
                        // runs TWICE during MergeExtensionConfigurationPass —
                        // once with the env placeholder (env_<hash>_NAME_<hash>)
                        // and once with the resolved value (often '' when the
                        // env is unset at compile time but will be set at
                        // request time). Skip both placeholder-shaped and empty
                        // values; the SDK will reject a truly-empty key at
                        // construction. Reject only non-empty literal strings
                        // that don't match the pp_test_/pp_live_ prefix.
                        ->ifTrue(static fn (string $v): bool => '' !== $v
                            && !str_contains($v, '%env(')
                            && 1 !== preg_match('/^env_[a-f0-9]{16}_/i', $v)
                            && 1 !== preg_match('/^pp_(test|live)_/', $v))
                        ->thenInvalid('Poli Page API key must start with pp_test_ or pp_live_. Get one at https://app.poli.page/settings/api-keys. Got: %s')
                    ->end()
                ->end()
                ->scalarNode('base_url')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static function (?string $v): bool {
                            // Why: treat empty string like null (unset → SDK default).
                            // Symfony's ValidateEnvPlaceholdersPass validates a
                            // '%env(POLI_PAGE_BASE_URL)%' placeholder with a synthetic ""
                            // at compile time, so rejecting empty would forbid env-based
                            // base_url config entirely.
                            if (null === $v || '' === $v) {
                                return false;
                            }
                            $scheme = parse_url($v, \PHP_URL_SCHEME);

                            return !\in_array($scheme, ['http', 'https'], true);
                        })
                        ->thenInvalid('base_url must use http or https scheme. Got: %s')
                    ->end()
                ->end()
                ->floatNode('timeout')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (?float $v): bool => null !== $v && ($v <= 0 || $v > 600))
                        ->thenInvalid('timeout must be > 0 and <= 600 seconds. Got: %s')
                    ->end()
                ->end()
                ->scalarNode('user_agent')->defaultNull()->end()
                ->arrayNode('retries')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_attempts')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (?int $v): bool => null !== $v && ($v < 0 || $v > 10))
                                ->thenInvalid('retries.max_attempts must be between 0 and 10. Got: %s')
                            ->end()
                        ->end()
                        ->floatNode('delay_seconds')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (?float $v): bool => null !== $v && ($v < 0 || $v > 30))
                                ->thenInvalid('retries.delay_seconds must be between 0 and 30 seconds. Got: %s')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('http_client')->defaultNull()->end()
                ->scalarNode('request_factory')->defaultNull()->end()
                ->scalarNode('stream_factory')->defaultNull()->end()
                ->scalarNode('logger')->defaultNull()->end()
                ->scalarNode('on_retry')->defaultNull()->end()
                ->scalarNode('on_error')->defaultNull()->end()
                ->arrayNode('exception_listener')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<array-key, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__.'/../config/services.php');

        $builder->setParameter('poli_page.api_key', $config['api_key']);
        $builder->setParameter('poli_page.base_url', $config['base_url']);
        $builder->setParameter('poli_page.timeout', $config['timeout']);
        $builder->setParameter('poli_page.user_agent', $config['user_agent']);
        $builder->setParameter('poli_page.retries.max_attempts', $config['retries']['max_attempts']);
        $builder->setParameter('poli_page.retries.delay_seconds', $config['retries']['delay_seconds']);
        $builder->setParameter('poli_page.on_retry', $config['on_retry']);
        $builder->setParameter('poli_page.on_error', $config['on_error']);

        if (null !== $config['http_client']) {
            $builder->setAlias('poli_page.http_client', (string) $config['http_client']);
        }
        if (null !== $config['request_factory']) {
            $builder->setAlias('poli_page.request_factory', (string) $config['request_factory']);
        }
        if (null !== $config['stream_factory']) {
            $builder->setAlias('poli_page.stream_factory', (string) $config['stream_factory']);
        }
        if (null !== $config['logger']) {
            $builder->setAlias('poli_page.logger', (string) $config['logger']);
        }
        if (null !== $config['on_retry']) {
            $builder->setAlias('poli_page.retry_listener', (string) $config['on_retry'])->setPublic(true);
        }
        if (null !== $config['on_error']) {
            $builder->setAlias('poli_page.error_listener', (string) $config['on_error'])->setPublic(true);
        }

        if (true === ($config['exception_listener']['enabled'] ?? false)) {
            $builder
                ->register('poli_page.exception_listener', EventListener\PoliPageExceptionListener::class)
                ->addTag('kernel.event_subscriber')
                ->setPublic(true);
        }
    }
}
