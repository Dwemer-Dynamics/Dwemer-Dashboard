<?php

if (!function_exists('dashboardCompareFileModificationDate')) {
    function dashboardCompareFileModificationDate(string $a, string $b): int
    {
        return filemtime($b) - filemtime($a);
    }
}

if (!function_exists('dashboardBootstrapHerikaProfile')) {
    function dashboardBootstrapHerikaProfile(string $herikaRoot): void
    {
        $rootPath = rtrim($herikaRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $configFilepath = $rootPath . 'conf' . DIRECTORY_SEPARATOR;
        $promoteConfigFile = static function (string $filePath, array $skipNames = []): void {
            if (!file_exists($filePath)) {
                return;
            }

            $loadedVars = (static function (string $__dashboardConfigFile): array {
                include $__dashboardConfigFile;
                $vars = get_defined_vars();
                unset($vars['__dashboardConfigFile']);
                return $vars;
            })($filePath);

            foreach ($loadedVars as $name => $value) {
                if (in_array($name, $skipNames, true)) {
                    continue;
                }
                $GLOBALS[$name] = $value;
            }
        };

        $modelDynPath = $rootPath . 'lib' . DIRECTORY_SEPARATOR . 'model_dynmodel.php';
        if (file_exists($modelDynPath)) {
            require_once($modelDynPath);
        }

        $confSamplePath = $configFilepath . 'conf.sample.php';
        $promoteConfigFile($confSamplePath, ['rootPath', 'configFilepath', 'promoteConfigFile', 'modelDynPath', 'confSamplePath', 'confPath', 'confLoaderPath', 'profiles', 'defaultProfile']);

        $confPath = $configFilepath . 'conf.php';
        $promoteConfigFile($confPath, ['rootPath', 'configFilepath', 'promoteConfigFile', 'modelDynPath', 'confSamplePath', 'confPath', 'confLoaderPath', 'profiles', 'defaultProfile']);

        $confLoaderPath = $configFilepath . 'conf_loader.php';
        if (file_exists($confLoaderPath)) {
            require_once($confLoaderPath);
        }

        $profiles = [];
        foreach (glob($configFilepath . 'conf_????????????????????????????????.php') as $profilePath) {
            if (file_exists($profilePath)) {
                $profiles[] = $profilePath;
            }
        }

        usort($profiles, 'dashboardCompareFileModificationDate');
        $defaultProfile = $confPath;
        $GLOBALS['PROFILES'] = array_merge(['default' => $defaultProfile], $profiles);

        if (
            isset($_SESSION['PROFILE']) &&
            is_string($_SESSION['PROFILE']) &&
            in_array($_SESSION['PROFILE'], $GLOBALS['PROFILES'], true)
        ) {
            $promoteConfigFile($_SESSION['PROFILE'], ['rootPath', 'configFilepath', 'promoteConfigFile', 'modelDynPath', 'confSamplePath', 'confPath', 'confLoaderPath', 'profiles', 'defaultProfile']);
            return;
        }

        $_SESSION['PROFILE'] = $defaultProfile;
        $promoteConfigFile($defaultProfile, ['rootPath', 'configFilepath', 'promoteConfigFile', 'modelDynPath', 'confSamplePath', 'confPath', 'confLoaderPath', 'profiles', 'defaultProfile']);
    }
}
