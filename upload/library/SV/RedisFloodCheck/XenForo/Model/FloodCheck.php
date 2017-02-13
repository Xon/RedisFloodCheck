<?php

class SV_RedisFloodCheck_XenForo_Model_FloodCheck extends XFCP_SV_RedisFloodCheck_XenForo_Model_FloodCheck
{
    const LUA_SETTTL_SH1 = 'b670e66199af96236f9798dd1152e61c312d4f78';

    protected function getSessionCache()
    {
        $session = null;
        $cache = $this->_getCache(true);
        if (XenForo_Application::isRegistered('session'))
        {
            $session = XenForo_Application::getSession();
        }
        else
        {
            $class = XenForo_Application::resolveDynamicClass('XenForo_Session');
            /** @var $session XenForo_Session */
            $session = new $class();
        }
        if ($session && is_callable(array($session, 'getSessionCache')))
        {
            $cache = $session->getSessionCache();
        }

        return $cache;
    }

    public function checkFloodingInternal($action, $floodingLimit = null, $userId = null)
    {
        if ($userId === null)
        {
            $userId = XenForo_Visitor::getUserId();
        }

        if (!$userId)
        {
            return 0;
        }
        if ($floodingLimit === null)
        {
            $floodingLimit = XenForo_Application::get('options')->floodCheckLength;
        }
        $floodingLimit = intval($floodingLimit);
        if ($floodingLimit <= 0)
        {
            return 0;
        }

        $registry = $this->_getDataRegistryModel();
        $cache = $this->getSessionCache();
        if (!$cache || !method_exists($registry, 'getCredis') || !($credis = $registry->getCredis($cache)))
        {
            return parent::checkFloodingInternal($action, $floodingLimit, $userId);
        }
        $useLua = method_exists($registry, 'useLua') && $registry->useLua($cache);
        $key = Cm_Cache_Backend_Redis::PREFIX_KEY . $cache->getOption('cache_id_prefix') . 'flood.'.strval($action).'.'.strval($userId);

        // the key just needs to exist, not have any value
        if ($useLua)
        {
            $seconds = $credis->evalSha(self::LUA_SETTTL_SH1, array($key), array($floodingLimit));
            if ($seconds === null)
            {
                $script =
                    "if not redis.call('SET', KEYS[1], '', 'NX', 'EX', ARGV[1]) then ".
                        "return redis.call('TTL', KEYS[1]) ".
                    "end ".
                    "return 0 ";
                $seconds = $credis->eval($script, array($key), array($floodingLimit));
            }
            if ($seconds === 0)
            {
                return 0;
            }
        }
        else
        {
            if (!$credis->set($key, '', array('nx', 'ex'=> $floodingLimit)))
            {
                return 0;
            }
            $seconds = $credis->ttl($key);
        }
        // seconds can return negative due to an error, treat that as requiring flooding
        return max(1, $seconds);
    }
}