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
        whichever company is being activated (spec §9). Warns, without refusing,
        when OAuth is served but FrontAccounting's root .htaccess lacks spec
        §5.1's rule.
    */
    public function activate_extension($company, $check_only = true)
    {
        if (!$check_only) {
            $this->refresh_own_version();
        }
        $graphql = $this->graphql_problem($company);
        if ($graphql !== null) {
            display_error($graphql);
            return false;
        }
        $rewrite = $this->root_rewrite_problem();
        if ($rewrite !== null) {
            display_warning($rewrite);
        }

        $updates = array(
            'update_1.0.sql' => array('mcp_oauth_client'),
        );

        return $this->update_databases(0, $updates, $check_only);
    }

    /*
        Stock FrontAccounting reads modules/<id>/_init/config (written by the
        module's build, docker/ci/fa-module) only when the extension is first
        installed. Refresh the stored version on activation so an upgrade shows
        its version on Install/Activate Extensions.
    */
    public function refresh_own_version()
    {
        global $path_to_root;

        $file = $path_to_root . '/modules/' . $this->module_name . '/_init/config';
        if (!is_file($file)) {
            return;
        }
        $version = null;
        foreach (file($file) as $line) {
            if (preg_match('/^Version:\s*(\S+)/', $line, $m)) {
                $version = $m[1];
            }
        }
        if ($version === null) {
            return;
        }
        $exts = get_company_extensions();
        foreach ($exts as $i => $ext) {
            if (@$ext['package'] === $this->module_name && @$ext['type'] === 'extension') {
                if (@$ext['version'] !== $version) {
                    $exts[$i]['version'] = $version;
                    update_extensions($exts);
                }
                return;
            }
        }
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

    /*
        RootRewriteCheck's problem, or null. This module's autoloader is loaded
        here, after graphql's (graphql_problem() found it), as index.php orders
        them. A config_mcp.php the endpoint would refuse is not this check's to
        report: the endpoint answers it on its first request.
    */
    private function root_rewrite_problem()
    {
        require_once __DIR__ . '/vendor/autoload.php';
        try {
            $config = \FA\Mcp\Config::fromFile(__DIR__ . '/config_mcp.php');
        } catch (\FA\Mcp\ConfigException $e) {
            return null;
        }
        return \FA\Mcp\Http\RootRewriteCheck::problem($config, $GLOBALS['path_to_root'] . '/.htaccess');
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
