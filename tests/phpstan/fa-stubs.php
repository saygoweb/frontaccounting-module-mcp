<?php

/*
    The FrontAccounting symbols hooks.php uses, for phpstan only (never loaded):
    includes/hooks.inc's base class and includes/ui/ui_msgs.inc's display_error()
    and display_warning().
    FrontAccounting is not beside this module outside its CI image.
*/

class hooks
{
    /** @var string */
    public $module_name;

    /**
     * @param int $comp
     * @param array<string, array<int, string>> $updates
     * @param bool $check_only
     * @return bool
     */
    public function update_databases($comp, $updates, $check_only = false)
    {
        return true;
    }
}

/**
 * @param string $msg
 * @param bool $center
 * @return void
 */
function display_error($msg, $center = true)
{
}

/**
 * @param string $msg
 * @param bool $center
 * @return void
 */
function display_warning($msg, $center = true)
{
}

/**
 * @return array<int, array<string, mixed>>
 */
function get_company_extensions()
{
    return array();
}

/**
 * @param array<int, array<string, mixed>> $exts
 * @return bool
 */
function update_extensions($exts)
{
    return true;
}
