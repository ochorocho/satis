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
use Composer\Composer;
use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\Factory;
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
                new InputArgument('project-url', InputArgument::REQUIRED, 'Gitlab project url'),
                new InputArgument('project-id', InputArgument::REQUIRED, 'Gitlab project id'),
                new InputArgument('private-token', InputArgument::OPTIONAL, 'Gitlab private token'),
                new InputArgument('file', InputArgument::OPTIONAL, 'Json file to use', './satis.json'),
                new InputOption('folder', null, InputOption::VALUE_REQUIRED, 'Folder to search for files'),
                new InputOption('skip-errors', null, InputOption::VALUE_NONE, 'Skip Download or Archive errors'),
            ])
            ->setHelp(<<<'EOT'
The <info>publish-gitlab</info> will search in 'files' for a given 'version' to upload.

The config accepts the following options:

- <info>"project-url"</info>: Gitlab project url.
- <info>"project-id"</info>: Gitlab project id.
- <info>"private-token"</info>: Gitlab private auth token.
- <info>"--folder"</info>: where to to search for files.
- <info>"--skip-errors"</info>: Skip errors.
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
        $skipErrors = (bool) $input->getOption('skip-errors');
        $configFile = $input->getArgument('file');

        $factory = new Factory();
        $createConfig = $factory->createConfig();
        $composerConfiguration = $this->getIO()->loadConfiguration($createConfig);

        if (preg_match('{^https?://}i', $configFile)) {
            $rfs = new RemoteFilesystem($composerConfiguration);
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

        if (!$outputDir = $input->getOption('folder')) {
            $outputDir = $config['output-dir'] ?? null;
        }

        $publisher = new GitlabPublisher($output, $outputDir, $config, $skipErrors, $input);

        return 0;
    }
}
