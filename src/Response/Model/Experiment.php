<?php

namespace InstagramAPI\Response\Model;

use InstagramAPI\AutoPropertyHandler;

class Experiment extends AutoPropertyHandler
{
    /**
     * @var Param[]
     */
    public $params;
    public $group;
    public $name;

    /**
     * @return Param[]
     */
    public function getParams()
    {
        if ($this->params !== null) {
            return $this->params;
        } elseif (isset($this->_jsonData->params)) {
            if (is_array($this->_jsonData->params)) {
                $this->params = [];
                foreach ($this->_jsonData->params as $idx => $value) {
                    $this->params[$idx] = new Param($value);
                }
            } else {
                $this->params = null;
            }

            return $this->params;
        } else {
            return null;
        }
    }

    /**
     * @param Param[] $value
     *
     * @return static
     */
    public function setParams(
        array $value)
    {
        $this->params = $value;
        $this->_jsonData->params = $value;

        return $this;
    }

    /**
     * @return bool
     */
    public function isParams()
    {
        return isset($this->_jsonData->params) && $this->_jsonData->params;
    }

    /**
     * @return mixed
     */
    public function getGroup()
    {
        if ($this->group !== null) {
            return $this->group;
        } elseif (isset($this->_jsonData->group)) {
            $this->group = $this->_jsonData->group;

            return $this->group;
        } else {
            return null;
        }
    }

    /**
     * @param mixed $value
     *
     * @return static
     */
    public function setGroup(
        $value)
    {
        $this->group = $value;
        $this->_jsonData->group = $value;

        return $this;
    }

    /**
     * @return bool
     */
    public function isGroup()
    {
        return isset($this->_jsonData->group) && $this->_jsonData->group;
    }

    /**
     * @return mixed
     */
    public function getName()
    {
        if ($this->name !== null) {
            return $this->name;
        } elseif (isset($this->_jsonData->name)) {
            $this->name = $this->_jsonData->name;

            return $this->name;
        } else {
            return null;
        }
    }

    /**
     * @param mixed $value
     *
     * @return static
     */
    public function setName(
        $value)
    {
        $this->name = $value;
        $this->_jsonData->name = $value;

        return $this;
    }

    /**
     * @return bool
     */
    public function isName()
    {
        return isset($this->_jsonData->name) && $this->_jsonData->name;
    }
}
