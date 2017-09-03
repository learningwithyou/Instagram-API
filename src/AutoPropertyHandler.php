<?php

namespace InstagramAPI;

/**
 * Automatic object property handler.
 *
 * By deriving from this base object, it will automatically create virtual
 * "getX()", "setX()" and "isX()" functions for all of your object's properties.
 *
 * This class is intended to handle Instagram's server responses, so all of your
 * object properties must be named the same way as Instagram's standardized var
 * format, which is "$some_value". That object property can then be magically
 * accessed via "getSomeValue()", "setSomeValue()" and "isSomeValue()".
 *
 * Examples (normal lowercase properties separated by underscores):
 * public $location; = getLocation(); isLocation(); setLocation();
 * public $is_valid; = getIsValid(); isIsValid(); setIsValid();
 *
 * Examples (rare properties with a leading underscore):
 * public $_messages; = get_Messages(); is_Messages(); set_Messages();
 * public $_the_url; = get_TheUrl(); is_TheUrl(); set_TheUrl();
 *
 * Examples (rare camelcase properties):
 * public $iTunesItem; = getITunesItem(); isITunesItem(); setITunesItem();
 * public $linkType; = getLinkType(); isLinkType(); setLinkType();
 *
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 */
class AutoPropertyHandler
{
    /**
     * @var object|null
     */
    protected $_jsonData;

    /**
     * Constructor.
     *
     * @param object|null $jsonData
     */
    public function __construct(
        $jsonData = null)
    {
        $this->_jsonData = $jsonData;
    }
}
