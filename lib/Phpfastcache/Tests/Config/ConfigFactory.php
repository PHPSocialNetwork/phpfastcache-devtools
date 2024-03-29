<?php

/**
 *
 * This file is part of Phpfastcache.
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt and LICENCE files.
 *
 * @author Georges.L (Geolim4)  <contact@geolim4.com>
 * @author Contributors  https://github.com/PHPSocialNetwork/phpfastcache/graphs/contributors
 */
declare(strict_types=1);

namespace Phpfastcache\Tests\Config;


use Phpfastcache\Config\ConfigurationOptionInterface;
use Phpfastcache\Drivers\Rediscluster\Config as RedisClusterConfig;
use Phpfastcache\Helper\UninstanciableObjectTrait;

class ConfigFactory
{
    use UninstanciableObjectTrait;

    static public function getDefaultConfig(string $driverName): ?ConfigurationOptionInterface
    {
        return self::getDefaultConfigs()[$driverName] ?? null;
    }

    /**
     * @return array<string, ConfigurationOptionInterface>
     */
    static public function getDefaultConfigs(): array
    {
        return [
            'RedisCluster' => (fn(RedisClusterConfig $config) => $config->setItemDetailedDate(true)
                ->setClusters( '127.0.0.1:7001', '127.0.0.1:7002', '127.0.0.1:7003', '127.0.0.1:7004', '127.0.0.1:7005', '127.0.0.1:7006')
                ->setSlaveFailover(\RedisCluster::FAILOVER_ERROR)
                // ->setOptPrefix( 'pfc_')
            )(new RedisClusterConfig()),
        ];
    }
}
