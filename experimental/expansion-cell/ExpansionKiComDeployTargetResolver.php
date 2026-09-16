<?php
declare(strict_types=1);

/**
 * Adapter from KiCom's existing allowlisted deployment-target registry to the
 * Expansion Cell local-filesystem deployer.
 *
 * The absolute target root is returned only to the in-process caller. Public
 * descriptors deliberately omit it. Expansion v1 accepts only test/staging
 * resources here; production remains outside this adapter's authority.
 */
final class KiComExpansionKiComDeployTargetResolver
{
    public static function available(): bool
    {
        return function_exists('kicomDeployTarget');
    }

    public static function resolve(string $alias): ?string
    {
        $alias=self::normalizeAlias($alias);
        if($alias===null||!self::available()) return null;

        $target=kicomDeployTarget($alias,true);
        if(!is_array($target)) return null;

        $class=strtolower(trim((string)($target['class']??'')));
        if(!in_array($class,['test','staging'],true)) return null;

        $root=(string)($target['root']??'');
        if($root===''||str_contains($root,"\0")) return null;
        $real=realpath($root);
        if($real===false||!is_dir($real)||!is_writable($real)) return null;

        return rtrim(str_replace('\\','/',$real),'/');
    }

    /** @return array<string,mixed>|null */
    public static function publicDescriptor(string $alias): ?array
    {
        $alias=self::normalizeAlias($alias);
        if($alias===null||!self::available()) return null;
        $target=kicomDeployTarget($alias,true);
        if(!is_array($target)) return null;
        $class=strtolower(trim((string)($target['class']??'')));
        if(!in_array($class,['test','staging'],true)) return null;

        return [
            'alias'=>$alias,
            'label'=>(string)($target['label']??$alias),
            'class'=>$class,
            'healthcheck'=>trim((string)($target['health_url']??''))!=='',
            'writable'=>self::resolve($alias)!==null,
        ];
    }

    private static function normalizeAlias(string $alias): ?string
    {
        $alias=strtolower(trim($alias));
        return preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/',$alias)?$alias:null;
    }
}
