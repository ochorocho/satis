<?php

declare(strict_types=1);

/*
 * This file is part of composer/satis.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Satis\Console\Command;

use Composer\Command\BaseCommand;
use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\Json\JsonFile;
use Composer\Json\JsonValidationException;
use Composer\Satis\Publisher\GitlabPublisher;
use Composer\Util\RemoteFilesystem;
use Seld\JsonLint\ParsingException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PublishGitlabCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('publish-gitlab')
            ->setDescription('Uploads a given version to Gitlab')
            ->setDefinition([
                new InputArgument('file', InputArgument::OPTIONAL, 'Json file to use', './satis.json'),
                new InputOption('folder', InputArgument::OPTIONAL, 'Folder to search for files', null),
                new InputOption('package-version', null, InputOption::VALUE_OPTIONAL, 'Version of package to upload, tag/branch', null),
                new InputOption('skip-errors', null, InputOption::VALUE_NONE, 'Skip Download or Archive errors'),
            ])
            ->setHelp(<<<'EOT'
The <info>publish-gitlab</info> will search in 'files' for a given 'version' to upload.

The config accepts the following keys:

- <info>"folder"</info>: where to to search for files.
- <info>"version"</info>: version to upload, upload all found if empty.
EOT
            );
    }

    /**
     * @throws JsonValidationException
     * @throws ParsingException
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbose = $input->getOption('verbose');
        $skipErrors = (bool) $input->getOption('skip-errors');
        $configFile = $input->getArgument('file');
        $io = $this->getIO();
        $io->loadConfiguration($this->getConfiguration());

        if (preg_match('{^https?://}i', $configFile)) {
            $rfs = new RemoteFilesystem($io);
            $contents = $rfs->getContents(parse_url($configFile, PHP_URL_HOST), $configFile, false);
            $config = JsonFile::parseJson($contents, $configFile);
        } else {
            $file = new JsonFile($configFile);
            if (!$file->exists()) {
                $output->writeln('<error>File not found: ' . $configFile . '</error>');

                return 1;
            }
            $config = $file->read();
        }


        var_dump($input->getArgument('folder'));

        if (!$outputDir = $input->getArgument('folder')) {
            $outputDir = $config['output-dir'] ?? null;
        }

        var_dump($outputDir);

        $publisher = new GitlabPublisher($output, $outputDir, $skipErrors, $input);

        // load auth.json authentication information and pass it to the io interface

        return 0;
    }

    private function getConfiguration(): Config
    {
        $config = new Config();

        // add dir to the config
        $config->merge(['config' => ['home' => $this->getComposerHome()]]);

        // load global auth file
        $file = new JsonFile($config->get('home') . '/auth.json');
        if ($file->exists()) {
            $config->merge(['config' => $file->read()]);
        }
        $config->setAuthConfigSource(new JsonConfigSource($file, true));

        return $config;
    }

    private function getComposerHome(): string
    {
        $home = getenv('COMPOSER_HOME');
        if (!$home) {
            if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
                if (!getenv('APPDATA')) {
                    throw new \RuntimeException('The APPDATA or COMPOSER_HOME environment variable must be set for composer to run correctly');
                }
                $home = strtr(getenv('APPDATA'), '\\', '/') . '/Composer';
            } else {
                if (!getenv('HOME')) {
                    throw new \RuntimeException('The HOME or COMPOSER_HOME environment variable must be set for composer to run correctly');
                }
                $home = rtrim(getenv('HOME'), '/') . '/.composer';
            }
        }

        return $home;
    }
}
