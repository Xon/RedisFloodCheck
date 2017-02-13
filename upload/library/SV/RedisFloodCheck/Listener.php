<?php

class SV_RedisFloodCheck_Listener
{
    public static function load_class($class, array &$extend)
    {
        $extend[] = 'SV_RedisFloodCheck_'.$class;
    }
}