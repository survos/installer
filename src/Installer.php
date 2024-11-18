<?php

declare(strict_types=1);

namespace Endroid\Installer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

final class Installer implements PluginInterface, EventSubscriberInterface
{
    private const PROJECT_TYPE_ALL = 'all';

    private Composer $composer;
    private IOInterface $io;

    /** @var array<string, array<string>> */
    private array $projectTypes = [
        self::PROJECT_TYPE_ALL => [],
        'symfony' => [
            'config/packages',
            'public',
        ],
    ];

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
        $io->write('<warning>Activating installation...</warning>');
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::PRE_UPDATE_CMD => ['install', 1],
//            ScriptEvents::PRE_INSTALL_CMD => ['install', 1],
//            ScriptEvents::POST_INSTALL_CMD => ['install', 1],
//            ScriptEvents::POST_UPDATE_CMD => ['install', 1],
        ];
    }

    public function install(Event $event): void
    {
        $this->io->write('<warning>Endroid Installer about to install! ' . $event->getName() . '</>');
        $foundCompatibleProjectType = false;
        foreach ($this->projectTypes as $projectType => $paths) {
            if ($this->isCompatibleProjectType($paths)) {
                if (self::PROJECT_TYPE_ALL !== $projectType) {
                    $this->io->write('<info>Endroid Installer detected project type "'.$projectType.'"</>');
                    $foundCompatibleProjectType = true;
                }
                $this->installProjectType($projectType);
            }
        }

        if (!$foundCompatibleProjectType) {
            $this->io->write('<info>Endroid Installer did not detect a specific framework for auto-configuration</>');
        }
    }

    /** @param array<string> $paths */
    private function isCompatibleProjectType(array $paths): bool
    {
        foreach ($paths as $path) {
            if (!file_exists(getcwd().DIRECTORY_SEPARATOR.$path)) {
                return false;
            }
        }

        return true;
    }

    private function installProjectType(string $projectType): void
    {
        $exclude = $this->composer->getPackage()->getExtra()['endroid']['installer']['exclude'] ?? [];

        $processedPackages = [];
        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();

        foreach ($packages as $package) {

            // Avoid handling duplicates: getPackages sometimes returns duplicates
            if (in_array($package->getName(), $processedPackages)) {
                continue;
            }
            $processedPackages[] = $package->getName();

            // Skip excluded packages
            if (in_array($package->getName(), $exclude)) {
                $this->io->write('- Skipping <info>'.$package->getName().'</>');
                continue;
            }

            // Check for installation files and install
            $packagePath = $this->composer->getInstallationManager()->getInstallPath($package);
            $sourcePath = $packagePath.DIRECTORY_SEPARATOR.'.install'.DIRECTORY_SEPARATOR.$projectType;

            $this->insertIntoFile($package->getName(), $sourcePath . '/env.txt', '.env');
            $this->insertIntoFile($package->getName(), $sourcePath . '/gitignore.txt', '.gitignore');

            if (file_exists($postInstallPath = $sourcePath . '/post-install.txt')) {
                $content = file_get_contents($postInstallPath);
                $this->io->write($content);
            }
            $manifestPath = $packagePath.DIRECTORY_SEPARATOR.'.install'.DIRECTORY_SEPARATOR.'manifest.json';

            if (file_exists($manifestPath)) {
                $this->io->write($manifestPath);
            }
            if (file_exists($sourcePath)) {
                $this->io->write('<info>Installing package "'.$package->getName(). " $sourcePath</>");
                $changed = $this->copy($sourcePath, (string) getcwd());
                if ($changed) {
                    $this->io->write('- Configured <info>'.$package->getName().'</>');
                }
            }
        }
    }

    private function insertIntoFile(string $packageName, string $sourcePath, string $targetPath): void
    {
        if (file_exists($sourcePath)) {
            $sourceToInsert = file_get_contents($sourcePath);
            $existing = file_get_contents($targetPath);
            // look for existing .env section
            $key = sprintf('###> %s ###', $packageName);
            if (!str_contains($existing, $key)) {
                $existing .= "\n\n$key\n" . $sourceToInsert .
                    sprintf('###< %s ###', $packageName) . "\n";
                file_put_contents($targetPath, $existing);
            }
        }


    }

    private function copy(string $sourcePath, string $targetPath): bool
    {
        $this->io->write("- Copying $sourcePath to $targetPath <info></>");
        $changed = false;

        /** @var \RecursiveDirectoryIterator $iterator */
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourcePath, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            $target = $targetPath.DIRECTORY_SEPARATOR.$iterator->getSubPathName();
            if ($fileInfo->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target);
                }
            } elseif (!file_exists($target)) {
                // hack
                if (pathinfo($target, PATHINFO_EXTENSION) !== 'txt') {
                    $this->copyFile($fileInfo->getPathname(), $target);
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    public function copyFile(string $source, string $target): void
    {
        if (file_exists($target)) {
            return;
        }

        copy($source, $target);
        @chmod($target, fileperms($target) | (fileperms($source) & 0111));
    }
}
