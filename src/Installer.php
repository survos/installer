<?php

declare(strict_types=1);

namespace Survos\Installer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Installer\PackageEvent;
use Composer\Script\ScriptEvents;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Finder\Finder;

final class Installer implements PluginInterface, EventSubscriberInterface, Capable, CommandProvider {
    private const PROJECT_TYPE_ALL = 'all';

    private Composer $composer;
    private IOInterface $io;

    /** @var array<string, array<string>> */
    private array $projectTypes = [
        'recipe' => [
            'config/packages',
            'public',
        ],
    ];

    public function activate(Composer $composer, IOInterface $io): void {
        $this->composer = $composer;
        $this->io = $io;
        if ($io->isVeryVerbose()) {
            $io->write('<warning>Activating installation...</warning>');
        }
    }

    public function deactivate(Composer $composer, IOInterface $io): void {
    }

    public function uninstall(Composer $composer, IOInterface $io): void {
    }

    public static function getSubscribedEvents(): array {
        //return [];
        // return [
        //     //   ScriptEvents::PRE_UPDATE_CMD => ['install', 1],
        //     //     ScriptEvents::PRE_INSTALL_CMD => ['install', 1],
        //     //    ScriptEvents::POST_INSTALL_CMD => ['install', 1],
        //     //    ScriptEvents::POST_UPDATE_CMD => ['install', 1],
        // ];

        return [
            'post-package-install' => 'onPackageInstall',
            'post-package-update' => 'onPackageUpdate',
        ];
    }

    public function onPackageInstall(PackageEvent $event)
    {
        $this->io->write('<warning>Survos Installer about to install! ' . $event->getName() . '</>');
        $this->processInstall($event);
    }

    public function onPackageUpdate(PackageEvent $event)
    {
        $this->processInstall($event);
    }

    private function processInstall(PackageEvent $event) {
        $operation = $event->getOperation();
        if (method_exists($operation, 'getPackage')) {
            $package = $operation->getPackage();
        } else {
//            $this->io->warning('Unable to retrieve package from operation');
            return;
        }
        $packageName = $package->getName();
        $installPath = $this->composer->getInstallationManager()->getInstallPath($package);

        //reference files paths
        $env = $installPath . '/.install/symfony/env.txt';
        $gitignore = $installPath . '/.install/symfony/gitignore.txt';
        $postInstall = $installPath . '/.install/symfony/post-install.txt';

        //.env
        if (file_exists($env)) {
            $this->io->write("<info>Applying env from {$packageName}</info>");
            $this->applyEnvVars($env, getcwd() . '/.env', $packageName);
        }

        // .gitignore
        if (file_exists($gitignore)) {
            $this->io->write("<info>Adding .gitignore rules from {$packageName}</info>");
            //$this->applyLinesToFile($gitignore, getcwd() . '/.gitignore');
            $this->writeScopedBlock($gitignore, getcwd() . '/.gitignore', $packageName);
        }

        // post-install.txt
        if (file_exists($postInstall)) {
            $this->io->write("\n<comment>Post-install message from {$packageName}:</comment>");
            $this->io->write(file_get_contents($postInstall));
        }

        // check if package have manifest file and extarct it s content
        $manifestPath = $installPath . '/.install/manifest.yaml';
        if (file_exists($manifestPath)) {
            $this->io->write("<info>Applying manifest from {$packageName}</info>");
            // Parse YAML file
            $yamlContent = Yaml::parseFile($manifestPath);
            // Work with the parsed data
            //print_r($yamlContent);
        }

        //search for yaml files in the package in the folder .install/symfony in all subfolders and copy them to the project keeping their same path
        $yamlFiles = [];
        //make sure $installPath . '/.install/symfony' exists
        if (file_exists($installPath . '/.install/symfony')) {
            $finder = new Finder();
            $finder->files()
                ->in($installPath . '/.install/symfony')
                ->name('*.yaml')
                ->name('*.yml');


            foreach ($finder as $file) {
                $yamlFiles[] = $file->getRealPath();
            }
        }

        foreach ($yamlFiles as $yamlFile) {
            $targetPath = str_replace($installPath . '/.install/', '', $yamlFile);
            //remove symfony/ from the path
            $targetPath = str_replace('symfony/', '', $targetPath);
            //file in target path must not exist
            if (file_exists($targetPath)) {
                $this->io->write("<error>File {$targetPath} already exists. Skipping copy.</error>");
                continue;
            }
            //if target path does not exist, create the directory
            if (!file_exists($targetPath)) {
                mkdir(dirname($targetPath), 0777, true);
            }
            copy($yamlFile, $targetPath);
            $this->io->write("<info> Copying {$yamlFile} to {$targetPath}</info>");
        }

    }

    private function applyLinesToFile(string $sourceFile, string $targetFile): void {
        $newLines = file($sourceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!file_exists($targetFile)) {
            file_put_contents($targetFile, '');
        }

        $existing = file($targetFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($newLines as $line) {
            if (!in_array($line, $existing, true)) {
                file_put_contents($targetFile, "$line\n", FILE_APPEND);
            }
        }
    }

    private function applyEnvVars(string $sourceFile, string $targetFile,string $packageName) : void
    {
        $newVars = file($sourceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!file_exists($targetFile)) {
            file_put_contents($targetFile, '');
        }

        $existing = file_get_contents($targetFile);
        $newContent = "";
        foreach ($newVars as $line) {
            if (!str_contains($existing, $line)) {
                //file_put_contents($targetFile, "$line\n", FILE_APPEND);
                $newContent .= "$line\n";
            }
        }
        if (!empty($newContent)) {
            //make it scoped
            $this->writeScopedBlock($sourceFile, $targetFile, $packageName);
            //file_put_contents($targetFile, $newContent, FILE_APPEND);
        }

    }



    public function install(Event $event): void {
        $this->io->write('<warning>Survos Installer about to install! ' . $event->getName() . '</>');
        $foundCompatibleProjectType = false;
        foreach ($this->projectTypes as $projectType => $paths) {
            if ($this->isCompatibleProjectType($paths)) {
                if (self::PROJECT_TYPE_ALL !== $projectType) {
                    $this->io->write('<info>Survos Installer detected project type "' . $projectType . '"</>');
                    $foundCompatibleProjectType = true;
                }
                $this->installProjectType($projectType);
            }
        }

        if (!$foundCompatibleProjectType) {
            $this->io->write('<info>Survos Installer did not detect a specific framework for auto-configuration</>');
        }
    }

    private function writeScopedBlock(string $sourceFile, string $targetFile, string $packageName): void {
        $newLines = file($sourceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($newLines)) {
            return;
        }

        $blockStart = "###> $packageName ###";
        $blockEnd = "###< $packageName ###";

        if (!file_exists($targetFile)) {
            file_put_contents($targetFile, '');
        }

        $content = file_get_contents($targetFile);

        // Remove existing block
        // $pattern = "/###> {$packageName} ###.*?###< {$packageName} ###\n?/s";
        // $content = preg_replace($pattern, '', $content);

        // Append new block
        $block = $blockStart . "\n" . implode("\n", $newLines) . "\n" . $blockEnd . "\n";
        file_put_contents($targetFile, rtrim($content) . "\n\n" . $block);
    }

    private function removeScopedBlock(string $targetFile, string $packageName): void {
        if (!file_exists($targetFile)) {
            return;
        }

        $content = file_get_contents($targetFile);
        $pattern = "/###> {$packageName} ###.*?###< {$packageName} ###\n?/s";
        $newContent = preg_replace($pattern, '', $content);

        file_put_contents($targetFile, trim($newContent) . "\n");
    }



    /** @param array<string> $paths */
    private function isCompatibleProjectType(array $paths): bool {
        foreach ($paths as $path) {
            if (!file_exists(getcwd() . DIRECTORY_SEPARATOR . $path)) {
                return false;
            }
        }

        return true;
    }

    private function installProjectType(string $projectType): void {
        $exclude = $this->composer->getPackage()->getExtra()['survos']['installer']['exclude'] ?? [];

        $processedPackages = [];
        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
        $alreadyInstalled = file_exists('symfony.lock')
            ? array_keys(json_decode(file_get_contents('symfony.lock'), true)) : [];

        foreach ($packages as $package) {

            // Check for installation files and install
            $packagePath = $this->composer->getInstallationManager()->getInstallPath($package);
            $sourcePath = $packagePath . DIRECTORY_SEPARATOR . $projectType;
            if (!str_contains($sourcePath, 'survos')) {
                continue;
            }
            if (!file_exists($sourcePath)) {
                $this->io->error($sourcePath);
                die();
                continue;
            }
            $this->io->warning($sourcePath);

            // Avoid handling duplicates: getPackages sometimes returns duplicates
            $this->io->write($sourcePath);
            if (in_array($package->getName(), $processedPackages)) {
                $this->io->error($package->getName() . "  already processed ");
                die();
                continue;
            }

            if (in_array($package->getName(), $alreadyInstalled)) {
                $this->io->write('- Skipping <info>' . $package->getName() . ', already installed</>');
                //                continue;
            }
            $processedPackages[] = $package->getName();

            // Skip excluded packages
            if (in_array($package->getName(), $exclude)) {
                $this->io->write('- Skipping <info>' . $package->getName() . '</>');
                //                continue;
            }


            //            $this->io->write($sourcePath); die();
            //            $this->insertIntoFile($package->getName(), $sourcePath . '/env.txt', '.env');
            //            $this->insertIntoFile($package->getName(), $sourcePath . '/gitignore.txt', '.gitignore');

            if (file_exists($postInstallPath = $sourcePath . '/post-install.txt')) {
                $content = file_get_contents($postInstallPath);
                $this->io->write($content);
            } else {
                $this->io->warning("Missing $postInstallPath");
            }
            $manifestPath = $packagePath . DIRECTORY_SEPARATOR . 'manifest.yaml';
            die($manifestPath);
            return;

            if (file_exists($manifestPath)) {
                $this->io->write($manifestPath);
            }
            if (file_exists($sourcePath)) {
                $this->io->write('<info>Installing package "' . $package->getName() . " $sourcePath</>");
                $changed = $this->copy($sourcePath, (string) getcwd());
                if ($changed) {
                    $this->io->write('- Configured <info>' . $package->getName() . '</>');
                }
            }
        }
    }

    private function insertIntoFile(string $packageName, string $sourcePath, string $targetPath): void {
        if (file_exists($sourcePath)) {
            $sourceToInsert = file_get_contents($sourcePath);
            $existing = file_get_contents($targetPath);
            // look for existing .env section
            $key = sprintf('###> %s ###', $packageName);
            if (!str_contains($existing, $key)) {
                $this->io->write("<warning>inserting $sourcePath to $targetPath</warning>");
                $existing .= "\n\n$key\n" . $sourceToInsert .
                    sprintf('###< %s ###', $packageName) . "\n";
                file_put_contents($targetPath, $existing);
            }
        } else {
            $this->io->warning("Missing $sourcePath");
        }
    }

    private function copy(string $sourcePath, string $targetPath): bool {
        $this->io->write("- Copying $sourcePath to $targetPath <info></>");
        $changed = false;

        /** @var \RecursiveDirectoryIterator $iterator */
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourcePath, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            $target = $targetPath . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
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

    public function copyFile(string $source, string $target): void {
        if (file_exists($target)) {
            return;
        }

        copy($source, $target);
        @chmod($target, fileperms($target) | (fileperms($source) & 0111));
    }


    public function getCapabilities(): array {
        return [CommandProvider::class => self::class];
    }

    public function getCommands(): array {
        return [new ListMetadataCommand()];
    }
}
