<?php

declare(strict_types=1);

namespace Phlix\Console\Commands;

use Phlix\Media\Transcoding\Hwaccel\HwaccelProbe;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `hwaccel:probe` — probe for available hardware-acceleration encoders.
 *
 * Thin console wrapper around {@see HwaccelProbe::probe()}, which returns a
 * map of vendor name to {@see \Phlix\Media\Transcoding\Hwaccel\HwaccelCapability}.
 * The command renders each detected vendor's encoder, decoder, and supported
 * codecs as a table. The backing {@see HwaccelProbe} is resolved lazily
 * through the injected factory so constructing this command never builds the
 * DI container.
 */
#[AsCommand(name: 'hwaccel:probe', description: 'Probe for available hardware-acceleration encoders')]
final class HwaccelProbeCommand extends Command
{
    /** @var callable(): HwaccelProbe Lazy factory for the backing probe. */
    private $hwaccelProbeFactory;

    /**
     * @param callable(): HwaccelProbe $hwaccelProbeFactory Lazy factory
     *        returning the backing {@see HwaccelProbe}. Invoked only inside
     *        {@see execute()}, never at registration time.
     */
    public function __construct(callable $hwaccelProbeFactory)
    {
        $this->hwaccelProbeFactory = $hwaccelProbeFactory;
        parent::__construct();
    }

    /**
     * Run the probe suite and render the detected capabilities.
     *
     * @return int {@see Command::SUCCESS} (0) once the results are rendered, or
     *         {@see Command::FAILURE} (1) when the probe throws.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $probe = ($this->hwaccelProbeFactory)();
            $capabilities = $probe->probe();
        } catch (Throwable $e) {
            $output->writeln('<error>Hardware-acceleration probe failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($capabilities === []) {
            $output->writeln('No hardware-acceleration vendors detected.');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Vendor', 'Encoder', 'Decoder', 'HDR Tone-mapping', 'Codecs']);

        foreach ($capabilities as $vendor => $capability) {
            $table->addRow([
                $vendor,
                $capability->encoder,
                $capability->decoder,
                $capability->supports_hdr_tone_mapping ? 'yes' : 'no',
                implode(', ', $capability->supported_codecs),
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
