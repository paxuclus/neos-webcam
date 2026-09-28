<?php

declare(strict_types=1);

namespace Lala\Webcam\Service;

/**
 * Removes EXIF, XMP, IPTC and comment segments from a JPEG without re-encoding it.
 *
 * Only the segment structure is rewritten, so this needs neither GD, Imagick nor vips.
 * JFIF (APP0), ICC color profiles (APP2) and the Adobe color transform marker (APP14)
 * are kept because decoders need them to render the image correctly. Everything after
 * the end of image marker is dropped, since cameras append e.g. thumbnails with their
 * own EXIF data there.
 */
final class JpegMetadataStripper
{
    private const MARKER_SOI = 0xD8;
    private const MARKER_EOI = 0xD9;
    private const MARKER_SOS = 0xDA;
    private const MARKER_RST0 = 0xD0;
    private const MARKER_RST7 = 0xD7;
    private const MARKER_COM = 0xFE;
    private const MARKER_APP0 = 0xE0;
    private const MARKER_APP2 = 0xE2;
    private const MARKER_APP14 = 0xEE;
    private const MARKER_APP15 = 0xEF;

    public function strip(string $jpeg): string
    {
        $length = strlen($jpeg);
        if ($length < 4 || ord($jpeg[0]) !== 0xFF || ord($jpeg[1]) !== self::MARKER_SOI) {
            throw new InvalidJpegException('Not a JPEG file', 1759050001);
        }

        $result = substr($jpeg, 0, 2);
        $position = 2;
        $hasImageData = false;

        while ($position + 1 < $length) {
            if (ord($jpeg[$position]) !== 0xFF) {
                throw new InvalidJpegException('Expected a segment marker', 1759050002);
            }

            // Markers may be padded with any number of 0xFF fill bytes
            $position += strspn($jpeg, "\xFF", $position) - 1;
            if ($position + 1 >= $length) {
                break;
            }
            $marker = ord($jpeg[$position + 1]);

            if ($marker === self::MARKER_EOI) {
                if (!$hasImageData) {
                    throw new InvalidJpegException('JPEG ends before any image data', 1759050003);
                }
                return $result . "\xFF\xD9";
            }

            if ($position + 4 > $length) {
                break;
            }
            $segmentLength = unpack('n', $jpeg, $position + 2)[1];
            if ($segmentLength < 2 || $position + 2 + $segmentLength > $length) {
                break;
            }

            $segment = substr($jpeg, $position, 2 + $segmentLength);
            if (!$this->isMetadataSegment($marker, $segment)) {
                $result .= $segment;
            }
            $position += 2 + $segmentLength;

            if ($marker === self::MARKER_SOS) {
                $scanEnd = $this->findEndOfScan($jpeg, $position);
                $result .= substr($jpeg, $position, $scanEnd - $position);
                $position = $scanEnd;
                $hasImageData = true;
            }
        }

        throw new InvalidJpegException('JPEG is truncated', 1759050004);
    }

    /**
     * Entropy coded data only contains 0xFF followed by a stuffed 0x00 or a restart marker,
     * so the first other marker ends the scan
     */
    private function findEndOfScan(string $jpeg, int $position): int
    {
        while (($position = strpos($jpeg, "\xFF", $position)) !== false && $position + 1 < strlen($jpeg)) {
            $next = ord($jpeg[$position + 1]);
            if ($next === 0x00 || ($next >= self::MARKER_RST0 && $next <= self::MARKER_RST7)) {
                $position += 2;
                continue;
            }
            return $position;
        }

        throw new InvalidJpegException('JPEG is truncated', 1759050004);
    }

    private function isMetadataSegment(int $marker, string $segment): bool
    {
        if ($marker === self::MARKER_COM) {
            return true;
        }
        if ($marker < self::MARKER_APP0 || $marker > self::MARKER_APP15) {
            return false;
        }

        return match ($marker) {
            self::MARKER_APP0, self::MARKER_APP14 => false,
            // APP2 is shared by ICC profiles and e.g. the multi picture format
            self::MARKER_APP2 => substr($segment, 4, 12) !== "ICC_PROFILE\0",
            default => true,
        };
    }
}
