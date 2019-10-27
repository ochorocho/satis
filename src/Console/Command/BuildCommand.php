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
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Json\JsonValidationException;
use Composer\Satis\Builder\ArchiveBuilder;
use Composer\Satis\Builder\PackagesBuilder;
use Composer\Satis\Builder\WebBuilder;
use Composer\Satis\Console\Application;
use Composer\Satis\PackageSelection\PackageSelection;
use Composer\Satis\Publisher\GitlabPublisher;
use Composer\Util\RemoteFilesystem;
use JsonSchema\Validator;
use Seld\JsonLint\JsonParser;
use Seld\JsonLint\ParsingException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BuildCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('build')
            ->setDescription('Builds a composer repository out of a json file')
            ->setDefinition([
                new InputArgument('file', InputArgument::OPTIONAL, 'Json file to use', './satis.json'),
                new InputArgument('output-dir', InputArgument::OPTIONAL, 'Location where to output built files', null),
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Packages that should be built. If not provided, all packages are built.', null),
                new InputOption('versions-only', null, InputOption::VALUE_OPTIONAL, 'Versions to build. Takes branch or tag name as a comma separated list', null),
                new InputOption('repository-url', null, InputOption::VALUE_OPTIONAL, 'Only update the repository at given url', null),
                new InputOption('repository-strict', null, InputOption::VALUE_NONE, 'Also apply the repository filter when resolving dependencies'),
                new InputOption('skip-errors', null, InputOption::VALUE_NONE, 'Skip Download or Archive errors'),
                new InputOption('stats', null, InputOption::VALUE_NONE, 'Display the download progress bar'),
            ])
            ->setHelp(<<<'EOT'
The <info>build</info> command reads the given json file
(satis.json is used by default) and outputs a composer
repository in the given output-dir.

The json config file accepts the following keys:

- <info>"repositories"</info>: defines which repositories are searched
  for packages.
- <info>"repositories-dep"</info>: define additional repositories for dependencies
- <info>"output-dir"</info>: where to output the repository files
  if not provided as an argument when calling build.
- <info>"require-all"</info>: boolean, if true, all packages present
  in the configured repositories will be present in the
  dumped satis repository.
- <info>"require"</info>: if you do not want to dump all packages,
  you can explicitly require them by name and version.
- <info>"minimum-stability"</info>: sets default stability for packages
  (default: dev), see
  http://getcomposer.org/doc/04-schema.md#minimum-stability
- <info>"require-dependencies"</info>: if you mark a few packages as
  required to mirror packagist for example, setting this
  to true will make satis automatically require all of your
  requirements' dependencies.
- <info>"require-dev-dependencies"</info>: works like require-dependencies
  but requires dev requirements rather than regular ones.
- <info>"only-dependencies"</info>: only require dependencies - choose this if you want to build
  a mirror of your project's dependencies without building packages for the main project repositories.
- <info>"config"</info>: all config options from composer, see
  http://getcomposer.org/doc/04-schema.md#config
- <info>"strip-hosts"</info>: boolean or an array of domains, IPs, CIDR notations, '/local' (=localnet and other reserved)
  or '/private' (=private IPs) to be stripped from the output. If set and non-false, local file paths are removed too.
- <info>"output-html"</info>: boolean, controls whether the repository
  has an html page as well or not.
- <info>"name"</info>: for html output, this defines the name of the
  repository.
- <info>"homepage"</info>: for html output, this defines the home URL
  of the repository (where you will host it).
- <info>"twig-template"</info>: Location of twig template to use for
  building the html output.
- <info>"abandoned"</info>: Packages that are abandoned. As the key use the
  package name, as the value use true or the replacement package.
- <info>"notify-batch"</info>: Allows you to specify a URL that will
  be called every time a user installs a package, see
  https://getcomposer.org/doc/05-repositories.md#notify-batch
- <info>"include-filename"</info> Specify filename instead of default include/all${SHA1_HASH}.json
- <info>"archive"</info> archive configuration, see https://getcomposer.org/doc/articles/handling-private-packages-with-satis.md#downloads

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
        $configFile = $input->getArgument('file');
        $packagesFilter = $input->getArgument('packages');
        $repositoryUrl = $input->getOption('repository-url');
        $skipErrors = (bool) $input->getOption('skip-errors');

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

        try {
            $schemaFile = __DIR__ . '/../../../../composer/res/composer-schema.json';
            $schema = json_decode(file_get_contents($schemaFile));
            $validator = new Validator();
            $validator->check($configFile, $schema);
        } catch (JsonValidationException $e) {
            foreach ($e->getErrors() as $error) {
                $output->writeln(sprintf('<error>%s</error>', $error));
            }
            if (!$skipErrors) {
                throw $e;
            }
            $output->writeln(sprintf('<warning>%s: %s</warning>', get_class($e), $e->getMessage()));
        } catch (ParsingException $e) {
            if (!$skipErrors) {
                throw $e;
            }
            $output->writeln(sprintf('<warning>%s: %s</warning>', get_class($e), $e->getMessage()));
        } catch (\UnexpectedValueException $e) {
            if (!$skipErrors) {
                throw $e;
            }
            $output->writeln(sprintf('<warning>%s: %s</warning>', get_class($e), $e->getMessage()));
        }

        if (null !== $repositoryUrl && count($packagesFilter) > 0) {
            throw new \InvalidArgumentException('The arguments "package" and "repository-url" can not be used together.');
        }

        // disable packagist by default
        unset(Config::$defaultRepositories['packagist'], Config::$defaultRepositories['packagist.org']);

        if (!$outputDir = $input->getArgument('output-dir')) {
            $outputDir = $config['output-dir'] ?? null;
        }

        if (null === $outputDir) {
            throw new \InvalidArgumentException('The output dir must be specified as second argument or be configured inside ' . $input->getArgument('file'));
        }

        $filesCleanup = GitlabPublisher::findFilesToUpload($outputDir);
        $this->deleteFiles($filesCleanup);

        /** @var $application Application */
        $application = $this->getApplication();
        $composer = $application->getComposer(true, $config);
        $packageSelection = new PackageSelection($output, $outputDir, $config, $skipErrors, $input);

        if (null !== $repositoryUrl) {
            $packageSelection->setRepositoryFilter($repositoryUrl, (bool) $input->getOption('repository-strict'));
        } else {
            $packageSelection->setPackagesFilter($packagesFilter);
        }

        $packages = $packageSelection->select($composer, $verbose);

        if (isset($config['archive']['directory'])) {
            $downloads = new ArchiveBuilder($output, $outputDir, $config, $skipErrors);
            $downloads->setComposer($composer);
            $downloads->setInput($input);
            $downloads->dump($packages);
        }

        $packages = $packageSelection->clean();

        if ($packageSelection->hasFilterForPackages() || $packageSelection->hasRepositoryFilter()) {
            // in case of an active filter we need to load the dumped packages.json and merge the
            // updated packages in
            $oldPackages = $packageSelection->load();
            $packages += $oldPackages;
            ksort($packages);
        }

        $packagesBuilder = new PackagesBuilder($output, $outputDir, $config, $skipErrors, $input);
        $packagesBuilder->dump($packages);

        return 0;
    }

    /**
     * Cleanup build files
     *
     * @param array $files
     */
    public static function deleteFiles($files)
    {
        foreach ($files as $file) {
            unlink($file);
        }
    }
}
