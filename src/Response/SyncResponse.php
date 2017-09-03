<?php

namespace InstagramAPI\Response;

use InstagramAPI\AutoPropertyHandler;
use InstagramAPI\ResponseInterface;
use InstagramAPI\ResponseTrait;

class SyncResponse extends AutoPropertyHandler implements ResponseInterface
{
    use ResponseTrait;

    /**
     * @var Model\Experiment[]
     */
    public $experiments;

    /**
     * @return Model\Experiment[]
     */
    public function getExperiments()
    {
        if ($this->experiments !== null) {
            return $this->experiments;
        } elseif (isset($this->_jsonData->experiments)) {
            if (is_array($this->_jsonData->experiments)) {
                $this->experiments = [];
                foreach ($this->_jsonData->experiments as $idx => $value) {
                    $this->experiments[$idx] = new Model\Experiment($value);
                }
            } else {
                $this->experiments = null;
            }

            return $this->experiments;
        } else {
            return null;
        }
    }

    /**
     * @param Model\Experiment[] $value
     *
     * @return static
     */
    public function setExperiments(
        array $value)
    {
        $this->experiments = $value;
        $this->_jsonData->experiments = $value;

        return $this;
    }

    /**
     * @return bool
     */
    public function isExperiments()
    {
        return isset($this->_jsonData->experiments) && $this->_jsonData->experiments;
    }
}
