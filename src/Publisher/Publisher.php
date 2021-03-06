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

namespace Composer\Satis\Publisher;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Publisher
 * @package Composer\Satis\Publisher
 */
abstract class Publisher
{
    /** @var OutputInterface $output The output Interface. */
    protected $output;
    /** @var InputInterface */
    protected $input;
    /** @var string $outputDir The directory where to build. */
    protected $outputDir;
    /** @var array $config The parameters from ./satis.json. */
    protected $config;
    /** @var bool $skipErrors Skips Exceptions if true. */
    protected $skipErrors;

    public function __construct(
        OutputInterface $output,
        string $outputDir,
        array $config,
        bool $skipErrors,
        InputInterface $input = null
    ) {
        $this->output = $output;
        $this->input = $input;
        $this->outputDir = $outputDir;
        $this->config = $config;
        $this->skipErrors = $skipErrors;
    }
}
