<?php

declare(strict_types=1);

namespace Lala\Webcam\Tests\Unit\Service;

use Lala\Webcam\Service\InvalidJpegException;
use Lala\Webcam\Service\JpegMetadataStripper;
use PHPUnit\Framework\TestCase;

class JpegMetadataStripperTest extends TestCase
{
    private const SOI = "\xFF\xD8";
    private const SCAN = "\xFF\xDA\x00\x04\x01\x02image-data\xFF\xD9";

    private static function segment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    public function testRemovesMetadataSegments(): void
    {
        $jfif = self::segment(0xE0, "JFIF\0\x01\x01");
        $exif = self::segment(0xE1, "Exif\0\0GPS");
        $xmp = self::segment(0xE1, "http://ns.adobe.com/xap/1.0/\0<x/>");
        $iptc = self::segment(0xED, "Photoshop 3.0\0");
        $comment = self::segment(0xFE, 'camera serial 1234');
        $quantizationTable = self::segment(0xDB, str_repeat("\x01", 65));

        $jpeg = self::SOI . $jfif . $exif . $xmp . $iptc . $comment . $quantizationTable . self::SCAN;

        self::assertSame(
            self::SOI . $jfif . $quantizationTable . self::SCAN,
            (new JpegMetadataStripper())->strip($jpeg)
        );
    }

    public function testKeepsColorProfilesAndAdobeMarker(): void
    {
        $iccProfile = self::segment(0xE2, "ICC_PROFILE\0\x01\x01profile");
        $multiPicture = self::segment(0xE2, "MPF\0data");
        $adobe = self::segment(0xEE, "Adobe\0\x64\0\0\0\0\x01");

        $jpeg = self::SOI . $iccProfile . $multiPicture . $adobe . self::SCAN;

        self::assertSame(
            self::SOI . $iccProfile . $adobe . self::SCAN,
            (new JpegMetadataStripper())->strip($jpeg)
        );
    }

    public function testDoesNotTouchImageData(): void
    {
        // Stuffed 0xFF00 and restart markers belong to the entropy coded data
        $scan = "\xFF\xDA\x00\x04\x01\x02\xFF\x00\xE1\x00ab\xFF\xD0cd\xFF\xD9";

        self::assertSame(self::SOI . $scan, (new JpegMetadataStripper())->strip(self::SOI . $scan));
    }

    public function testDropsEverythingAfterEndOfImage(): void
    {
        $appendedExif = self::segment(0xE1, "Exif\0\0GPS");

        self::assertSame(
            self::SOI . self::SCAN,
            (new JpegMetadataStripper())->strip(self::SOI . self::SCAN . $appendedExif . '<?php echo 1; ?>')
        );
    }

    public function testRemovesMetadataBetweenProgressiveScans(): void
    {
        $firstScan = "\xFF\xDA\x00\x04\x01\x02first";
        $huffmanTable = self::segment(0xC4, 'table');
        $exif = self::segment(0xE1, "Exif\0\0GPS");

        self::assertSame(
            self::SOI . $firstScan . $huffmanTable . self::SCAN,
            (new JpegMetadataStripper())->strip(self::SOI . $firstScan . $huffmanTable . $exif . self::SCAN)
        );
    }

    public function testSkipsFillBytesBeforeMarkers(): void
    {
        $quantizationTable = self::segment(0xDB, 'table');

        self::assertSame(
            self::SOI . $quantizationTable . self::SCAN,
            (new JpegMetadataStripper())->strip(self::SOI . str_repeat("\xFF", 1000) . $quantizationTable . self::SCAN)
        );
    }

    public function testRejectsJpegWithoutImageData(): void
    {
        $this->expectException(InvalidJpegException::class);
        (new JpegMetadataStripper())->strip(self::SOI . self::segment(0xDB, 'table') . "\xFF\xD9");
    }

    public function testRejectsFillBytesOnly(): void
    {
        $this->expectException(InvalidJpegException::class);
        (new JpegMetadataStripper())->strip(self::SOI . str_repeat("\xFF", 100000));
    }

    public function testRejectsNonJpegContent(): void
    {
        $this->expectException(InvalidJpegException::class);
        (new JpegMetadataStripper())->strip("\x89PNG\r\n\x1a\n");
    }

    public function testRejectsTruncatedJpeg(): void
    {
        $this->expectException(InvalidJpegException::class);
        (new JpegMetadataStripper())->strip(self::SOI . "\xFF\xE1\x00\x40Exif");
    }
}
