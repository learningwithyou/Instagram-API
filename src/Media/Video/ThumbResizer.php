<?php

namespace InstagramAPI\Media\Video;

use InstagramAPI\Media\Photo\PhotoResizer;

class ThumbResizer extends VideoResizer
{
    /**
     * Constructor.
     *
     * @param string $inputFile
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function __construct(
        $inputFile)
    {
        parent::__construct($inputFile);
        $this->_outputFormat = '-f mjpeg -ss 00:00:01 -vframes 1';
    }

    /** {@inheritdoc} */
    protected function _makeTempFile()
    {
        return tempnam($this->_outputDir, 'THUMB');
    }

    /** {@inheritdoc} */
    public function getMinWidth()
    {
        return PhotoResizer::MIN_WIDTH;
    }

    /** {@inheritdoc} */
    public function getMaxWidth()
    {
        return PhotoResizer::MAX_WIDTH;
    }
}
