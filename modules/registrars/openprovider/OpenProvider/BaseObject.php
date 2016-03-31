<?php

namespace OpenProvider;

class BaseObject
{
    public function toArray()
    {
        return json_decode(json_encode($object), true);
    }
}