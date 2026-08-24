<?php

namespace App\Support;

use Throwable;

/**
 * Resolves a user's effective capabilities from the legacy WordPress schema.
 *
 * Roles stay in `{prefix}usermeta` and their capability definitions stay in
 * `{prefix}options` (`{prefix}user_roles`), exactly as WordPress stores them.
 */
final class WordpressCapabilityResolver
{
    public function __construct(private readonly WordpressTableResolver $tables)
    {
    }

    /**
     * @return array{roles:list<string>,capabilities:list<string>}
     */
    public function profileForUser(int $userId): array
    {
        if ($userId < 1) {
            return ['roles' => [], 'capabilities' => []];
        }

        $assigned = $this->serializedMap(
            $this->tables->connection()
                ->table($this->tables->table('usermeta'))
                ->where('user_id', $userId)
                ->where('meta_key', $this->tables->capabilitiesMetaKey())
                ->value('meta_value')
        );
        $definitions = $this->roleDefinitions();

        $roles = [];
        $capabilities = [];
        $directCapabilities = [];

        foreach ($assigned as $key => $granted) {
            if (!is_string($key)) {
                continue;
            }

            if (isset($definitions[$key])) {
                if (!$granted) {
                    continue;
                }

                $roles[] = $key;
                foreach ($definitions[$key]['capabilities'] as $capability => $allowed) {
                    if (is_string($capability) && $allowed) {
                        $capabilities[$capability] = true;
                    }
                }

                continue;
            }

            // WordPress permits individual capability overrides alongside roles.
            $directCapabilities[$key] = $granted;
        }

        foreach ($directCapabilities as $capability => $allowed) {
            if ($allowed) {
                $capabilities[$capability] = true;
            } else {
                unset($capabilities[$capability]);
            }
        }

        sort($roles);
        $effectiveCapabilities = array_keys($capabilities);
        sort($effectiveCapabilities);

        return [
            'roles' => array_values(array_unique($roles)),
            'capabilities' => $effectiveCapabilities,
        ];
    }

    /**
     * @return array<string,array{capabilities:array<string,bool>}>
     */
    private function roleDefinitions(): array
    {
        $value = $this->tables->connection()
            ->table($this->tables->table('options'))
            ->where('option_name', $this->tables->prefix() . 'user_roles')
            ->value('option_value');
        $roles = $this->serializedMap($value);
        $definitions = [];

        foreach ($roles as $role => $definition) {
            if (!is_string($role) || !is_array($definition) || !isset($definition['capabilities']) || !is_array($definition['capabilities'])) {
                continue;
            }

            $capabilities = [];
            foreach ($definition['capabilities'] as $capability => $allowed) {
                if (is_string($capability)) {
                    $capabilities[$capability] = (bool) $allowed;
                }
            }

            $definitions[$role] = ['capabilities' => $capabilities];
        }

        return $definitions;
    }

    /**
     * @return array<string,mixed>
     */
    private function serializedMap(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = unserialize($value, ['allowed_classes' => false]);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
