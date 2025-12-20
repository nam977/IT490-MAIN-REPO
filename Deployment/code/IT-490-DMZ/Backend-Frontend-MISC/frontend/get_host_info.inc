<?php
/**
    @brief gets the currrent machine information and optionally any other task
    specific INI folder installed in the system path.
    Uses the default path of /var/system_ini/ unless $INIPATH is set

    @return parsed ini machine information
*/
function getHostInfo(array $extra = NULL)
{
    $machine = array();
    // parse host.ini only if it exists and is readable; avoid noisy warnings
    if (file_exists(__DIR__ . '/host.ini') && is_readable(__DIR__ . '/host.ini')) {
        $parsed_main = @parse_ini_file(__DIR__ . '/host.ini', true);
        if ($parsed_main) {
            $machine = $parsed_main;
        }
    }
    if ($extra != NULL)
    {
        foreach ($extra as $ini)
        {
            // only attempt to parse explicitly provided INI files if they exist
            if (file_exists($ini) && is_readable($ini)) {
                $parsed = @parse_ini_file($ini, true);
                if ($parsed) {
                    $machine = array_merge($machine, $parsed);
                }
            }
        }
    }
    return $machine;
}

