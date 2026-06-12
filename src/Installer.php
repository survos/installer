<?php

declare(strict_types=1);

namespace Survos\Installer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Installer\PackageEvent;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Finder\Finder;

final class Installer implements PluginInterface, EventSubscriberInterface, Capable, CommandProvider {
    private Composer $composer;
    private IOInterface $io;

    #[\Override]
    public function activate(Composer $composer, IOInterface $io): void {
        $this->composer = $composer;
        $this->io = $io;
        if ($io->isVeryVerbose()) {
            $io->write('<warning>Activating installation...</warning>');
        }
    }

    #[\Override]
    public function deactivate(Composer $composer, IOInterface $io): void {
    }

    #[\Override]
    public function uninstall(Composer $composer, IOInterface $io): void {
    }

    #[\Override]
    public static function getSubscribedEvents(): array {
        return [
            'post-package-install' => 'onPackageInstall',
            'post-package-update' => 'onPackageUpdate',
        ];
    }

    public function onPackageInstall(PackageEvent $event): void {
        if ($this->io->isVeryVerbose()) {
            $this->io->write('<warning>Survos Installer about to install! ' . $event->getName() . '</>');
        }
        $this->processInstall($event);
    }

    public function onPackageUpdate(PackageEvent $event): void {
        $this->processInstall($event);
    }

    private function processInstall(PackageEvent $event): void {
        $operation = $event->getOperation();
        if (method_exists($operation, 'getPackage')) {
            $package = $operation->getPackage();
        } else {
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
            $this->applyEnvVars($env, getcwd() . '/.env', $packageName);
        }

        // .gitignore
        if (file_exists($gitignore)) {
            $this->writeScopedBlock($gitignore, getcwd() . '/.gitignore', $packageName);
        }

        // post-install.txt
        if (file_exists($postInstall)) {
            $this->io->write(file_get_contents($postInstall));
        }

        // check if package has a manifest file and extract its content
        $manifestPath = $installPath . '/.installer/manifest.yaml';
        if (file_exists($manifestPath)) {
            // Parse YAML file
            $yamlContent = Yaml::parseFile($manifestPath);
            // Work with the parsed data
            //print_r($yamlContent);
        }

        //search for yaml files in the package in the folder .installer/symfony in all subfolders and copy them to the project keeping their same path
        $yamlFiles = [];
        //make sure $installPath . '/.installer/symfony' exists
        if (file_exists($installPath . '/.installer/symfony')) {
            $finder = new Finder();
            $finder->files()
                ->in($installPath . '/.installer/symfony')
                ->name('*.yaml')
                ->name('*.yml');


            foreach ($finder as $file) {
                $yamlFiles[] = $file->getRealPath();
            }
        }

        foreach ($yamlFiles as $yamlFile) {
            $targetPath = str_replace($installPath . '/.installer/', '', $yamlFile);
            //remove symfony/ from the path
            $targetPath = str_replace('symfony/', '', $targetPath);
            //file in target path must not exist
            if (file_exists($targetPath)) {
                $this->io->write("<error>File {$targetPath} already exists. Skipping copy.</error>");
                continue;
            }
            //if target path does not exist, create the directory
            if (!file_exists(dirname($targetPath))) {
                mkdir(dirname($targetPath), 0777, true);
            }
            copy($yamlFile, $targetPath);
            $this->io->write("<info> Copying {$yamlFile} to {$targetPath}</info>");
        }
    }

    private function applyEnvVars(string $sourceFile, string $targetFile, string $packageName): void {
        $newVars = file($sourceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!file_exists($targetFile)) {
            file_put_contents($targetFile, '');
        }

        $existing = file_get_contents($targetFile);
        $newContent = "";
        foreach ($newVars as $line) {
            if (!str_contains($existing, $line)) {
                $newContent .= "$line\n";
            }
        }
        if (!empty($newContent)) {
            //make it scoped
            $this->writeScopedBlock($sourceFile, $targetFile, $packageName);
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

        // Append new block
        $block = $blockStart . "\n" . implode("\n", $newLines) . "\n" . $blockEnd . "\n";
        file_put_contents($targetFile, rtrim($content) . "\n\n" . $block);
    }

    public function getCapabilities(): array {
        return [CommandProvider::class => self::class];
    }

    public function getCommands(): array {
        return [new ListMetadataCommand()];
    }
}
