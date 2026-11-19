<?php
/**
 * VGT Omega deterministic JSON canonicalisation.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package VGT\Omega
 */

declare(strict_types=1);

namespace VGT\Omega\System\Vault\Crypto;

/**
 * Recursively key-sorted JSON encoding used for Ed25519 signature payloads.
 *
 * Signer and verifier must produce byte-identical JSON. Lists keep their order;
 * object keys are sorted at every nesting level.
 */
final class CanonicalJson
{
    public static function encode(array $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }

        ksort($value);

        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = self::canonicalize($item);
        }

        return $result;
    }
}
