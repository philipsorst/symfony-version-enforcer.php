<?php

namespace Dontdrinkandroot\SymfonyVersionEnforcer;

use Composer\Cache;
use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use InvalidArgumentException;
use LogicException;
use Override;

use function sprintf;

class SymfonyVersionEnforcerPlugin implements PluginInterface, EventSubscriberInterface
{
    private Composer|null $composer = null;

    private IOInterface|null $io = null;

    private Cache|null $cache = null;

    private VersionParser|null $versionParser = null;

    #[Override]
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    #[Override]
    public function deactivate(Composer $composer, IOInterface $io): void
    {
        /* Noop */
    }

    #[Override]
    public function uninstall(Composer $composer, IOInterface $io): void
    {
        /* Noop */
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
        $symfonyRequire = $this->getSymfonyRequire();
        if (null === $symfonyRequire) {
            return;
        }

        $restrictionInfoGiven = false;
        $symfonyConstraints = $this->getVersionParser()->parseConstraints($symfonyRequire);

        $rootPackage = $this->getComposer()->getPackage();
        $lockedPackages = $event->getRequest()->getFixedOrLockedPackages();
        $basePackages = $event->getPackages();
        $lockedVersions = $this->getLockedVersionsWithAliasVersion($lockedPackages);
        $rootConstraints = $this->getConstraintsByName($rootPackage->getRequires() + $rootPackage->getDevRequires());
        $knownVersions = $this->getKnownVersions();

        $filteredPackages = [];
        $symfonyPackages = [];
        $oneSymfony = false;
        foreach ($basePackages as $package) {
            $name = $package->getName();
            $versions = [$package->getVersion()];
            if ($package instanceof AliasPackage) {
                $versions[] = $package->getAliasOf()->getVersion();
            }

            if ('symfony/symfony' !== $name && (
                    !isset($knownVersions['splits'][$name])
                    || array_intersect($versions, $lockedVersions[$name] ?? [])
                    || (isset($rootConstraints[$name]) && !Intervals::haveIntersections($symfonyConstraints, $rootConstraints[$name]))
                    || ('symfony/psr-http-message-bridge' === $name && 6.4 > $versions[0])
                )) {
                $filteredPackages[] = $package;
                continue;
            }

            if (null !== $alias = $package->getExtra()['branch-alias'][$package->getVersion()] ?? null) {
                $versions[] = $this->getVersionParser()->normalize($alias);
            }

            foreach ($versions as $version) {
                if ($symfonyConstraints->matches(new Constraint('==', $version))) {
                    $filteredPackages[] = $package;
                    $oneSymfony = $oneSymfony || 'symfony/symfony' === $name;
                    continue 2;
                }
            }

            if ('symfony/symfony' === $name) {
                $symfonyPackages[] = $package;
            } elseif (!$restrictionInfoGiven) {
                $this->getIo()->writeError(sprintf('<info>Restricting packages listed in "symfony/symfony" to "%s"</>', $symfonyRequire));
                $restrictionInfoGiven = true;
            }
        }

        if ($symfonyPackages && !$oneSymfony) {
            $filteredPackages = array_merge($filteredPackages, $symfonyPackages);
        }

        $event->setPackages($filteredPackages);
    }

    /**
     * @return mixed[]
     */
    private function getKnownVersions(): array
    {
        $url = 'https://raw.githubusercontent.com/symfony/recipes/flex/main/index.json';

        $httpDownloader = Factory::createHttpDownloader($this->getIo(), $this->getComposer()->getConfig());

        $headers = [];

        if ($this->getIo()->hasAuthentication('github.com')) {
            $auth = $this->getIo()->getAuthentication('github.com');
            if ('x-oauth-basic' === $auth['password']) {
                $headers[] = 'Authorization: token ' . $auth['username'];
            }
        }

        $cacheKey = 'flex-definitions-json';
        $cachedResponse = [];
        if (false !== ($contents = $this->getCache()->read($cacheKey))) {
            $cachedResponse = json_decode($contents, true);
            if (null !== ($lastModified = $cachedResponse['lastModified'] ?? null)) {
                $headers[] = 'If-Modified-Since: ' . $lastModified;
            }
            if (null !== ($eTag = $cachedResponse['etag'] ?? null)) {
                $headers[] = 'If-None-Match: ' . $eTag;
            }
        }

        $options = ['http' => ['header' => $headers]];
        $response = $httpDownloader->get($url, $options);
        if (200 === $response->getStatusCode()) {
            $jsonResponseBody = JsonFile::parseJson($response->getBody(), $url);
            $cachedResponse = [
                'lastModified' => $response->getHeader('Last-Modified'),
                'etag' => $response->getHeader('ETag'),
                'versions' => $jsonResponseBody['versions'],
            ];
            $this->getCache()->write($cacheKey, self::assertNotFalse(json_encode($cachedResponse), 'Could not encode cachedResponse'));
        }

        return $cachedResponse['versions'];
    }

    /**
     * @template T
     * @param T|false $value
     * @return T
     */
    private static function assertNotFalse(mixed $value, string $message = 'The value is false'): mixed
    {
        return false !== $value
            ? $value
            : throw new InvalidArgumentException($message);
    }

    private function getIo(): IOInterface
    {
        return $this->io ?? throw new LogicException('IOInterface not set');
    }

    private function getCache(): Cache
    {
        return $this->cache ??= new Cache($this->getIo(), $this->getComposer()->getConfig()->get('cache-repo-dir') . '/ddrsve');
    }

    private function getComposer(): Composer
    {
        return $this->composer ?? throw new LogicException('Composer not set');
    }

    private function getVersionParser(): VersionParser
    {
        return $this->versionParser ??= new VersionParser();
    }

    private function getSymfonyRequire(): string|null
    {
        return preg_replace(
            '/\.x$/',
            '.x-dev',
            is_string($envVar = getenv('SYMFONY_REQUIRE'))
                ? $envVar
                : ($this->getComposer()->getPackage()->getExtra()['symfony']['require'] ?? '')
        );
    }

    /**
     * @param BasePackage[] $lockedPackages
     * @return array<string, non-empty-list<string>>
     */
    private function getLockedVersionsWithAliasVersion(array $lockedPackages): array
    {
        $lockedVersions = [];
        foreach ($lockedPackages as $package) {
            $lockedVersions[$package->getName()] = [$package->getVersion()];
            if ($package instanceof AliasPackage) {
                $lockedVersions[$package->getName()][] = $package->getAliasOf()->getVersion();
            }
        }

        return $lockedVersions;
    }

    /**
     * @param array<string, Link> $links
     * @return array<string, ConstraintInterface>
     */
    private function getConstraintsByName(array $links): array
    {
        $constraints = [];
        foreach ($links as $name => $link) {
            $constraints[$name] = $link->getConstraint();
        }

        return $constraints;
    }
}
