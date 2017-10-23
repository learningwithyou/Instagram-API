<?php

namespace InstagramAPI;

use InstagramAPI\Media\Dimensions;
use InstagramAPI\Media\Rectangle;
use InstagramAPI\Media\ResizerFactory;
use InstagramAPI\Media\ResizerInterface;

/**
 * Automatic media resizer.
 *
 * Resizes and crops/expands a media file to match Instagram's requirements,
 * if necessary. You can also use this with your own parameters, to force your
 * media into different aspects, ie square, or for adding borders to media.
 *
 * Usage:
 *
 * - Create an instance of the class with your media file and requirements.
 * - Call getFile() to get the path to a media file matching the requirements.
 *   This will be the same as the input file if no processing was required.
 * - Optionally, call deleteFile() if you want to delete the temporary file
 *   ahead of time instead of automatically when PHP does its object garbage
 *   collection. This function is safe and won't delete the original input file.
 *
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 */
class MediaAutoResizer
{
    /** @var int Crop Operation. */
    const CROP = 1;

    /** @var int Expand Operation. */
    const EXPAND = 2;

    /**
     * Lowest allowed general media aspect ratio (4:5, meaning portrait).
     *
     * These are decided by Instagram. Not by us!
     *
     * A different value (MIN_STORY_RATIO) will be used for story media.
     *
     * @var float
     *
     * @see https://help.instagram.com/1469029763400082
     */
    const MIN_RATIO = 0.8;

    /**
     * Highest allowed general media aspect ratio (1.91:1, meaning landscape).
     *
     * These are decided by Instagram. Not by us!
     *
     * A different value (MAX_STORY_RATIO) will be used for story media.
     *
     * @var float
     */
    const MAX_RATIO = 1.91;

    /**
     * Lowest allowed story aspect ratio.
     *
     * This range was decided through community research, which revealed that
     * all Instagram stories are in ~9:16 (0.5625, widescreen portrait) ratio,
     * with a small range of similar portrait ratios also being used sometimes.
     *
     * We have selected a photo/video story aspect range which supports all
     * story media aspects that are commonly used by the app: 0.56 - 0.67.
     * (That's ~1080x1611 to ~1080x1928.)
     *
     * However, note that we'll target the "best story aspect ratio range"
     * by default and that you must manually disable that constructor option
     * to get this extended story aspect range, if you REALLY want it...
     *
     * @var float
     *
     * @see https://github.com/mgp25/Instagram-API/issues/1420#issuecomment-318146010
     */
    const MIN_STORY_RATIO = 0.56;

    /**
     * Highest allowed story aspect ratio.
     *
     * This range was decided through community research.
     *
     * @var float
     */
    const MAX_STORY_RATIO = 0.67;

    /**
     * The best story aspect ratio.
     *
     * This is exactly 9:16 ratio, meaning a standard widescreen phone viewed in
     * portrait mode. It is the most common story ratio on Instagram, and it's
     * the one that looks the best on most devices. All other ratios will look
     * "cropped" when viewed on 16:9 widescreen devices, since the app "zooms"
     * the story until it fills the screen without any black bars. So unless the
     * story is exactly 16:9, it won't look great on 16:9 screens.
     *
     * Every manufacturer uses 16:9 screens. Even Apple since the iPhone 5.
     *
     * Therefore, this will be the final target aspect ratio used EVERY time
     * that media destined for a story feed is outside of the allowed range!
     * That's because it doesn't make sense to let people target non-9:16 final
     * story aspect ratios, since only 9:16 stories look good on most devices!
     *
     * @var float
     */
    const BEST_STORY_RATIO = 0.5625;

    /**
     * Lowest ratio allowed when enforcing the best story aspect ratio.
     *
     * These constants are used instead of MIN_STORY_RATIO and MAX_STORY_RATIO
     * whenever the user tells us to "use the best ~9:16 story ratio" (which is
     * enabled by default). We need to allow a bit above/below it to prevent
     * pointless processing when the media is a few pixels off from the perfect
     * ratio, since the perfect story ratio is often impossible to hit unless
     * the input media is already exactly 720x1280 or 1080x1920.
     *
     * @var float
     */
    const BEST_MIN_STORY_RATIO = 0.56;

    /**
     * Highest ratio allowed when enforcing the best story aspect ratio.
     *
     * @var float
     */
    const BEST_MAX_STORY_RATIO = 0.565;

    /**
     * Override for the default temp path used by all class instances.
     *
     * If you don't provide any tmpPath to the constructor, we'll use this value
     * instead (if non-null). Otherwise we'll use the default system tmp folder.
     *
     * TIP: If your default system temp folder isn't writable, it's NECESSARY
     * for you to set this value to another, writable path, like this:
     *
     * \InstagramAPI\MediaAutoResizer::$defaultTmpPath = '/home/example/foo/';
     */
    public static $defaultTmpPath = null;

    /** @var bool Whether to output debugging info during calculation steps. */
    protected $_debug;

    /** @var string Input file path. */
    protected $_inputFile;

    /** @var string Target feed (either "story" or "general"). */
    protected $_targetFeed;

    /** @var float|null Minimum allowed aspect ratio. */
    protected $_minAspectRatio;

    /** @var float|null Maximum allowed aspect ratio. */
    protected $_maxAspectRatio;

    /** @var float Whether to allow the new aspect ratio (during processing) to
     * deviate slightly from the min/max targets. See constructor for info. */
    protected $_allowNewAspectDeviation;

    /** @var int Crop focus position (-50 .. 50) when cropping horizontally. */
    protected $_horCropFocus;

    /** @var int Crop focus position (-50 .. 50) when cropping vertically. */
    protected $_verCropFocus;

    /** @var array Background color [R, G, B] for the final media. */
    protected $_bgColor;

    /** @var int Operation to perform on the media. */
    protected $_operation;

    /** @var string Path to a tmp directory. */
    protected $_tmpPath;

    /** @var string Output file path. */
    protected $_outputFile;

    /** @var ResizerInterface The media resizer for our input file. */
    protected $_resizer;

    /**
     * Constructor.
     *
     * Available `$options` parameters:
     *
     * - "targetFeed" (int): One of the FEED_X constants. MUST be used if you're
     *   targeting stories. Defaults to `Constants::FEED_TIMELINE`.
     *
     * - "horCropFocus" (int): Crop focus position (-50 .. 50) when cropping
     *   horizontally (reducing width). Uses intelligent guess if not set.
     *
     * - "verCropFocus" (int): Crop focus position (-50 .. 50) when cropping
     *   vertically (reducing height). Uses intelligent guess if not set.
     *
     * - "minAspectRatio" (float): Minimum allowed aspect ratio. Uses
     *   auto-selected class constants if not set.
     *
     * - "maxAspectRatio" (float): Maximum allowed aspect ratio. Uses
     *   auto-selected class constants if not set.
     *
     * - "useBestStoryRatio" (bool): Enabled by default and affects which
     *   min/max aspect class constants are auto-selected for stories.
     *
     * - "allowNewAspectDeviation" (bool): Whether to allow the new aspect ratio
     *   (during processing) to deviate slightly from the min/max targets.
     *   Normally, we will ENSURE that the resulting canvas PERFECTLY fits
     *   within the provided minimum and maximum aspect ratio ranges. However,
     *   if you want to resize your media to an "exact" static ratio such as
     *   "minAspectRatio:1.25, maxAspectRatio:1.25" (or perhaps to a min/max
     *   ratio from another piece of media, if you want all media to be changed
     *   to the same size), then your result would almost always violate that
     *   request since it would be IMPOSSIBLE to achieve such perfect and
     *   specific final ratios in MOST cases (due to the original dimensions of
     *   your input media). The ONLY ratio that is 100% sure to ALWAYS be
     *   perfectly reachable is 1:1 (square). Other ratios may not be perfectly
     *   possible. But by setting this option to `FALSE`, you will tell our
     *   processing to allow such "slight missteps" and permit the final
     *   "closest-possible canvas" we've calculated anyway. We will still get as
     *   close as absolutely possible. For example, we may instead reach
     *   "1.25385" in the "1.25" example.
     *
     * - "bgColor" (array) - Array with 3 color components `[R, G, B]`
     *   (0-255/0x00-0xFF) for the background. Uses white if not set.
     *
     * - "operation" (int) - Operation to perform on the media (CROP or EXPAND).
     *   Uses `self::CROP` if not set.
     *
     * - "tmpPath" (string) - Path to temp directory. Uses system temp location
     *   or the class-default (`self::$defaultTmpPath`) if not set.
     *
     * - "debug" (bool) - Whether to output debugging info during calculation
     *   steps.
     *
     * - "customResizer" (string) - Class name for a custom resizer. It must implement ResizerInterface.
     *
     * @param string $inputFile Path to an input file.
     * @param array  $options   An associative array of optional parameters. See constructor description.
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function __construct(
        $inputFile,
        array $options = [])
    {
        // Assign variables for all options, to avoid bulky code repetition.
        $targetFeed = isset($options['targetFeed']) ? $options['targetFeed'] : Constants::FEED_TIMELINE;
        $horCropFocus = isset($options['horCropFocus']) ? $options['horCropFocus'] : null;
        $verCropFocus = isset($options['verCropFocus']) ? $options['verCropFocus'] : null;
        $minAspectRatio = isset($options['minAspectRatio']) ? $options['minAspectRatio'] : null;
        $maxAspectRatio = isset($options['maxAspectRatio']) ? $options['maxAspectRatio'] : null;
        $useBestStoryRatio = isset($options['useBestStoryRatio']) ? (bool) $options['useBestStoryRatio'] : true;
        $allowNewAspectDeviation = isset($options['allowNewAspectDeviation']) ? (bool) $options['allowNewAspectDeviation'] : false;
        $bgColor = isset($options['bgColor']) ? $options['bgColor'] : null;
        $operation = isset($options['operation']) ? $options['operation'] : null;
        $tmpPath = isset($options['tmpPath']) ? $options['tmpPath'] : null;
        $debug = isset($options['debug']) ? $options['debug'] : null;

        // Debugging.
        $this->_debug = $debug === true;

        // Input file.
        if (!is_file($inputFile)) {
            throw new \InvalidArgumentException(sprintf('Input file "%s" doesn\'t exist.', $inputFile));
        }
        $this->_inputFile = $inputFile;

        // Horizontal crop focus.
        if ($horCropFocus !== null && (!is_int($horCropFocus) || $horCropFocus < -50 || $horCropFocus > 50)) {
            throw new \InvalidArgumentException('Horizontal crop focus must be between -50 and 50.');
        }
        $this->_horCropFocus = $horCropFocus;

        // Vertical crop focus.
        if ($verCropFocus !== null && (!is_int($verCropFocus) || $verCropFocus < -50 || $verCropFocus > 50)) {
            throw new \InvalidArgumentException('Vertical crop focus must be between -50 and 50.');
        }
        $this->_verCropFocus = $verCropFocus;

        // Target feed. Turn it into a string for easier processing,
        // since we only care about story ratios vs general ratios.
        switch ($targetFeed) {
        case Constants::FEED_STORY:
        case Constants::FEED_DIRECT_STORY:
            $targetFeed = 'story';
            break;
        default:
            $targetFeed = 'general';
        }
        $this->_targetFeed = $targetFeed;

        // Determine the legal min/max aspect ratios for the target feed.
        if ($targetFeed === 'story') {
            if ($useBestStoryRatio) { // On by default.
                $allowedMinRatio = self::BEST_MIN_STORY_RATIO;
                $allowedMaxRatio = self::BEST_MAX_STORY_RATIO;
            } else {
                $allowedMinRatio = self::MIN_STORY_RATIO;
                $allowedMaxRatio = self::MAX_STORY_RATIO;
            }
        } else {
            $allowedMinRatio = self::MIN_RATIO;
            $allowedMaxRatio = self::MAX_RATIO;
        }

        // Select allowed aspect ratio range based on defaults and user input.
        if ($minAspectRatio !== null && ($minAspectRatio < $allowedMinRatio || $minAspectRatio > $allowedMaxRatio)) {
            throw new \InvalidArgumentException(sprintf('Minimum aspect ratio must be between %.3f and %.3f.',
                $allowedMinRatio, $allowedMaxRatio));
        } elseif ($minAspectRatio === null) {
            $minAspectRatio = $allowedMinRatio;
        }
        if ($maxAspectRatio !== null && ($maxAspectRatio < $allowedMinRatio || $maxAspectRatio > $allowedMaxRatio)) {
            throw new \InvalidArgumentException(sprintf('Maximum aspect ratio must be between %.3f and %.3f.',
                $allowedMinRatio, $allowedMaxRatio));
        } elseif ($maxAspectRatio === null) {
            $maxAspectRatio = $allowedMaxRatio;
        }
        if ($minAspectRatio !== null && $maxAspectRatio !== null && $minAspectRatio > $maxAspectRatio) {
            throw new \InvalidArgumentException('Maximum aspect ratio must be greater than or equal to minimum.');
        }
        $this->_minAspectRatio = $minAspectRatio;
        $this->_maxAspectRatio = $maxAspectRatio;

        // Allow the aspect ratio of the final, new canvas to deviate slightly?
        $this->_allowNewAspectDeviation = (bool) $allowNewAspectDeviation;

        // Background color.
        if ($bgColor !== null && (!is_array($bgColor) || count($bgColor) !== 3 || !isset($bgColor[0]) || !isset($bgColor[1]) || !isset($bgColor[2]))) {
            throw new \InvalidArgumentException('The background color must be a 3-element array [R, G, B].');
        } elseif ($bgColor === null) {
            $bgColor = [255, 255, 255]; // White.
        }
        $this->_bgColor = $bgColor;

        // Media operation.
        if ($operation !== null && $operation !== self::CROP && $operation !== self::EXPAND) {
            throw new \InvalidArgumentException('The operation must be one of the class constants CROP or EXPAND.');
        } elseif ($operation === null) {
            $operation = self::CROP;
        }
        $this->_operation = $operation;

        // Temporary directory path.
        if ($tmpPath === null) {
            $tmpPath = self::$defaultTmpPath !== null
                       ? self::$defaultTmpPath
                       : sys_get_temp_dir();
        }
        if (!is_dir($tmpPath) || !is_writable($tmpPath)) {
            throw new \InvalidArgumentException(sprintf('Directory %s does not exist or is not writable.', $tmpPath));
        }
        $this->_tmpPath = realpath($tmpPath);

        // Init a resizer.
        $this->_resizer = $this->_initResizer(isset($options['customResizer']) ? $options['customResizer'] : null);
    }

    /**
     * Init a resizer.
     *
     * @param string|null $resizerClass
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     *
     * @return ResizerInterface
     */
    protected function _initResizer(
        $resizerClass = null)
    {
        if ($resizerClass === null) {
            $resizerClass = ResizerFactory::createFromFile($this->_inputFile);
        } else {
            try {
                $reflection = new \ReflectionClass($resizerClass);
            } catch (\ReflectionException $e) {
                throw new \InvalidArgumentException(sprintf('Failed to fetch reflection data from class "%s".', $resizerClass));
            }

            if (!$reflection->implementsInterface(ResizerInterface::class)) {
                throw new \InvalidArgumentException('Custom resizer class must implement ResizerInterface.');
            }
        }

        return new $resizerClass($this->_inputFile, $this->_tmpPath, $this->_bgColor);
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        $this->deleteFile();
    }

    /**
     * Removes the output file if it exists and differs from input file.
     *
     * This function is safe and won't delete the original input file.
     *
     * Is automatically called when the class instance is destroyed by PHP.
     * But you can manually call it ahead of time if you want to force cleanup.
     *
     * Note that getFile() will still work afterwards, but will have to process
     * the media again to a new temp file if the input file required processing.
     *
     * @return bool
     */
    public function deleteFile()
    {
        // Only delete if outputfile exists and isn't the same as input file.
        if ($this->_outputFile !== null && $this->_outputFile !== $this->_inputFile && is_file($this->_outputFile)) {
            $result = @unlink($this->_outputFile);
            $this->_outputFile = null; // Reset so getFile() will work again.
            return $result;
        }

        return true;
    }

    /**
     * Gets the path to a media file matching the requirements.
     *
     * The automatic processing is performed the first time that this function
     * is called. Which means that no CPU time is wasted if you never call this
     * function at all.
     *
     * Due to the processing, the first call to this function may take a moment.
     *
     * If the input file already fits all of the specifications, we simply
     * return the input path instead, without any need to re-process it.
     *
     * @throws \Exception
     * @throws \RuntimeException
     *
     * @return string The path to the media file.
     *
     * @see MediaAutoResizer::_shouldProcess() For the criteria that determines processing.
     */
    public function getFile()
    {
        if ($this->_outputFile === null) {
            $this->_outputFile = $this->_shouldProcess() ? $this->_process() : $this->_inputFile;
        }

        return $this->_outputFile;
    }

    /**
     * Checks whether we should process the input file.
     *
     * @throws \RuntimeException
     *
     * @return bool
     */
    protected function _shouldProcess()
    {
        $inputDimensions = $this->_resizer->getInputDimensions();
        $inputWidth = $inputDimensions->getWidth();
        $inputAspectRatio = $inputWidth / $inputDimensions->getHeight();

        // Process if width < minimum allowed.
        if ($inputWidth < $this->_resizer->getMinWidth()) {
            return true;
        }

        // Process if width > maximum allowed.
        if ($inputWidth > $this->_resizer->getMaxWidth()) {
            return true;
        }

        // Process if aspect ratio < minimum allowed.
        if ($this->_minAspectRatio !== null && $inputAspectRatio < $this->_minAspectRatio) {
            return true;
        }

        // Process if aspect ratio > maximum allowed.
        if ($this->_maxAspectRatio !== null && $inputAspectRatio > $this->_maxAspectRatio) {
            return true;
        }

        // Process if the media resizer sees any other problems with the input
        // file (such as needing rotation or media format transcoding).
        // NOTE: Nobody is allowed to call `isMod2CanvasRequired()` here. That
        // isn't its purpose. Whether a final Mod2 canvas is required for actual
        // resizing has NOTHING to do with whether the input file is ok.
        if ($this->_resizer->isProcessingRequired()) {
            return true;
        }

        // No need to do any processing.
        return false;
    }

    /**
     * Process the input file and create the new file.
     *
     * @throws \RuntimeException
     *
     * @return string The path to the new file.
     */
    protected function _process()
    {
        // Get the dimensions of the original input file.
        $inputCanvas = $this->_resizer->getInputDimensions();

        // Create an output canvas with the desired dimensions.
        // WARNING: This creates a LEGAL canvas which MUST be followed EXACTLY.
        $canvasInfo = $this->_calculateNewCanvas( // Throws.
            $this->_targetFeed,
            $this->_operation,
            $inputCanvas->getWidth(),
            $inputCanvas->getHeight(),
            $this->_resizer->isMod2CanvasRequired(),
            $this->_resizer->getMinWidth(),
            $this->_resizer->getMaxWidth(),
            $this->_minAspectRatio,
            $this->_maxAspectRatio,
            $this->_allowNewAspectDeviation
        );
        $outputCanvas = $canvasInfo['canvas'];

        // Determine the media operation's resampling parameters and perform it.
        // NOTE: This section is EXCESSIVELY commented to explain each step. The
        // algorithm is pretty easy after you understand it. But without the
        // detailed comments, future contributors may not understand any of it!
        // "We'd rather have a WaLL oF TeXt for future reference, than bugs due
        // to future misunderstandings!" - SteveJobzniak ;-)
        if ($this->_operation === self::CROP) {
            // Determine the IDEAL canvas dimensions as if Mod2 adjustments were
            // not applied. That's NECESSARY for calculating an ACCURATE scale-
            // change compared to the input, so that we can calculate how much
            // the canvas has rescaled. WARNING: These are 1-dimensional scales,
            // and only ONE value (the uncropped side) is valid for comparison.
            $idealCanvas = new Dimensions($outputCanvas->getWidth() - $canvasInfo['mod2WidthDiff'],
                                          $outputCanvas->getHeight() - $canvasInfo['mod2HeightDiff']);
            $idealWidthScale = (float) ($idealCanvas->getWidth() / $inputCanvas->getWidth());
            $idealHeightScale = (float) ($idealCanvas->getHeight() / $inputCanvas->getHeight());
            $this->_debugDimensions(
                $inputCanvas->getWidth(), $inputCanvas->getHeight(),
                'CROP: Analyzing Original Input Canvas Size'
            );
            $this->_debugDimensions(
                $idealCanvas->getWidth(), $idealCanvas->getHeight(),
                'CROP: Analyzing Ideally Cropped (Non-Mod2-adjusted) Output Canvas Size'
            );
            $this->_debugText(
                'CROP: Scale of Ideally Cropped Canvas vs Input Canvas',
                'width=%.8f, height=%.8f',
                $idealWidthScale, $idealHeightScale
            );

            // Now determine HOW the IDEAL canvas has been cropped compared to
            // the INPUT canvas. But we can't just compare dimensions, since our
            // algorithms may have cropped and THEN scaled UP the dimensions to
            // legal values far above the input values, or scaled them DOWN and
            // then Mod2-cropped at the new scale, etc. There are so many
            // possibilities. That's also why we couldn't "just keep track of
            // amount of pixels cropped during main algorithm". We MUST figure
            // it out ourselves accurately HERE. We can't do it at any earlier
            // stage, since cumulative rounding errors from width/height
            // readjustments could drift us away from the target aspect ratio
            // and could prevent pixel-perfect results UNLESS we calc it HERE.
            //
            // There's IS a great way to figure out the cropping. When the WIDTH
            // of a canvas is reduced (making it more "portraity"), its aspect
            // ratio number decreases. When the HEIGHT of a canvas is reduced
            // (making it more "landscapey"), its aspect ratio number increases.
            //
            // And our canvas cropping algorithm only crops in ONE DIRECTION
            // (width or height), so we only need to detect the aspect ratio
            // change of the IDEAL (non-Mod2-adjusted) canvas, to know what
            // happened. However, note that this CAN also trigger if the input
            // had to be up/downscaled (to an imperfect final aspect), but that
            // doesn't matter since this algorithm will STILL figure out the
            // proper scale and croppings to use for the canvas. Because uneven,
            // aspect-affecting scaling basically IS cropping the INPUT canvas!
            if ($idealCanvas->getAspectRatio() === $inputCanvas->getAspectRatio()) {
                // No sides have been cropped. So both width and height scales
                // WILL be IDENTICAL, since NOTHING else would be able to create
                // an identical aspect ratio again (otherwise the aspect ratio
                // would have been warped (not equal)). So just pick either one.
                // NOTE: Identical (uncropped ratio) DOESN'T mean that scale is
                // going to be 1.0. It MAY be. Or the canvas MAY have been
                // evenly expanded or evenly shrunk in both dimensions.
                $hasCropped = 'nothing';
                $overallRescale = $idealWidthScale; // $idealHeightScale IS identical.
            } elseif ($idealCanvas->getAspectRatio() < $inputCanvas->getAspectRatio()) {
                // The horizontal width has been cropped. Grab the height's
                // scale, since that side is "unaffected" by the main cropping
                // and should therefore have a scale of 1. Although it may have
                // had up/down-scaling. In that case, the height scale will
                // represent the amount of overall rescale change.
                $hasCropped = 'width';
                $overallRescale = $idealHeightScale;
            } else { // Output aspect is > input.
                // The vertical height has been cropped. Just like above, the
                // "unaffected" side is what we'll use as our scale reference.
                $hasCropped = 'height';
                $overallRescale = $idealWidthScale;
            }
            $this->_debugText(
                'CROP: Detecting Cropped Direction',
                'cropped=%s, overallRescale=%.8f',
                $hasCropped, $overallRescale
            );

            // Alright, now calculate the dimensions of the "IDEALLY CROPPED
            // INPUT canvas", at INPUT canvas scale. These are the scenarios:
            //
            // - "hasCropped: nothing, scale is 1.0" = Nothing was cropped, and
            //   nothing was scaled. Treat as "use whole INPUT canvas". This is
            //   pixel-perfect.
            //
            // - "hasCropped: nothing, scale NOT 1.0" = Nothing was cropped, but
            //   the whole canvas was up/down-scaled. We don't have to care at
            //   all about that scaling and should treat it as "use whole INPUT
            //   canvas" for crop calculation purposes. The cropped result will
            //   later be scaled/stretched to the canvas size (up or down).
            //
            // - "hasCropped: width/height, scale is 1.0" = A single side was
            //   cropped, and nothing was scaled. Treat as "use IDEALLY CROPPED
            //   canvas". This is pixel-perfect.
            //
            // - "hasCropped: width/height, scale NOT 1.0" = A single side was
            //   cropped, and then the whole canvas was up/down-scaled. Treat as
            //   "use scale-fixed version of IDEALLY CROPPED canvas". The
            //   cropped result will later be scaled/stretched to the canvas
            //   size (up or down).
            //
            // There's an easy way to handle ALL of those scenarios: Just
            // translate the IDEALLY CROPPED canvas back into INPUT-SCALED
            // dimensions. Then we'll get a pixel-perfect "input crop" whenever
            // scale is 1.0, since a scale of 1.0 gives the same result back.
            // And we'll get a properly re-scaled result in all other cases.
            //
            // NOTE: This result CAN deviate from what was "actually cropped"
            // during the main algorithm. That is TOTALLY INTENTIONAL AND IS THE
            // INTENDED, PERFECT BEHAVIOR! Do NOT change this code! By always
            // re-calculating here, we'll actually FIX rounding errors caused by
            // the main algorithm's multiple steps, and will create better
            // looking rescaling, and pixel-perfect unscaled croppings and
            // pixel-perfect unscaled Mod2 adjustments!

            // First calculate the overall IDEAL cropping applied to the INPUT
            // canvas. If scale is 1.0 it will be used as-is (pixel-perfect).
            // NOTE: We tell it to use round() so that the rescaled pixels are
            // as close to the perfect aspect ratio as possible.
            $croppedInputCanvas = $idealCanvas->withRescaling(1 / $overallRescale, 'round');
            $this->_debugDimensions(
                $croppedInputCanvas->getWidth(), $croppedInputCanvas->getHeight(),
                'CROP: Rescaled Ideally Cropped Canvas to Input Dimension Space'
            );

            // Now re-scale the Mod2 adjustments to the INPUT canvas coordinate
            // space too. If scale is 1.0 they'll be used as-is (pixel-perfect).
            // If the scale is up/down, they'll be rounded to the next whole
            // number. The rounding is INTENTIONAL, because if scaling was used
            // for the IDEAL canvas then it DOESN'T MATTER how many exact pixels
            // we crop, but round() gives us the BEST APPROXIMATION!
            $rescaledMod2WidthDiff = (int) round($canvasInfo['mod2WidthDiff'] * (1 / $overallRescale));
            $rescaledMod2HeightDiff = (int) round($canvasInfo['mod2HeightDiff'] * (1 / $overallRescale));
            $this->_debugText(
                'CROP: Rescaled Mod2 Adjustments to Input Dimension Space',
                'width=%s, height=%s, widthRescaled=%s, heightRescaled=%s',
                $canvasInfo['mod2WidthDiff'], $canvasInfo['mod2HeightDiff'],
                $rescaledMod2WidthDiff, $rescaledMod2HeightDiff
            );

            // Apply the Mod2 adjustments to the input cropping that we'll
            // perform. This ensures that ALL of the Mod2 croppings (in ANY
            // dimension) will always be pixel-perfect when we're at scale 1.0!
            $croppedInputCanvas = new Dimensions($croppedInputCanvas->getWidth() + $rescaledMod2WidthDiff,
                                                 $croppedInputCanvas->getHeight() + $rescaledMod2HeightDiff);
            $this->_debugDimensions(
                $croppedInputCanvas->getWidth(), $croppedInputCanvas->getHeight(),
                'CROP: Applied Mod2 Adjustments to Final Cropped Input Canvas'
            );

            // The "CROPPED INPUT canvas" is in the same dimensions/coordinate
            // space as the "INPUT canvas". So ensure all dimensions are valid
            // (don't exceed INPUT) and create the final "CROPPED INPUT canvas".
            // NOTE: This is it... if the media is at scale 1.0, we now have a
            // pixel-perfect, cropped canvas with ALL of the cropping and Mod2
            // adjustments applied to it! And if we're at another scale, we have
            // a perfectly recalculated, cropped canvas which took into account
            // cropping, scaling and Mod2 adjustments. Advanced stuff! :-)
            $croppedInputCanvasWidth = $croppedInputCanvas->getWidth() <= $inputCanvas->getWidth()
                                     ? $croppedInputCanvas->getWidth() : $inputCanvas->getWidth();
            $croppedInputCanvasHeight = $croppedInputCanvas->getHeight() <= $inputCanvas->getHeight()
                                      ? $croppedInputCanvas->getHeight() : $inputCanvas->getHeight();
            $croppedInputCanvas = new Dimensions($croppedInputCanvasWidth, $croppedInputCanvasHeight);
            $this->_debugDimensions(
                $croppedInputCanvas->getWidth(), $croppedInputCanvas->getHeight(),
                'CROP: Clamped to Legal Input Max-Dimensions'
            );

            // Initialize the crop-shifting variables. They control the range of
            // X/Y coordinates we'll copy from ORIGINAL INPUT to OUTPUT canvas.
            // NOTE: This properly selects the entire INPUT media canvas area.
            $x1 = $y1 = 0;
            $x2 = $inputCanvas->getWidth();
            $y2 = $inputCanvas->getHeight();
            $this->_debugText(
                'CROP: Initializing X/Y Variables to Full Input Canvas Size',
                'x1=%s, x2=%s, y1=%s, y2=%s',
                $x1, $x2, $y1, $y2
            );

            // Calculate the width and height diffs between the original INPUT
            // canvas and the new CROPPED INPUT canvas. Negative values mean the
            // output is smaller (which we'll handle by cropping), and larger
            // values would mean the output is larger (which we'll handle by
            // letting the OUTPUT canvas stretch the 100% uncropped original
            // pixels of the INPUT in that direction, to fill the whole canvas).
            // NOTE: Because of clamping of the CROPPED INPUT canvas above, this
            // will actually never be a positive ("scale up") number. It will
            // only be 0 or less. That's good, just be aware of it if editing!
            $widthDiff = $croppedInputCanvas->getWidth() - $inputCanvas->getWidth();
            $heightDiff = $croppedInputCanvas->getHeight() - $inputCanvas->getHeight();
            $this->_debugText(
                'CROP: Calculated Input Canvas Crop Amounts',
                'width=%s px, height=%s px',
                $widthDiff, $heightDiff
            );

            // After ALL of that work... we finally know how to crop the input
            // canvas! Alright... handle cropping of the INPUT width and height!
            // NOTE: The main canvas-creation algorithm only crops a single
            // dimension (width or height), but its Mod2 adjustments may have
            // caused BOTH to be cropped, which is why we MUST process both.
            if ($widthDiff < 0) {
                // Horizontal cropping. Focus on the center by default.
                $horCropFocus = $this->_horCropFocus !== null ? $this->_horCropFocus : 0;
                $this->_debugText('CROP: Horizontal Crop Focus', 'focus=%s', $horCropFocus);

                // Invert the focus if this is horizontally flipped media.
                if ($this->_resizer->isHorFlipped()) {
                    $horCropFocus = -$horCropFocus;
                    $this->_debugText(
                        'CROP: Media is HorFlipped, Flipping Horizontal Crop Focus',
                        'focus=%s',
                        $horCropFocus
                    );
                }

                // Calculate amount of pixels to crop and shift them as-focused.
                // NOTE: Always use floor() to make uneven amounts lean at left.
                $absWidthDiff = abs($widthDiff);
                $x1 = (int) floor($absWidthDiff * (50 + $horCropFocus) / 100);
                $x2 = $x2 - ($absWidthDiff - $x1);
                $this->_debugText('CROP: Calculated New X Offsets', 'x1=%s, x2=%s', $x1, $x2);
            }
            if ($heightDiff < 0) {
                // Vertical cropping. Focus on top by default (to keep faces).
                $verCropFocus = $this->_verCropFocus !== null ? $this->_verCropFocus : -50;
                $this->_debugText('CROP: Vertical Crop Focus', 'focus=%s', $verCropFocus);

                // Invert the focus if this is vertically flipped media.
                if ($this->_resizer->isVerFlipped()) {
                    $verCropFocus = -$verCropFocus;
                    $this->_debugText(
                        'CROP: Media is VerFlipped, Flipping Vertical Crop Focus',
                        'focus=%s',
                        $verCropFocus
                    );
                }

                // Calculate amount of pixels to crop and shift them as-focused.
                // NOTE: Always use floor() to make uneven amounts lean at top.
                $absHeightDiff = abs($heightDiff);
                $y1 = (int) floor($absHeightDiff * (50 + $verCropFocus) / 100);
                $y2 = $y2 - ($absHeightDiff - $y1);
                $this->_debugText('CROP: Calculated New Y Offsets', 'y1=%s, y2=%s', $y1, $y2);
            }

            // Create a source rectangle which starts at the start-offsets
            // (x1/y1) and lasts until the width and height of the desired area.
            $srcRect = new Rectangle($x1, $y1, $x2 - $x1, $y2 - $y1);
            $this->_debugText(
                'CROP_SRC: Input Canvas Source Rectangle',
                'x1=%s, x2=%s, y1=%s, y2=%s, width=%s, height=%s, aspect=%.8f',
                $srcRect->getX1(), $srcRect->getX2(), $srcRect->getY1(), $srcRect->getY2(),
                $srcRect->getWidth(), $srcRect->getHeight(), $srcRect->getAspectRatio()
            );

            // Create a destination rectangle which completely fills the entire
            // output canvas from edge to edge. This ensures that any undersized
            // or oversized input will be stretched properly in all directions.
            //
            // NOTE: Everything about our cropping/canvas algorithms is
            // optimized so that stretching won't happen unless the media is so
            // tiny that it's below the minimum width or so wide that it must be
            // shrunk. Everything else WILL use sharp 1:1 pixels and pure
            // cropping instead of stretching/shrinking. And when stretch/shrink
            // is used, the aspect ratio is always perfectly maintained!
            $dstRect = new Rectangle(0, 0, $outputCanvas->getWidth(), $outputCanvas->getHeight());
            $this->_debugText(
                'CROP_DST: Output Canvas Destination Rectangle',
                'x1=%s, x2=%s, y1=%s, y2=%s, width=%s, height=%s, aspect=%.8f',
                $dstRect->getX1(), $dstRect->getX2(), $dstRect->getY1(), $dstRect->getY2(),
                $dstRect->getWidth(), $dstRect->getHeight(), $dstRect->getAspectRatio()
            );
        } elseif ($this->_operation === self::EXPAND) {
            // We'll copy the entire original input media onto the new canvas.
            // Always copy from the absolute top left of the original media.
            $srcRect = new Rectangle(0, 0, $inputCanvas->getWidth(), $inputCanvas->getHeight());
            $this->_debugText(
                'EXPAND_SRC: Input Canvas Source Rectangle',
                'x1=%s, x2=%s, y1=%s, y2=%s, width=%s, height=%s, aspect=%.8f',
                $srcRect->getX1(), $srcRect->getX2(), $srcRect->getY1(), $srcRect->getY2(),
                $srcRect->getWidth(), $srcRect->getHeight(), $srcRect->getAspectRatio()
            );

            // Determine the target dimensions to fit it on the new canvas,
            // because the input media's dimensions may have been too large.
            // This will not scale anything (uses scale=1) if the input fits.
            $outputWidthScale = (float) ($outputCanvas->getWidth() / $inputCanvas->getWidth());
            $outputHeightScale = (float) ($outputCanvas->getHeight() / $inputCanvas->getHeight());
            $scale = min($outputWidthScale, $outputHeightScale);
            $this->_debugText(
                'EXPAND: Calculating Scale to Fit Input on Output Canvas',
                'scale=%.8f',
                $scale
            );

            // Calculate the scaled destination rectangle. Note that X/Y remain.
            // NOTE: We tell it to use ceil(), which guarantees that it'll
            // never scale a side badly and leave a 1px gap between the media
            // and canvas sides. Also note that ceil will never produce bad
            // values, since PHP allows the dst_w/dst_h to exceed beyond canvas!
            $dstRect = $srcRect->withRescaling($scale, 'ceil');
            $this->_debugDimensions(
                $dstRect->getWidth(), $dstRect->getHeight(),
                'EXPAND: Rescaled Input to Output Dimension Space'
            );

            // Now calculate the centered destination offset on the canvas.
            // NOTE: We use floor() to ensure that the result gets left-aligned
            // perfectly, and prefers to lean towards towards the top as well.
            $dst_x = (int) floor(($outputCanvas->getWidth() - $dstRect->getWidth()) / 2);
            $dst_y = (int) floor(($outputCanvas->getHeight() - $dstRect->getHeight()) / 2);
            $this->_debugText(
                'EXPAND: Calculating Centered Destination on Output Canvas',
                'dst_x=%s, dst_y=%s',
                $dst_x, $dst_y
            );

            // Build the final destination rectangle for the expanded canvas!
            $dstRect = new Rectangle($dst_x, $dst_y, $dstRect->getWidth(), $dstRect->getHeight());
            $this->_debugText(
                'EXPAND_DST: Output Canvas Destination Rectangle',
                'x1=%s, x2=%s, y1=%s, y2=%s, width=%s, height=%s, aspect=%.8f',
                $dstRect->getX1(), $dstRect->getX2(), $dstRect->getY1(), $dstRect->getY2(),
                $dstRect->getWidth(), $dstRect->getHeight(), $dstRect->getAspectRatio()
            );
        } else {
            throw new \RuntimeException(sprintf('Unsupported operation: %s.', $this->_operation));
        }

        return $this->_resizer->resize($srcRect, $dstRect, $outputCanvas);
    }

    /**
     * Calculate a new canvas based on input size and requested modifications.
     *
     * The final canvas will be the same size as the input if everything was
     * already okay and within the limits. Otherwise it will be a new canvas
     * representing the _exact_, best-possible size to convert input media to.
     *
     * It is up to the caller to perfectly follow these orders, since deviating
     * by even a SINGLE PIXEL can create illegal media aspect ratios.
     *
     * Also note that the resulting canvas can be LARGER than the input in
     * several cases, such as in EXPAND-mode (obviously), or when the input
     * isn't wide enough to be legal (and must be scaled up), and whenever Mod2
     * is requested. In the latter case, the algorithm may have to add a few
     * pixels to the height to make it valid in a few rare cases. The caller
     * must be aware of such "enlarged" canvases and should handle them by
     * stretching the input if necessary.
     *
     * @param string     $targetFeed
     * @param int        $operation
     * @param int        $inputWidth
     * @param int        $inputHeight
     * @param bool       $isMod2CanvasRequired
     * @param int        $minWidth
     * @param int        $maxWidth
     * @param float|null $minAspectRatio
     * @param float|null $maxAspectRatio
     * @param bool       $allowNewAspectDeviation See constructor arg docs.
     *
     * @throws \RuntimeException If requested canvas couldn't be achieved, most
     *                           commonly if you have chosen way too narrow
     *                           aspect ratio ranges that cannot be perfectly
     *                           reached by your input media, and you AREN'T
     *                           running with `$allowNewAspectDeviation`.
     *
     * @return array An array with `canvas` (`Dimensions`), `mod2WidthDiff` and
     *               `mod2HeightDiff`. The latter are integers representing how
     *               many pixels were cropped (-) or added (+) by the Mod2 step
     *               compared to the ideal canvas.
     */
    protected function _calculateNewCanvas(
        $targetFeed,
        $operation,
        $inputWidth,
        $inputHeight,
        $isMod2CanvasRequired,
        $minWidth = 1,
        $maxWidth = 99999,
        $minAspectRatio = null,
        $maxAspectRatio = null,
        $allowNewAspectDeviation = false)
    {
        /*
         * WARNING TO POTENTIAL CONTRIBUTORS:
         *
         * THIS right here is the MOST COMPLEX algorithm in the whole project.
         * Everything is finely tuned to create 100% accurate, pixel-perfect
         * resizes. A SINGLE PIXEL ERROR in your calculations WILL lead to it
         * sometimes outputting illegally formatted files that will be rejected
         * by Instagram. We know this, because we have SEEN IT HAPPEN while we
         * tweaked and tweaked and tweaked to balance everything perfectly!
         *
         * Unfortunately, this file also seems to attract a lot of beginners.
         * Maybe because a "media resizer" seems "fun and easy". But that would
         * be an incorrect guess. It's the most serious algorithm in the whole
         * project. If you break it, *YOU* break people's uploads.
         *
         * We have had many random, new contributors just jumping in and adding
         * zero-effort code everywhere in here, and breaking the whole balance,
         * and then opening pull requests. We have rejected EVERY single one of
         * those pull requests because they were totally unusable and unsafe.
         *
         * We will not accept such pull requests. Ever.
         *
         * This warning is here to save your time, and ours.
         *
         * If you are interested in helping out with the MediaAutoResizer, then
         * that's GREAT! But in that case we require that you fully read through
         * the algorithm below and all of its comments about 50 times over a 3-4
         * day period - until you understand every single step perfectly. The
         * comments will help make it clearer the more you read...
         *
         *                                               ...and make an effort.
         *
         * Then you are ready... and welcome to the team. :-)
         *
         * Thank you.
         */

        // Initialize target canvas to original input dimensions & aspect ratio.
        $targetWidth = (int) $inputWidth;
        $targetHeight = (int) $inputHeight;
        $targetAspectRatio = $inputWidth / $inputHeight;
        $this->_debugDimensions($targetWidth, $targetHeight, 'CANVAS_INPUT: Input Canvas Size');

        // Check aspect ratio and crop/expand the canvas to fit aspect if needed.
        $useFloorHeightRecalc = true; // Height-behavior in any later re-calculations.
        if ($minAspectRatio !== null && $targetAspectRatio < $minAspectRatio) {
            // Use floor() so that height will always be above minAspectRatio.
            $useFloorHeightRecalc = true;
            // Determine target ratio; in case of stories we always target 9:16.
            $targetAspectRatio = $targetFeed === 'story'
                               ? self::BEST_STORY_RATIO : $minAspectRatio;

            if ($operation === self::CROP) {
                // We need to limit the height, so floor is used intentionally to
                // AVOID rounding height upwards to a still-illegal aspect ratio.
                $targetHeight = (int) floor($targetWidth / $targetAspectRatio);
                $this->_debugDimensions($targetWidth, $targetHeight, 'CANVAS_CROPPED: Aspect Was < MIN');
            } elseif ($operation === self::EXPAND) {
                // We need to expand the width with left/right borders. We use
                // ceil to guarantee that the final media is wide enough to be
                // above the minimum allowed aspect ratio.
                $targetWidth = (int) ceil($targetHeight * $targetAspectRatio);
                $this->_debugDimensions($targetWidth, $targetHeight, 'CANVAS_EXPANDED: Aspect Was < MIN');
            }
        } elseif ($maxAspectRatio !== null && $targetAspectRatio > $maxAspectRatio) {
            // Use ceil() so that height will always be below maxAspectRatio.
            $useFloorHeightRecalc = false;
            // Determine target ratio; in case of stories we always target 9:16.
            $targetAspectRatio = $targetFeed === 'story'
                               ? self::BEST_STORY_RATIO : $maxAspectRatio;

            if ($operation === self::CROP) {
                // We need to limit the width. We use floor to guarantee cutting
                // enough pixels, since our width exceeds the maximum allowed ratio.
                $targetWidth = (int) floor($targetHeight * $targetAspectRatio);
                $this->_debugDimensions($targetWidth, $targetHeight, 'CANVAS_CROPPED: Aspect Was > MAX');
            } elseif ($operation === self::EXPAND) {
                // We need to expand the height with top/bottom borders. We use
                // ceil to guarantee that the final media is tall enough to be
                // below the maximum allowed aspect ratio.
                $targetHeight = (int) ceil($targetWidth / $targetAspectRatio);
                $this->_debugDimensions($targetWidth, $targetHeight, 'CANVAS_EXPANDED: Aspect Was > MAX');
            }
        } else {
            $this->_debugDimensions($targetWidth, $targetHeight, 'CANVAS: Aspect Ratio Already Legal');

            // The media's aspect ratio is already within the legal range, but
            // we'll still need to set up a proper height re-calc variable if
            // our input needs to be re-scaled based on width limits further
            // below. So determine whether the input is closest to min or max.
            $minAspectDistance = abs(($minAspectRatio !== null
                ? $minAspectRatio : 0) - $targetAspectRatio);
            $maxAspectDistance = abs(($maxAspectRatio !== null
                ? $maxAspectRatio : 0) - $targetAspectRatio);

            // If it's closest to minimum allowed ratio, we'll use floor() to
            // ensure the result is above the minimum ratio. Otherwise we'll use
            // ceil() to ensure that the result is below the maximum ratio.
            $useFloorHeightRecalc = ($minAspectDistance < $maxAspectDistance);
        }

        // Verify square target ratios by ensuring canvas is now a square.
        // NOTE: This is just a sanity check against wrong code above. It will
        // never execute, since all code above took care of making both
        // dimensions identical already (if they differed in any way, they had a
        // non-1 ratio and invoked the aspect ratio cropping/expansion code). It
        // then made identical thanks to the fact that X / 1 = X, and X * 1 = X.
        // NOTE: It's worth noting that our squares are always the size of the
        // shortest side when cropping or the longest side when expanding.
        if ($targetAspectRatio === 1 && $targetWidth !== $targetHeight) { // Ratio 1 = Square.
            $targetWidth = $targetHeight = $operation === self::CROP
                         ? min($targetWidth, $targetHeight)
                         : max($targetWidth, $targetHeight);
            $this->_debugDimensions($targetWidth, $targetHeight, 'CANVAS_SQUARIFY: Fixed Badly Generated Square');
        }

        // Lastly, enforce minimum and maximum width limits on our final canvas.
        // NOTE: Instagram only enforces width & aspect ratio, which in turn
        // auto-limits height (since we can only use legal height ratios).
        // NOTE: Yet again, if the target ratio is 1 (square), we'll get
        // identical width & height, so NO NEED to MANUALLY "fix square" here.
        if ($targetWidth > $maxWidth) {
            $targetWidth = $maxWidth;
            $this->_debugDimensions($targetWidth, $targetHeight, 'CANVAS_WIDTH: Width Was > MAX');
            $targetHeight = $this->_accurateHeightRecalc($useFloorHeightRecalc, $targetAspectRatio, $targetWidth);
            $this->_debugDimensions($targetWidth, $targetHeight, 'CANVAS_WIDTH: Height Recalc From Width & Aspect');
        } elseif ($targetWidth < $minWidth) {
            $targetWidth = $minWidth;
            $this->_debugDimensions($targetWidth, $targetHeight, 'CANVAS_WIDTH: Width Was < MIN');
            $targetHeight = $this->_accurateHeightRecalc($useFloorHeightRecalc, $targetAspectRatio, $targetWidth);
            $this->_debugDimensions($targetWidth, $targetHeight, 'CANVAS_WIDTH: Height Recalc From Width & Aspect');
        }

        // All of the main canvas algorithms are now finished, and we are now
        // able to check Mod2 compatibility and accurately readjust if needed.
        $mod2WidthDiff = $mod2HeightDiff = 0;
        if ($isMod2CanvasRequired
            && (!$this->_isNumberMod2($targetWidth) || !$this->_isNumberMod2($targetHeight))
        ) {
            // Calculate the Mod2-adjusted final canvas size.
            $mod2Canvas = $this->_calculateAdjustedMod2Canvas(
                $inputWidth,
                $inputHeight,
                $useFloorHeightRecalc,
                $targetWidth,
                $targetHeight,
                $targetAspectRatio,
                $minWidth,
                $maxWidth,
                $minAspectRatio,
                $maxAspectRatio,
                $allowNewAspectDeviation
            );

            // Determine the pixel difference before and after processing.
            $mod2WidthDiff = $mod2Canvas->getWidth() - $targetWidth;
            $mod2HeightDiff = $mod2Canvas->getHeight() - $targetHeight;
            $this->_debugText('CANVAS: Mod2 Difference Stats', 'width=%s, height=%s', $mod2WidthDiff, $mod2HeightDiff);

            // Update the final canvas to the Mod2-adjusted canvas size.
            // NOTE: If code above failed, the new values are invalid. But so
            // could our original values have been. We check that further down.
            $targetWidth = $mod2Canvas->getWidth();
            $targetHeight = $mod2Canvas->getHeight();
            $this->_debugDimensions($targetWidth, $targetHeight, 'CANVAS: Updated From Mod2 Result');
        }

        // Create the new canvas Dimensions object.
        $canvas = new Dimensions($targetWidth, $targetHeight);
        $this->_debugDimensions($targetWidth, $targetHeight, 'CANVAS_OUTPUT: Final Output Canvas Size');

        // We must now validate the canvas before returning it.
        // NOTE: Most of these are just strict sanity-checks to protect against
        // bad code contributions in the future. The canvas won't be able to
        // pass all of these checks unless the algorithm above remains perfect.
        $isIllegalRatio = (($minAspectRatio !== null && $canvas->getAspectRatio() < $minAspectRatio)
                           || ($maxAspectRatio !== null && $canvas->getAspectRatio() > $maxAspectRatio));
        if ($canvas->getWidth() < 1 || $canvas->getHeight() < 1) {
            throw new \RuntimeException(sprintf(
                'Canvas calculation failed. Target width (%s) or height (%s) less than one pixel.',
                $canvas->getWidth(), $canvas->getHeight()
            ));
        } elseif ($canvas->getWidth() < $minWidth) {
            throw new \RuntimeException(sprintf(
                'Canvas calculation failed. Target width (%s) less than minimum allowed (%s).',
                $canvas->getWidth(), $minWidth()
            ));
        } elseif ($canvas->getWidth() > $maxWidth) {
            throw new \RuntimeException(sprintf(
                'Canvas calculation failed. Target width (%s) greater than maximum allowed (%s).',
                $canvas->getWidth(), $maxWidth
            ));
        } elseif ($isIllegalRatio) {
            if (!$allowNewAspectDeviation) {
                throw new \RuntimeException(sprintf(
                    'Canvas calculation failed. Unable to reach target aspect ratio range during output canvas generation. The range of allowed aspect ratios is too narrow (%.8f - %.8f). We achieved a ratio of %.8f.',
                    $minAspectRatio !== null ? $minAspectRatio : 0.0,
                    $maxAspectRatio !== null ? $maxAspectRatio : INF,
                    $canvas->getAspectRatio()
                ));
            } else {
                // The user wants us to allow "near-misses", so we proceed...
                $this->_debugDimensions($canvas->getWidth(), $canvas->getHeight(), 'CANVAS_FINAL: Allowing Deviating Aspect Ratio');
            }
        }

        return [
            'canvas'         => $canvas,
            'mod2WidthDiff'  => $mod2WidthDiff,
            'mod2HeightDiff' => $mod2HeightDiff,
        ];
    }

    /**
     * Calculates a new relative height using the target aspect ratio.
     *
     * Used internally by `_calculateNewCanvas()`.
     *
     * This algorithm aims at the highest-possible or lowest-possible resulting
     * aspect ratio based on what's needed. It uses either `floor()` or `ceil()`
     * depending on whether we need the resulting aspect ratio to be >= or <=
     * the target aspect ratio.
     *
     * The principle behind this is the fact that removing height (via floor)
     * will give us a higher aspect ratio. And adding height (via ceil) will
     * give us a lower aspect ratio.
     *
     * If the target aspect ratio is square (1), height becomes equal to width.
     *
     * @param bool  $useFloorHeightRecalc
     * @param float $targetAspectRatio
     * @param int   $targetWidth
     *
     * @return int
     */
    protected function _accurateHeightRecalc(
        $useFloorHeightRecalc,
        $targetAspectRatio,
        $targetWidth)
    {
        // Read the docs above to understand this CRITICALLY IMPORTANT code.
        $targetHeight = $useFloorHeightRecalc
                      ? (int) floor($targetWidth / $targetAspectRatio) // >=
                      : (int) ceil($targetWidth / $targetAspectRatio); // <=

        return $targetHeight;
    }

    /**
     * Adjusts dimensions to create a Mod2-compatible canvas.
     *
     * Used internally by `_calculateNewCanvas()`.
     *
     * The reason why this function also takes the original input width/height
     * is because it tries to maximize its usage of the available original pixel
     * surface area while correcting the dimensions. It uses the extra
     * information to know when it's safely able to grow the canvas beyond the
     * given target width/height parameter values.
     *
     * @param int        $inputWidth
     * @param int        $inputHeight
     * @param bool       $useFloorHeightRecalc
     * @param int        $targetWidth
     * @param int        $targetHeight
     * @param float      $targetAspectRatio
     * @param int        $minWidth
     * @param int        $maxWidth
     * @param float|null $minAspectRatio
     * @param float|null $maxAspectRatio
     * @param bool       $allowNewAspectDeviation See constructor arg docs.
     *
     * @throws \RuntimeException If requested canvas couldn't be achieved, most
     *                           commonly if you have chosen way too narrow
     *                           aspect ratio ranges that cannot be perfectly
     *                           reached by your input media, and you AREN'T
     *                           running with `$allowNewAspectDeviation`.
     *
     * @return Dimensions
     *
     * @see MediaAutoResizer::_calculateNewCanvas()
     */
    protected function _calculateAdjustedMod2Canvas(
        $inputWidth,
        $inputHeight,
        $useFloorHeightRecalc,
        $targetWidth,
        $targetHeight,
        $targetAspectRatio,
        $minWidth = 1,
        $maxWidth = 99999,
        $minAspectRatio = null,
        $maxAspectRatio = null,
        $allowNewAspectDeviation = false)
    {
        // Initialize to the calculated canvas size.
        $mod2Width = $targetWidth;
        $mod2Height = $targetHeight;
        $this->_debugDimensions($mod2Width, $mod2Height, 'MOD2_CANVAS: Current Canvas Size');

        // Determine if we're able to cut an extra pixel from the width if
        // necessary, or if cutting would take us below the minimum width.
        $canCutWidth = $mod2Width > $minWidth;

        // To begin, we must correct the width if it's uneven. We'll only do
        // this once, and then we'll leave the width at its new number. By
        // keeping it static, we don't risk going over its min/max width
        // limits. And by only varying one dimension (height) if multiple Mod2
        // offset adjustments are needed, then we'll properly get a steadily
        // increasing/decreasing aspect ratio (moving towards the target ratio).
        if (!$this->_isNumberMod2($mod2Width)) {
            // Always prefer cutting an extra pixel, rather than stretching
            // by +1. But use +1 if cutting would take us below minimum width.
            // NOTE: Another IMPORTANT reason to CUT width rather than extend
            // is because in narrow cases (canvas close to original input size),
            // the extra width proportionally increases total area (thus height
            // too), and gives us less of the original pixels on the height-axis
            // to play with when attempting to fix the height (and its ratio).
            $mod2Width += ($canCutWidth ? -1 : 1);
            $this->_debugDimensions($mod2Width, $mod2Height, 'MOD2_CANVAS: Width Mod2Fix');

            // Calculate the new relative height based on the new width.
            $mod2Height = $this->_accurateHeightRecalc($useFloorHeightRecalc, $targetAspectRatio, $mod2Width);
            $this->_debugDimensions($mod2Width, $mod2Height, 'MOD2_CANVAS: Height Recalc From Width & Aspect');
        }

        // Ensure that the calculated height is also Mod2, but totally ignore
        // the aspect ratio at this moment (we'll fix that later). Instead,
        // we'll use the same pattern we'd use for width above. That way, if
        // both width and height were uneven, they both get adjusted equally.
        if (!$this->_isNumberMod2($mod2Height)) {
            $mod2Height += ($canCutWidth ? -1 : 1);
            $this->_debugDimensions($mod2Width, $mod2Height, 'MOD2_CANVAS: Height Mod2Fix');
        }

        // We will now analyze multiple different height alternatives to find
        // which one gives us the best visual quality. This algorithm looks
        // for the best qualities (with the most pixel area) first. It first
        // tries the current height (offset 0, which is the closest to the
        // pre-Mod2 adjusted canvas), then +2 pixels (gives more pixel area if
        // this is possible), then -2 pixels (cuts but may be our only choice).
        // After that, it checks 4, -4, 6 and -6 as well.
        // NOTE: Every increased offset (+/-2, then +/-4, then +/- 6) USUALLY
        // (but not always) causes more and more deviation from the intended
        // cropping aspect ratio. So don't add any more steps after 6, since
        // NOTHING will be THAT far off! Six was chosen as a good balance.
        // NOTE: Every offset is checked for visual stretching and aspect ratio,
        // and then rated into one of 3 categories: "perfect" (legal aspect
        // ratio, no stretching), "stretch" (legal aspect ratio, but stretches),
        // or "bad" (illegal aspect ratio).
        $heightAlternatives = ['perfect' => [], 'stretch' => [], 'bad' => []];
        static $offsetPriorities = [0, 2, -2, 4, -4, 6, -6];
        foreach ($offsetPriorities as $offset) {
            // Calculate the new height and its resulting aspect ratio.
            $offsetMod2Height = $mod2Height + $offset;
            $offsetMod2AspectRatio = $mod2Width / $offsetMod2Height;

            // Check if the aspect ratio is legal.
            $isLegalRatio = (($minAspectRatio === null || $offsetMod2AspectRatio >= $minAspectRatio)
                             && ($maxAspectRatio === null || $offsetMod2AspectRatio <= $maxAspectRatio));

            // Detect whether the height would need stretching. Stretching is
            // defined as "not enough pixels in the input media to reach".
            // NOTE: If the input media has been upscaled (such as a 64x64 image
            // being turned into 320x320), then we will ALWAYS detect that media
            // as needing stretching. That's intentional and correct, because
            // such media will INDEED need stretching, so there's never going to
            // be a perfect rating for it (where aspect ratio is legal AND zero
            // stretching is needed to reach those dimensions).
            // NOTE: The max() gets rid of negative values (cropping).
            $stretchAmount = max(0, $offsetMod2Height - $inputHeight);

            // Calculate the deviation from the target aspect ratio. The larger
            // this number is, the further away from "the ideal canvas". The
            // "perfect" answers will always deviate by different amount, and
            // the most perfect one is the one with least deviation.
            $ratioDeviation = abs($offsetMod2AspectRatio - $targetAspectRatio);

            // Rate this height alternative and store it according to rating.
            $rating = ($isLegalRatio && !$stretchAmount ? 'perfect' : ($isLegalRatio ? 'stretch' : 'bad'));
            $heightAlternatives[$rating][] = [
                'offset'         => $offset,
                'height'         => $offsetMod2Height,
                'ratio'          => $offsetMod2AspectRatio,
                'isLegalRatio'   => $isLegalRatio,
                'stretchAmount'  => $stretchAmount,
                'ratioDeviation' => $ratioDeviation,
                'rating'         => $rating,
            ];
            $this->_debugDimensions($mod2Width, $offsetMod2Height, sprintf(
                'MOD2_CANVAS_CHECK: Testing Height Mod2Ratio (h%s%s = %s)',
                ($offset >= 0 ? '+' : ''), $offset, $rating)
            );
        }

        // Now pick the BEST height from our available choices (if any). We will
        // pick the LEGAL height that has the LEAST amount of deviation from the
        // ideal aspect ratio. In other words, the BEST-LOOKING aspect ratio!
        // NOTE: If we find no legal (perfect or stretch) choices, we'll pick
        // the most accurate (least deviation from ratio) of the bad choices.
        $bestHeight = null;
        foreach (['perfect', 'stretch', 'bad'] as $rating) {
            if (!empty($heightAlternatives[$rating])) {
                // Sort all alternatives by their amount of ratio deviation.
                usort($heightAlternatives[$rating], function ($a, $b) {
                    return ($a['ratioDeviation'] < $b['ratioDeviation'])
                        ? -1 : (($a['ratioDeviation'] > $b['ratioDeviation']) ? 1 : 0);
                });

                // Pick the 1st array element, which has the least deviation!
                $bestHeight = $heightAlternatives[$rating][0];
                break;
            }
        }

        // Process and apply the best-possible height we found.
        $mod2Height = $bestHeight['height'];
        $this->_debugDimensions($mod2Width, $mod2Height, sprintf(
            'MOD2_CANVAS: Selected Most Ideal Height Mod2Ratio (h%s%s = %s)',
            ($bestHeight['offset'] >= 0 ? '+' : ''), $bestHeight['offset'], $bestHeight['rating']
        ));

        // Decide what to do if there were no legal aspect ratios among our
        // calculated choices. This can happen if the user gave us an insanely
        // narrow range (such as "min/max ratio 1.6578" or whatever).
        if ($bestHeight['rating'] === 'bad') {
            if (!$allowNewAspectDeviation) {
                throw new \RuntimeException(sprintf(
                    'Canvas calculation failed. Unable to reach target aspect ratio range during Mod2 canvas conversion. The range of allowed aspect ratios is too narrow (%.8f - %.8f). We achieved a ratio of %.8f.',
                    $minAspectRatio !== null ? $minAspectRatio : 0.0,
                    $maxAspectRatio !== null ? $maxAspectRatio : INF,
                    $mod2Width / $mod2Height
                ));
            } else {
                // They WANT us to allow "near-misses", so we'll KEEP our best
                // possible bad ratio here (the one that was closest to the
                // target). We didn't find any more ideal aspect ratio (since
                // all other attempts ALSO FAILED the aspect ratio ranges), so
                // we have NO idea if they'd prefer any others! ;-)
                $this->_debugDimensions($mod2Width, $mod2Height, sprintf(
                    'MOD2_CANVAS: Allowing Deviating Height Mod2Ratio (h%s%s = %s)',
                    ($bestHeight['offset'] >= 0 ? '+' : ''), $bestHeight['offset'], $bestHeight['rating']
                ));
            }
        }

        return new Dimensions($mod2Width, $mod2Height);
    }

    /**
     * Checks whether a number is Mod2.
     *
     * @param int|float $number
     *
     * @return bool
     */
    protected function _isNumberMod2(
        $number)
    {
        // NOTE: The modulo operator correctly returns ints even for float input such as 1.999.
        return $number % 2 === 0;
    }

    /**
     * Output debug text.
     *
     * @param string $stepDescription
     * @param string $formatMessage
     * @param mixed  $args,...
     */
    protected function _debugText(
        $stepDescription,
        $formatMessage,
        ...$args)
    {
        if (!$this->_debug) {
            return;
        }

        printf(
            "[\033[1;33m%s\033[0m] {$formatMessage}\n",
            $stepDescription,
            ...$args
        );
    }

    /**
     * Debug current calculation dimensions and their ratio.
     *
     * @param int|float   $width
     * @param int|float   $height
     * @param string|null $stepDescription
     */
    protected function _debugDimensions(
        $width,
        $height,
        $stepDescription = null)
    {
        if (!$this->_debug) {
            return;
        }

        printf(
            // NOTE: This uses 8 decimals for proper debugging, since small
            // rounding errors can make rejected ratios look valid.
            "[\033[1;33m%s\033[0m] w=%s h=%s (aspect %.8f)\n",
            $stepDescription !== null ? $stepDescription : 'DEBUG',
            $width, $height, $width / $height
        );
    }
}
