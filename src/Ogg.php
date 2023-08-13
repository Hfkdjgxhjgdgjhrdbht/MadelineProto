<?php

declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2023 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use AssertionError;
use danog\MadelineProto\Stream\BufferedStreamInterface;
use danog\MadelineProto\Stream\BufferInterface;
use danog\MadelineProto\Stream\Common\SimpleBufferedRawStream;
use danog\MadelineProto\Stream\ConnectionContext;
use danog\MadelineProto\Stream\Transport\PremadeStream;
use FFI;
use FFI\CData;
use Webmozart\Assert\Assert;

use function Amp\File\openFile;
use function count;

/**
 * Async OGG stream reader and writer.
 *
 * @author Charles-Édouard Coste <contact@ccoste.fr>
 * @author Daniil Gentili <daniil@daniil.it>
 */
final class Ogg
{
    private const CRC = [
        0x00000000,0x04c11db7,0x09823b6e,0x0d4326d9,
        0x130476dc,0x17c56b6b,0x1a864db2,0x1e475005,
        0x2608edb8,0x22c9f00f,0x2f8ad6d6,0x2b4bcb61,
        0x350c9b64,0x31cd86d3,0x3c8ea00a,0x384fbdbd,
        0x4c11db70,0x48d0c6c7,0x4593e01e,0x4152fda9,
        0x5f15adac,0x5bd4b01b,0x569796c2,0x52568b75,
        0x6a1936c8,0x6ed82b7f,0x639b0da6,0x675a1011,
        0x791d4014,0x7ddc5da3,0x709f7b7a,0x745e66cd,
        0x9823b6e0,0x9ce2ab57,0x91a18d8e,0x95609039,
        0x8b27c03c,0x8fe6dd8b,0x82a5fb52,0x8664e6e5,
        0xbe2b5b58,0xbaea46ef,0xb7a96036,0xb3687d81,
        0xad2f2d84,0xa9ee3033,0xa4ad16ea,0xa06c0b5d,
        0xd4326d90,0xd0f37027,0xddb056fe,0xd9714b49,
        0xc7361b4c,0xc3f706fb,0xceb42022,0xca753d95,
        0xf23a8028,0xf6fb9d9f,0xfbb8bb46,0xff79a6f1,
        0xe13ef6f4,0xe5ffeb43,0xe8bccd9a,0xec7dd02d,
        0x34867077,0x30476dc0,0x3d044b19,0x39c556ae,
        0x278206ab,0x23431b1c,0x2e003dc5,0x2ac12072,
        0x128e9dcf,0x164f8078,0x1b0ca6a1,0x1fcdbb16,
        0x018aeb13,0x054bf6a4,0x0808d07d,0x0cc9cdca,
        0x7897ab07,0x7c56b6b0,0x71159069,0x75d48dde,
        0x6b93dddb,0x6f52c06c,0x6211e6b5,0x66d0fb02,
        0x5e9f46bf,0x5a5e5b08,0x571d7dd1,0x53dc6066,
        0x4d9b3063,0x495a2dd4,0x44190b0d,0x40d816ba,
        0xaca5c697,0xa864db20,0xa527fdf9,0xa1e6e04e,
        0xbfa1b04b,0xbb60adfc,0xb6238b25,0xb2e29692,
        0x8aad2b2f,0x8e6c3698,0x832f1041,0x87ee0df6,
        0x99a95df3,0x9d684044,0x902b669d,0x94ea7b2a,
        0xe0b41de7,0xe4750050,0xe9362689,0xedf73b3e,
        0xf3b06b3b,0xf771768c,0xfa325055,0xfef34de2,
        0xc6bcf05f,0xc27dede8,0xcf3ecb31,0xcbffd686,
        0xd5b88683,0xd1799b34,0xdc3abded,0xd8fba05a,
        0x690ce0ee,0x6dcdfd59,0x608edb80,0x644fc637,
        0x7a089632,0x7ec98b85,0x738aad5c,0x774bb0eb,
        0x4f040d56,0x4bc510e1,0x46863638,0x42472b8f,
        0x5c007b8a,0x58c1663d,0x558240e4,0x51435d53,
        0x251d3b9e,0x21dc2629,0x2c9f00f0,0x285e1d47,
        0x36194d42,0x32d850f5,0x3f9b762c,0x3b5a6b9b,
        0x0315d626,0x07d4cb91,0x0a97ed48,0x0e56f0ff,
        0x1011a0fa,0x14d0bd4d,0x19939b94,0x1d528623,
        0xf12f560e,0xf5ee4bb9,0xf8ad6d60,0xfc6c70d7,
        0xe22b20d2,0xe6ea3d65,0xeba91bbc,0xef68060b,
        0xd727bbb6,0xd3e6a601,0xdea580d8,0xda649d6f,
        0xc423cd6a,0xc0e2d0dd,0xcda1f604,0xc960ebb3,
        0xbd3e8d7e,0xb9ff90c9,0xb4bcb610,0xb07daba7,
        0xae3afba2,0xaafbe615,0xa7b8c0cc,0xa379dd7b,
        0x9b3660c6,0x9ff77d71,0x92b45ba8,0x9675461f,
        0x8832161a,0x8cf30bad,0x81b02d74,0x857130c3,
        0x5d8a9099,0x594b8d2e,0x5408abf7,0x50c9b640,
        0x4e8ee645,0x4a4ffbf2,0x470cdd2b,0x43cdc09c,
        0x7b827d21,0x7f436096,0x7200464f,0x76c15bf8,
        0x68860bfd,0x6c47164a,0x61043093,0x65c52d24,
        0x119b4be9,0x155a565e,0x18197087,0x1cd86d30,
        0x029f3d35,0x065e2082,0x0b1d065b,0x0fdc1bec,
        0x3793a651,0x3352bbe6,0x3e119d3f,0x3ad08088,
        0x2497d08d,0x2056cd3a,0x2d15ebe3,0x29d4f654,
        0xc5a92679,0xc1683bce,0xcc2b1d17,0xc8ea00a0,
        0xd6ad50a5,0xd26c4d12,0xdf2f6bcb,0xdbee767c,
        0xe3a1cbc1,0xe760d676,0xea23f0af,0xeee2ed18,
        0xf0a5bd1d,0xf464a0aa,0xf9278673,0xfde69bc4,
        0x89b8fd09,0x8d79e0be,0x803ac667,0x84fbdbd0,
        0x9abc8bd5,0x9e7d9662,0x933eb0bb,0x97ffad0c,
        0xafb010b1,0xab710d06,0xa6322bdf,0xa2f33668,
        0xbcb4666d,0xb8757bda,0xb5365d03,0xb1f740b4
    ];
    private const OPUS_SET_APPLICATION_REQUEST = 4000;
    private const OPUS_GET_APPLICATION_REQUEST = 4001;
    private const OPUS_SET_BITRATE_REQUEST = 4002;
    private const OPUS_GET_BITRATE_REQUEST = 4003;
    private const OPUS_SET_MAX_BANDWIDTH_REQUEST = 4004;
    private const OPUS_GET_MAX_BANDWIDTH_REQUEST = 4005;
    private const OPUS_SET_VBR_REQUEST = 4006;
    private const OPUS_GET_VBR_REQUEST = 4007;
    private const OPUS_SET_BANDWIDTH_REQUEST = 4008;
    private const OPUS_GET_BANDWIDTH_REQUEST = 4009;
    private const OPUS_SET_COMPLEXITY_REQUEST = 4010;
    private const OPUS_GET_COMPLEXITY_REQUEST = 4011;
    private const OPUS_SET_INBAND_FEC_REQUEST = 4012;
    private const OPUS_GET_INBAND_FEC_REQUEST = 4013;
    private const OPUS_SET_PACKET_LOSS_PERC_REQUEST = 4014;
    private const OPUS_GET_PACKET_LOSS_PERC_REQUEST = 4015;
    private const OPUS_SET_DTX_REQUEST = 4016;
    private const OPUS_GET_DTX_REQUEST = 4017;
    private const OPUS_SET_VBR_CONSTRAINT_REQUEST = 4020;
    private const OPUS_GET_VBR_CONSTRAINT_REQUEST = 4021;
    private const OPUS_SET_FORCE_CHANNELS_REQUEST = 4022;
    private const OPUS_GET_FORCE_CHANNELS_REQUEST = 4023;
    private const OPUS_SET_SIGNAL_REQUEST = 4024;
    private const OPUS_GET_SIGNAL_REQUEST = 4025;
    private const OPUS_GET_LOOKAHEAD_REQUEST = 4027;
    private const OPUS_GET_SAMPLE_RATE_REQUEST = 4029;
    private const OPUS_GET_FINAL_RANGE_REQUEST = 4031;
    private const OPUS_GET_PITCH_REQUEST = 4033;
    private const OPUS_SET_GAIN_REQUEST = 4034;
    private const OPUS_GET_GAIN_REQUEST = 4045;
    private const OPUS_SET_LSB_DEPTH_REQUEST = 4036;
    private const OPUS_GET_LSB_DEPTH_REQUEST = 4037;
    private const OPUS_GET_LAST_PACKET_DURATION_REQUEST = 4039;
    private const OPUS_SET_EXPERT_FRAME_DURATION_REQUEST = 4040;
    private const OPUS_GET_EXPERT_FRAME_DURATION_REQUEST = 4041;
    private const OPUS_SET_PREDICTION_DISABLED_REQUEST = 4042;
    private const OPUS_GET_PREDICTION_DISABLED_REQUEST = 4043;
    private const OPUS_SET_PHASE_INVERSION_DISABLED_REQUEST = 4046;
    private const OPUS_GET_PHASE_INVERSION_DISABLED_REQUEST = 4047;
    private const OPUS_GET_IN_DTX_REQUEST = 4049;

    /* Values for the various encoder CTLs */
    private const OPUS_AUTO = -1000 /**<Auto/default setting @hideinitializer*/;
    private const OPUS_BITRATE_MAX = -1 /**<Maximum bitrate @hideinitializer*/;

    /** Best for most VoIP/videoconference applications where listening quality and intelligibility matter most.
     * @hideinitializer */
    private const OPUS_APPLICATION_VOIP = 2048;
    /** Best for broadcast/high-fidelity application where the decoded audio should be as close as possible to the input.
     * @hideinitializer */
    private const OPUS_APPLICATION_AUDIO = 2049;
    /** Only use when lowest-achievable latency is what matters most. Voice-optimized modes cannot be used.
     * @hideinitializer */
    private const OPUS_APPLICATION_RESTRICTED_LOWDELAY = 2051;

    private const OPUS_SIGNAL_VOICE = 3001 /**< Signal being encoded is voice */;
    private const OPUS_SIGNAL_MUSIC = 3002 /**< Signal being encoded is music */;
    private const OPUS_BANDWIDTH_NARROWBAND = 1101 /**< 4 kHz bandpass @hideinitializer*/;
    private const OPUS_BANDWIDTH_MEDIUMBAND = 1102 /**< 6 kHz bandpass @hideinitializer*/;
    private const OPUS_BANDWIDTH_WIDEBAND = 1103 /**< 8 kHz bandpass @hideinitializer*/;
    private const OPUS_BANDWIDTH_SUPERWIDEBAND = 1104 /**<12 kHz bandpass @hideinitializer*/;
    private const OPUS_BANDWIDTH_FULLBAND = 1105 /**<20 kHz bandpass @hideinitializer*/;

    private const OPUS_FRAMESIZE_ARG = 5000 /**< Select frame size from the argument (default) */;
    private const OPUS_FRAMESIZE_2_5_MS = 5001 /**< Use 2.5 ms frames */;
    private const OPUS_FRAMESIZE_5_MS = 5002 /**< Use 5 ms frames */;
    private const OPUS_FRAMESIZE_10_MS = 5003 /**< Use 10 ms frames */;
    private const OPUS_FRAMESIZE_20_MS = 5004 /**< Use 20 ms frames */;
    private const OPUS_FRAMESIZE_40_MS = 5005 /**< Use 40 ms frames */;
    private const OPUS_FRAMESIZE_60_MS = 5006 /**< Use 60 ms frames */;
    private const OPUS_FRAMESIZE_80_MS = 5007 /**< Use 80 ms frames */;
    private const OPUS_FRAMESIZE_100_MS = 5008 /**< Use 100 ms frames */;
    private const OPUS_FRAMESIZE_120_MS = 5009 /**< Use 120 ms frames */;

    private const CAPTURE_PATTERN = "OggS";
    const CONTINUATION = 1;
    const BOS = 2;
    const EOS = 4;

    const STATE_READ_HEADER = 0;
    const STATE_READ_COMMENT = 1;
    const STATE_STREAMING = 3;
    const STATE_END = 4;

    private int $currentDuration = 0;
    /**
     * Current OPUS payload.
     */
    private string $opusPayload = '';

    /**
     * OGG Stream count.
     */
    private int $streamCount;

    /**
     * Buffered stream interface.
     */
    private BufferInterface $stream;

    /**
     * Pack format.
     */
    private string $packFormat;

    /**
     * Opus packet iterator.
     *
     * @var iterable<string>
     */
    public readonly iterable $opusPackets;

    /**
     * Constructor.
     *
     * @param BufferedStreamInterface $stream The stream
     */
    public function __construct(BufferedStreamInterface $stream)
    {
        $this->stream = $stream->getReadBuffer($l);
        $pack_format = [
            'stream_structure_version' => 'C',
            'header_type_flag'         => 'C',
            'granule_position'         => 'P',
            'bitstream_serial_number'  => 'V',
            'page_sequence_number'     => 'V',
            'CRC_checksum'             => 'V',
            'number_page_segments'     => 'C',
        ];

        $this->packFormat = \implode(
            '/',
            \array_map(
                fn (string $v, string $k): string => $v.$k,
                $pack_format,
                \array_keys($pack_format),
            ),
        );
        $it = $this->read();
        $it->current();
        $this->opusPackets = $it;
    }

    /**
     * Read OPUS length.
     */
    private function readLen(string $content, int &$offset): int
    {
        $len = \ord($content[$offset++]);
        if ($len > 251) {
            $len += \ord($content[$offset++]) << 2;
        }
        return $len;
    }
    /**
     * OPUS state machine.
     *
     * @psalm-suppress InvalidArrayOffset
     */
    private function opusStateMachine(string $content): \Generator
    {
        $curStream = 0;
        $offset = 0;
        $len = \strlen($content);
        while ($offset < $len) {
            $selfDelimited = $curStream++ < $this->streamCount - 1;
            $sizes = [];

            $preOffset = $offset;

            $toc = \ord($content[$offset++]);
            $stereo = $toc & 4;
            $conf = $toc >> 3;
            $c = $toc & 3;

            if ($conf < 12) {
                $frameDuration = $conf % 4;
                if ($frameDuration === 0) {
                    $frameDuration = 10000;
                } else {
                    $frameDuration *= 20000;
                }
            } elseif ($conf < 16) {
                $frameDuration = 2**($conf % 2) * 10000;
            } else {
                $frameDuration = 2**($conf % 4) * 2500;
            }

            $paddingLen = 0;
            if ($c === 0) {
                // Exactly 1 frame
                $sizes []= $selfDelimited
                    ? $this->readLen($content, $offset)
                    : $len - $offset;
            } elseif ($c === 1) {
                // Exactly 2 frames, equal size
                $size = $selfDelimited
                    ? $this->readLen($content, $offset)
                    : ($len - $offset)/2;
                $sizes []= $size;
                $sizes []= $size;
            } elseif ($c === 2) {
                // Exactly 2 frames, different size
                $size = $this->readLen($content, $offset);
                $sizes []= $size;
                $sizes []= $selfDelimited
                    ? $this->readLen($content, $offset)
                    : $len - ($offset + $size);
            } else {
                // Arbitrary number of frames
                $ch = \ord($content[$offset++]);
                $len--;
                $count = $ch & 0x3F;
                $vbr = $ch & 0x80;
                $padding = $ch & 0x40;
                if ($padding) {
                    $paddingLen = $padding = \ord($content[$offset++]);
                    while ($padding === 255) {
                        $padding = \ord($content[$offset++]);
                        $paddingLen += $padding - 1;
                    }
                }
                if ($vbr) {
                    if (!$selfDelimited) {
                        $count -= 1;
                    }
                    for ($x = 0; $x < $count; $x++) {
                        $sizes[]= $this->readLen($content, $offset);
                    }
                    if (!$selfDelimited) {
                        $sizes []= ($len - ($offset + $padding));
                    }
                } else { // CBR
                    $size = $selfDelimited
                        ? $this->readLen($content, $offset)
                        : ($len - ($offset + $padding)) / $count;
                    \array_push($sizes, ...\array_fill(0, $count, $size));
                }
            }

            $totalDuration = \count($sizes) * $frameDuration;
            if (!$selfDelimited && $totalDuration + $this->currentDuration <= 60_000) {
                $this->currentDuration += $totalDuration;
                $sum = \array_sum($sizes);
                /** @psalm-suppress InvalidArgument */
                $this->opusPayload .= \substr($content, $preOffset, (int) (($offset - $preOffset) + $sum + $paddingLen));
                if ($this->currentDuration === 60_000) {
                    yield $this->opusPayload;
                    $this->opusPayload = '';
                    $this->currentDuration = 0;
                }
                $offset += $sum;
                $offset += $paddingLen;
                continue;
            }

            foreach ($sizes as $size) {
                $this->opusPayload .= \chr($toc & ~3);
                $this->opusPayload .= \substr($content, $offset, $size);
                $offset += $size;
                $this->currentDuration += $frameDuration;
                if ($this->currentDuration >= 60_000) {
                    if ($this->currentDuration > 60_000) {
                        throw new AssertionError("Emitting packet with duration {$this->currentDuration} but need {60000}, please reconvert the OGG file with a proper frame size.", Logger::WARNING);
                    }
                    yield $this->opusPayload;
                    $this->opusPayload = '';
                    $this->currentDuration = 0;
                }
            }
            $offset += $paddingLen;
        }
    }

    /**
     * Read frames.
     *
     * @return \Generator<string>
     */
    private function read(): \Generator
    {
        $state = self::STATE_READ_HEADER;
        $content = '';
        $granule = 0;
        $ignoredStreams = [];

        while (true) {
            $capture = $this->stream->bufferRead(4);
            if ($capture !== self::CAPTURE_PATTERN) {
                if ($capture === null) {
                    return;
                }
                throw new Exception('Bad capture pattern: '.\bin2hex($capture));
            }

            $headers = \unpack(
                $this->packFormat,
                $this->stream->bufferRead(23)
            );
            $ignore = \in_array($headers['bitstream_serial_number'], $ignoredStreams, true);

            if ($headers['stream_structure_version'] != 0x00) {
                throw new Exception("Bad stream version");
            }
            $granule_diff = $headers['granule_position'] - $granule;
            $granule = $headers['granule_position'];

            $continuation = (bool) ($headers['header_type_flag'] & 0x01);
            $firstPage = (bool) ($headers['header_type_flag'] & self::BOS);
            $lastPage = (bool) ($headers['header_type_flag'] & self::EOS);

            $segments = \unpack(
                'C*',
                $this->stream->bufferRead($headers['number_page_segments']),
            );

            //$serial = $headers['bitstream_serial_number'];
            /*if ($headers['header_type_flag'] & Ogg::BOS) {
                $this->emit('ogg:stream:start', [$serial]);
            } elseif ($headers['header_type_flag'] & Ogg::EOS) {
                $this->emit('ogg:stream:end', [$serial]);
            } else {
                $this->emit('ogg:stream:continue', [$serial]);
            }*/
            $sizeAccumulated = 0;
            foreach ($segments as $segment_size) {
                $sizeAccumulated += $segment_size;
                if ($segment_size < 255) {
                    $piece = $this->stream->bufferRead($sizeAccumulated);
                    $sizeAccumulated = 0;
                    if ($ignore) {
                        continue;
                    }
                    $content .= $piece;
                    if ($state === self::STATE_STREAMING) {
                        yield from $this->opusStateMachine($content);
                    } elseif ($state === self::STATE_READ_HEADER) {
                        Assert::true($firstPage);
                        $head = \substr($content, 0, 8);
                        if ($head !== 'OpusHead') {
                            $ignoredStreams[]= $headers['bitstream_serial_number'];
                            $content = '';
                            $ignore = true;
                            continue;
                        }
                        $opus_head = \unpack('Cversion/Cchannel_count/vpre_skip/Vsample_rate/voutput_gain/Cchannel_mapping_family/', \substr($content, 8));
                        if ($opus_head['channel_mapping_family']) {
                            $opus_head['channel_mapping'] = \unpack('Cstream_count/Ccoupled_count/C*channel_mapping', \substr($content, 19));
                        } else {
                            $opus_head['channel_mapping'] = [
                                'stream_count' => 1,
                                'coupled_count' => $opus_head['channel_count'] - 1,
                                'channel_mapping' => [0],
                            ];
                            if ($opus_head['channel_count'] === 2) {
                                $opus_head['channel_mapping']['channel_mapping'][] = 1;
                            }
                        }
                        $this->streamCount = $opus_head['channel_mapping']['stream_count'];
                        if ($opus_head['sample_rate'] !== 48000) {
                            throw new AssertionError("The sample rate must be 48khz, got {$opus_head['sample_rate']}");
                        }
                        $state = self::STATE_READ_COMMENT;
                    } elseif ($state === self::STATE_READ_COMMENT) {
                        $vendor_string_length = \unpack('V', \substr($content, 8, 4))[1];
                        $result = [];
                        $result['vendor_string'] = \substr($content, 12, $vendor_string_length);
                        $comment_count = \unpack('V', \substr($content, 12+$vendor_string_length, 4))[1];
                        $offset = 16+$vendor_string_length;
                        for ($x = 0; $x < $comment_count; $x++) {
                            $length = \unpack('V', \substr($content, $offset, 4))[1];
                            $result['comments'][$x] = \substr($content, $offset += 4, $length);
                            $offset += $length;
                        }
                        $state = self::STATE_STREAMING;
                    }
                    $content = '';
                }
            }
        }
    }

    public static function convert(
        LocalFile|RemoteUrl|ReadableStream $wavIn,
        LocalFile|WritableStream $oggOut
    ): void {
        $opus = FFI::cdef('
        typedef struct OpusEncoder OpusEncoder;

        OpusEncoder *opus_encoder_create(
            int32_t Fs,
            int channels,
            int application,
            int *error
        );

        int opus_encoder_ctl(OpusEncoder *st, int request, int arg);

        int32_t opus_encode(
            OpusEncoder *st,
            const char *pcm,
            int frame_size,
            const char *data,
            int32_t max_data_bytes
        );
        void opus_encoder_destroy(OpusEncoder *st);
        const char *opus_strerror(int error);
        const char *opus_get_version_string(void);

        ', 'libopus.so.0');
        $checkErr = function (int|CData $err) use ($opus): void {
            if ($err instanceof CData) {
                $err = $err->cdata;
            }
            if ($err < 0) {
                throw new AssertionError("opus returned: ".$opus->opus_strerror($len));
            }
        };
        $err = FFI::new('int');
        $encoder = $opus->opus_encoder_create(48000, 2, self::OPUS_APPLICATION_AUDIO, FFI::addr($err));
        $checkErr($err);
        $checkErr($opus->opus_encoder_ctl($encoder, self::OPUS_SET_COMPLEXITY_REQUEST, 10));
        $checkErr($opus->opus_encoder_ctl($encoder, self::OPUS_SET_PACKET_LOSS_PERC_REQUEST, 1));
        $checkErr($opus->opus_encoder_ctl($encoder, self::OPUS_SET_INBAND_FEC_REQUEST, 1));
        $checkErr($opus->opus_encoder_ctl($encoder, self::OPUS_SET_SIGNAL_REQUEST, self::OPUS_SIGNAL_MUSIC));
        $checkErr($opus->opus_encoder_ctl($encoder, self::OPUS_SET_BANDWIDTH_REQUEST, self::OPUS_BANDWIDTH_FULLBAND));
        $checkErr($opus->opus_encoder_ctl($encoder, self::OPUS_SET_BITRATE_REQUEST, 130*1000));

        $in = $wavIn instanceof LocalFile
            ? openFile($wavIn->file, 'r')
            : (
                $wavIn instanceof RemoteUrl
                ? HttpClientBuilder::buildDefault()->request(new Request($wavIn->url))->getBody()
                : $wavIn
            );

        $ctx = (new ConnectionContext())->addStream(PremadeStream::class, $in)->addStream(SimpleBufferedRawStream::class);
        /** @var SimpleBufferedRawStream */
        $in = $ctx->getStream();
        Assert::eq($in->bufferRead(length: 4), 'RIFF', "A .wav file must be provided!");
        $totalLength = \unpack('V', $in->bufferRead(length: 4))[1];
        Assert::eq($in->bufferRead(length: 4), 'WAVE', "A .wav file must be provided!");
        do {
            $type = $in->bufferRead(length: 4);
            $length = \unpack('V', $in->bufferRead(length: 4))[1];
            if ($type === 'fmt ') {
                Assert::eq($length, 16);
                $contents = $in->bufferRead(length: $length + ($length % 2));
                $header = \unpack('vaudioFormat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', $contents);
                Assert::eq($header['audioFormat'], 1, "The wav file must contain PCM audio");
                Assert::eq($header['sampleRate'], 48000, "The sample rate of the wav file must be 48khz!");
            } elseif ($type === 'data') {
                break;
            } else {
                $in->bufferRead($length);
            }
        } while (true);

        $sampleCount = 0.06 * $header['sampleRate'];
        $chunkSize = (int) ($sampleCount * $header['channels'] * ($header['bitsPerSample'] >> 3));
        $shift = (int) \log($header['channels'] * ($header['bitsPerSample'] >> 3), 2);

        $out = $oggOut instanceof LocalFile
            ? openFile($oggOut->file, 'w')
            : $oggOut;

        $writePage = function (int $header_type_flag, int $granule, int $streamId, int &$streamSeqno, string $packet) use ($out): void {
            Assert::true(\strlen($packet) < 65025);
            $segments = [
                ...\array_fill(0, (int) (\strlen($packet) / 255), 255),
                \strlen($packet) % 255
            ];
            $data = 'OggS'.\pack(
                'CCPVVVCC*',
                0, // stream_structure_version
                $header_type_flag,
                $granule,
                $streamId,
                $streamSeqno++,
                0,
                \count($segments),
                ...$segments
            ).$packet;

            $c = 0;
            for ($i = 0; $i < \strlen($data); $i++) {
                $c = ($c<<8)^self::CRC[(($c >> 24)&0xFF)^(\ord($data[$i]))];
            }
            $crc = \pack('V', $c);

            $data = \substr_replace(
                $data,
                $crc,
                22,
                4
            );
            $out->write($data);
        };

        $streamId = \unpack('V', Tools::random(4))[1];
        $seqno = 0;

        $writePage(
            Ogg::BOS,
            0,
            $streamId,
            $seqno,
            'OpusHead'.\pack(
                'CCvVvC',
                1,
                $header['channels'],
                312,
                $header['sampleRate'],
                0,
                0,
            )
        );

        $tags = 'OpusTags';
        $writeTag = function (string $tag) use (&$tags): void {
            $tags .= \pack('V', \strlen($tag)).$tag;
        };
        $writeTag("MadelineProto ".API::RELEASE.", ".$opus->opus_get_version_string());
        $tags .= \pack('V', 2);
        $writeTag("ENCODER=MadelineProto ".API::RELEASE." with ".$opus->opus_get_version_string());
        $writeTag('See https://docs.madelineproto.xyz/docs/CALLS.html for more info');
        $writePage(
            0,
            0,
            $streamId,
            $seqno,
            $tags
        );

        $granule = 0;
        $buf = FFI::cast(FFI::type('char*'), FFI::addr($opus->new('char[1024]')));
        do {
            $chunkOrig = $in->bufferRead(length: $chunkSize);
            $chunk = \str_pad($chunkOrig, $chunkSize, "\0");
            $granuleDiff = \strlen($chunk) >> $shift;
            $len = $opus->opus_encode($encoder, $chunk, $granuleDiff, $buf, 1024);
            $checkErr($len);
            $writePage(
                \strlen($chunk) !== \strlen($chunkOrig) ? self::EOS : 0,
                $granule += $granuleDiff,
                $streamId,
                $seqno,
                FFI::string($buf, $len)
            );
        } while (\strlen($chunk) === \strlen($chunkOrig));
        $opus->opus_encoder_destroy($encoder);
        unset($buf, $encoder, $opus);

        $out->close();
    }
}
