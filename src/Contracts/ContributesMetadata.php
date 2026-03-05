<?php

namespace SineMacula\Repositories\Contracts;

/**
 * Supplementary capability contract for criteria that contribute metadata.
 *
 * Criteria implementing this contract can attach key-value metadata alongside
 * query modification. The repository collects this metadata at criteria
 * application time, making it retrievable through the repository's public API.
 *
 * This is an opt-in contract: criteria that only implement CriteriaInterface
 * are unaffected and contribute no metadata.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface ContributesMetadata
{
    /**
     * Return the metadata to contribute.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array;
}
