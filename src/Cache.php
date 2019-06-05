<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex;

use Composer\Cache as BaseCache;
use Composer\IO\IOInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
class Cache extends BaseCache
{
    private $versions;
    private $versionParser;
    private $symfonyRequire;
    private $symfonyConstraints;

    public function setSymfonyRequire(string $symfonyRequire, array $versions, IOInterface $io = null)
    {
        $this->versionParser = new VersionParser();
        $this->symfonyRequire = $symfonyRequire;
        $this->symfonyConstraints = $this->versionParser->parseConstraints($symfonyRequire);

        foreach ($versions['splits'] as $name => $vers) {
            foreach ($vers as $i => $v) {
                $v = $this->versionParser->normalize($v);

                if (!$this->symfonyConstraints->matches(new Constraint('==', $v))) {
                    if (null !== $io) {
                        $io->writeError(sprintf('<info>Restricting packages listed in "symfony/symfony" to "%s"</info>', $this->symfonyRequire));
                        $io = null;
                    }
                    unset($vers[$i]);
                }
            }

            if (!$vers || $vers === $versions['splits'][$name]) {
                unset($versions['splits'][$name]);
            }
        }

        $this->versions = $versions;
    }

    public function read($file)
    {
        $content = parent::read($file);

        if (0 === strpos($file, 'provider-symfony$') && \is_array($data = json_decode($content, true))) {
            $content = json_encode($this->removeLegacyTags($data));
        }

        return $content;
    }

    public function removeLegacyTags(array $data): array
    {
        if (!$this->symfonyConstraints) {
            return $data;
        }

        foreach ($data['packages'] as $name => $versions) {
            if (!isset($this->versions['splits'][$name]) || null === $devMasterAlias = $versions['dev-master']['extra']['branch-alias']['dev-master'] ?? null) {
                continue;
            }

            foreach ($versions as $version => $composerJson) {
                if ('dev-master' === $version) {
                    $normalizedVersion = $this->versionParser->normalize($devMasterAlias);
                } else {
                    $normalizedVersion = $composerJson['version_normalized'];
                }

                if (!$this->symfonyConstraints->matches(new Constraint('==', $normalizedVersion))) {
                    unset($versions[$version]);
                }
            }

            $data['packages'][$name] = $versions;
        }

        if (null === $symfonySymfony = $data['packages']['symfony/symfony'] ?? null) {
            return $data;
        }

        foreach ($symfonySymfony as $version => $composerJson) {
            if ('dev-master' === $version) {
                $normalizedVersion = $this->versionParser->normalize($composerJson['extra']['branch-alias']['dev-master']);
            } else {
                $normalizedVersion = $composerJson['version_normalized'];
            }

            if (!$this->symfonyConstraints->matches(new Constraint('==', $normalizedVersion))) {
                unset($symfonySymfony[$version]);
            }
        }

        if ($symfonySymfony) {
            $data['packages']['symfony/symfony'] = $symfonySymfony;
        }

        return $data;
    }
}
