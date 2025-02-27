<?php

namespace Dontdrinkandroot\SymfonyVersionEnforcer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PrePoolCreateEvent;
use Override;

class SymfonyVersionEnforcerPlugin implements PluginInterface, EventSubscriberInterface
{
    #[\Override]
    public function activate(Composer $composer, IOInterface $io): void
    {
        $io->write("Symfony Version Enforcer plugin activated.");
        // TODO: Continue
    }

    #[\Override]
    public function deactivate(Composer $composer, IOInterface $io): void
    {
        $io->write("Symfony Version Enforcer plugin deactivated.");
        // TODO: Continue
    }

    #[\Override]
    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $io->write("Symfony Version Enforcer plugin uninstalled.");
        // TODO: Continue
    }

    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::PRE_POOL_CREATE => 'truncatePackages',
        ];
    }

    public function truncatePackages(PrePoolCreateEvent $event): void
    {
        // TODO: Continue
    }
}
