<?php

/*
    FrontAccounting's connector for this module. FrontAccounting includes it for
    every installed extension on each page, so it declares the hooks class and
    does nothing else. It is FrontAccounting-style by contract (a global,
    snake_case hooks_<module> class), which phpcs.xml allows for this file only.

    The MCP server is reached by URL (modules/mcp/), not through FrontAccounting's
    menu. Who may use it is the GraphQL module's SA_GRAPHQL: this module adds no
    security area of its own.
*/

class hooks_mcp extends hooks
{
    public $module_name = 'mcp';

    /*
        Refuses unless the GraphQL module is active for the company with an
        Executor of contract 1.x (spec §3.2). The OAuth tables live in company 0
        whichever company is being activated (spec §9).
    */
    public function activate_extension($company, $check_only = true)
    {
        $graphql = $this->graphql_problem($company);
        if ($graphql !== null) {
            display_error($graphql);
            return false;
        }

        $updates = array(
            'update_1.0.sql' => array('mcp_oauth_client'),
        );

        return $this->update_databases(0, $updates, $check_only);
    }

    /*
        Why the GraphQL module cannot serve this one for $company, or null. Reads
        the company's installed_extensions.php itself: the activation form may be
        working on another company's list than the one loaded. graphql's own
        hooks.php, included by FrontAccounting while graphql is installed, loads
        its autoloader, which is what makes its Executor class visible here.
    */
    private function graphql_problem($company)
    {
        $active = false;
        foreach ($this->installed_extensions($company) as $ext) {
            if (isset($ext['package']) && $ext['package'] === 'graphql' && !empty($ext['active'])) {
                $active = true;
            }
        }
        if (!$active) {
            return _('The MCP module needs the GraphQL module: activate GraphQL for this company first.');
        }
        if (
            !class_exists('FA\GraphQL\Api\Executor')
            || (int) explode('.', \FA\GraphQL\Api\Executor::CONTRACT_VERSION)[0] !== 1
        ) {
            return _('The MCP module needs a GraphQL module with Executor contract 1.x: update the GraphQL module.');
        }
        return null;
    }

    /**
     * The company's installed_extensions.php, as admin/inst_module.php's
     * get_company_extensions() reads it; empty when there is none.
     *
     * @return array<int, array<string, mixed>>
     */
    private function installed_extensions($company)
    {
        $installed_extensions = array();
        $file = $GLOBALS['path_to_root'] . '/company/' . (int) $company . '/installed_extensions.php';
        if (is_file($file)) {
            include $file;
        }
        return $installed_extensions;
    }
}
