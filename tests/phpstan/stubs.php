<?php
/**
 * Stand-ins for the WHMCS functions and classes the module calls, so PHPStan can
 * analyse the module without a WHMCS installation. Signatures follow WHMCS docs.
 */

namespace {
    define('WHMCS', true);
    require __DIR__ . '/../vendor/autoload.php';
    class_alias(\Illuminate\Database\Capsule\Manager::class, 'WHMCS\Database\Capsule');

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    function localAPI(string $command, array $data = [], $adminUser = null): array
    {
        return [];
    }

    function logActivity(string $message, int $userId = 0): void
    {
    }

    /**
     * @param mixed $request
     * @param mixed $response
     * @param mixed $processed
     * @param array<int, string> $replaceVars
     */
    function logModuleCall(string $module, string $action, $request, $response, $processed = '', array $replaceVars = []): void
    {
    }

    function generate_token(string $type = 'form'): string
    {
        return '';
    }

    /** @return array<string, mixed> */
    function getCurrency($userId = '', $currencyId = ''): array
    {
        return [];
    }

    /** @return mixed */
    function formatCurrency($amount, $currencyId = null)
    {
        return '';
    }

    function decrypt(string $value): string
    {
        return $value;
    }

    function add_hook(string $hookName, int $priority, callable $function): void
    {
    }
}

namespace WHMCS {
    class Session
    {
        /** @return mixed */
        public static function get(string $key)
        {
            return null;
        }
    }

    class Smarty extends \Smarty
    {
    }
}
