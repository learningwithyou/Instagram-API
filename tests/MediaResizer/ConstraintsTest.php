<?php

namespace InstagramAPI\Tests\MediaResizer;

use InstagramAPI\Constants;
use InstagramAPI\Media\Dimensions;
use InstagramAPI\Media\Photo\PhotoResizer;
use InstagramAPI\Media\Rectangle;
use InstagramAPI\Media\ResizerInterface;
use InstagramAPI\MediaAutoResizer;
use PHPUnit\Framework\TestCase;
use PHPUnit_Framework_MockObject_MockObject;

class ConstraintsTest extends TestCase
{
    const MIN_WIDTH = PhotoResizer::MIN_WIDTH;

    const MAX_WIDTH = PhotoResizer::MAX_WIDTH;

    /** @var int[] */
    protected $_values;

    /**
     * @param callable $width
     * @param callable $height
     * @param callable $result
     *
     * @return PHPUnit_Framework_MockObject_MockObject
     */
    protected function _getResizerMock(
        callable $width,
        callable $height,
        callable $result)
    {
        $dimensionsMock = $this->getMockBuilder(Dimensions::class)
            ->disableOriginalConstructor()
            ->setMethods(['getWidth', 'getHeight'])
            ->getMock();

        $dimensionsMock->expects($this->any())
            ->method('getWidth')
            ->willReturnCallback($width);

        $dimensionsMock->expects($this->any())
            ->method('getHeight')
            ->willReturnCallback($height);

        $resizerMock = $this->getMockBuilder(ResizerInterface::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getInputDimensions', 'isProcessingRequired', 'isHorFlipped', 'isVerFlipped',
                'resize', 'getMinWidth', 'getMaxWidth',
            ])
            ->getMock();

        $resizerMock->expects($this->any())
            ->method('getInputDimensions')
            ->willReturn($dimensionsMock);

        $resizerMock->expects($this->any())
            ->method('isProcessingRequired')
            ->willReturn(true);

        $resizerMock->expects($this->any())
            ->method('isHorFlipped')
            ->willReturn(false);

        $resizerMock->expects($this->any())
            ->method('isVerFlipped')
            ->willReturn(false);

        $resizerMock->expects($this->any())
            ->method('getMinWidth')
            ->willReturn(self::MIN_WIDTH);

        $resizerMock->expects($this->any())
            ->method('getMaxWidth')
            ->willReturn(self::MAX_WIDTH);

        $resizerMock->expects($this->any())
            ->method('resize')
            ->willReturnCallback($result);

        return $resizerMock;
    }

    public function setUp()
    {
        $this->_values = array_merge(range(100, 150), range(275, 325), range(1050, 1100));
    }

    /**
     * @param float $minAspectRatio
     * @param float $maxAspectRatio
     * @param int   $feed
     */
    protected function _runTests(
        $minAspectRatio,
        $maxAspectRatio,
        $feed = null)
    {
        $w = 0;
        $h = 0;
        /** @var Dimensions $result */
        $result = null;
        $resizerMock = $this->_getResizerMock(
            function () use (&$w) {
                return $w;
            },
            function () use (&$h) {
                return $h;
            },
            function (Rectangle $src, Rectangle $dst, Dimensions $canvas) use (&$result) {
                $result = $canvas;
            }
        );
        $resizer = new MediaAutoResizer(__FILE__, [
            'minAspectRatio' => $minAspectRatio,
            'maxAspectRatio' => $maxAspectRatio,
            'targetFeed'     => $feed,
        ], $resizerMock);

        $isEqual = abs($minAspectRatio - $maxAspectRatio) < 0.0000001;

        foreach ($this->_values as $w) {
            foreach ($this->_values as $h) {
                $resizer->getFile();
                $resultW = $result->getWidth();
                $resultH = $result->getHeight();
                $aspectRatio = $resultW / $resultH;
                $message = sprintf('%dx%d => %dx%d (%.4f)', $w, $h, $resultW, $resultH, $aspectRatio);
                $this->assertGreaterThanOrEqual(self::MIN_WIDTH, $resultW, $message);
                $this->assertLessThanOrEqual(self::MAX_WIDTH, $resultW, $message);
                if (!$isEqual) {
                    $this->assertGreaterThanOrEqual($minAspectRatio, $aspectRatio, $message);
                    $this->assertLessThanOrEqual($maxAspectRatio, $aspectRatio, $message);
                } else {
                    $this->assertEquals($minAspectRatio, $aspectRatio, $message);
                }
            }
        }
    }

    public function testForcedSquare()
    {
        $this->_runTests(1.0, 1.0);
    }

    public function testForcedLandscape()
    {
        $this->_runTests(1.2, 1.22);
    }

    public function testForcedPortrait()
    {
        $this->_runTests(0.8, 0.81);
    }

    public function testStory()
    {
        $this->_runTests(
            MediaAutoResizer::BEST_MIN_STORY_RATIO,
            MediaAutoResizer::BEST_MAX_STORY_RATIO,
            Constants::FEED_STORY
        );
    }

    public function testDefaultConstraints()
    {
        $this->_runTests(MediaAutoResizer::MIN_RATIO, MediaAutoResizer::MAX_RATIO);
    }
}
