<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding\Hwaccel\ToneMapping;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\Hwaccel\ToneMapping\ToneMapFilterChain;

class ToneMapFilterChainTest extends TestCase
{
    public function test_is_empty_false(): void
    {
        $chain = new ToneMapFilterChain(
            input_filtergraph: 'hwupload=extra_hw_frames=3',
            output_filtergraph: 'tonemap_cuda=transfer=smpte2084',
            metadata_filter: 'zscale=transfer=bt709'
        );

        $this->assertFalse($chain->isEmpty());
    }

    public function test_is_empty_true(): void
    {
        $chain = new ToneMapFilterChain(
            input_filtergraph: '',
            output_filtergraph: '',
            metadata_filter: ''
        );

        $this->assertTrue($chain->isEmpty());
    }

    public function test_get_filter_graph_single_filter(): void
    {
        $chain = new ToneMapFilterChain(
            input_filtergraph: 'hwupload',
            output_filtergraph: '',
            metadata_filter: 'zscale=transfer=bt709'
        );

        $graph = $chain->getFilterGraph();

        $this->assertSame('hwupload,zscale=transfer=bt709', $graph);
    }

    public function test_get_filter_graph_all_filters(): void
    {
        $chain = new ToneMapFilterChain(
            input_filtergraph: 'hwupload',
            output_filtergraph: 'scale_cuda=format=nv12',
            metadata_filter: 'zscale=transfer=bt709'
        );

        $graph = $chain->getFilterGraph();

        $this->assertSame('hwupload,zscale=transfer=bt709,scale_cuda=format=nv12', $graph);
    }

    public function test_get_filter_graph_empty(): void
    {
        $chain = new ToneMapFilterChain(
            input_filtergraph: '',
            output_filtergraph: '',
            metadata_filter: ''
        );

        $this->assertSame('', $chain->getFilterGraph());
    }

    public function test_get_vf_argument(): void
    {
        $chain = new ToneMapFilterChain(
            input_filtergraph: 'hwupload',
            output_filtergraph: 'scale_cuda=format=nv12',
            metadata_filter: 'zscale=transfer=bt709'
        );

        $vfArg = $chain->getVfArgument();

        $this->assertSame(' -vf "hwupload,zscale=transfer=bt709,scale_cuda=format=nv12"', $vfArg);
    }

    public function test_get_vf_argument_empty(): void
    {
        $chain = new ToneMapFilterChain(
            input_filtergraph: '',
            output_filtergraph: '',
            metadata_filter: ''
        );

        $this->assertSame('', $chain->getVfArgument());
    }

    public function test_ffmpeg_args_accessible(): void
    {
        $chain = new ToneMapFilterChain(
            input_filtergraph: 'hwupload',
            output_filtergraph: 'scale_cuda',
            metadata_filter: 'zscale',
            ffmpeg_args: ['-extra_hw_frames', '3']
        );

        $this->assertSame(['-extra_hw_frames', '3'], $chain->ffmpeg_args);
    }
}
