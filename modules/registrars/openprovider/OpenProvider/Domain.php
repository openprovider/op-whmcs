<?php

namespace OpenProvider;

class Domain extends \OpenProvider\AutoloadConstructor
{
    /**
     *
     * @var string
     */
    public $name        =   null;
    
    /**
     *
     * @var string 
     */
    public $extension   =   null;
    
    public function getFullName()
    {
        return $this->name . '.' . $this->extension;
    }
}