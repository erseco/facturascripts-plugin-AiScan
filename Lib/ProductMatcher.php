<?php

/**
 * This file is part of AiScan plugin for FacturaScripts.
 * Copyright (C) 2026 Ernesto Serrano <info@ernesto.es>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\AiScan\Lib;

use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Variante;

class ProductMatcher
{
    private const AUTO_MATCH_MIN_SCORE = 140;
    private const SCORE_DELTA = 15;

    public function findReference(array $lineData): ?string
    {
        $normalizedSku = $this->normalizeCode((string) ($lineData['sku'] ?? ''));
        if ($normalizedSku !== '') {
            $reference = $this->resolveReference(
                $this->rankCandidates($this->findVariantsBySku((string) ($lineData['sku'] ?? '')), '', $normalizedSku)
            );
            if ($reference !== null) {
                return $reference;
            }
        }

        $description = trim((string) ($lineData['description'] ?? ''));
        if (mb_strlen($description) < 4) {
            return null;
        }

        return $this->resolveReference(
            $this->rankCandidates(
                $this->findVariantsByDescription($description),
                $this->normalizeText($description),
                $normalizedSku
            )
        );
    }

    protected function findVariantsBySku(string $sku, int $limit = 20): array
    {
        $candidates = [];
        foreach ($this->buildCodeTerms($sku) as $term) {
            if ($term === '') {
                continue;
            }

            $candidates = array_merge(
                $candidates,
                $this->loadVariants([new Where('referencia', '=', $term)], [], $limit),
                $this->loadVariants([new Where('codbarras', '=', $term)], [], $limit),
                $this->loadVariants([new Where('referencia', 'LIKE', '%' . $term . '%')], ['referencia' => 'ASC'], $limit),
                $this->loadVariants([new Where('codbarras', 'LIKE', '%' . $term . '%')], ['referencia' => 'ASC'], $limit)
            );
        }

        return $this->uniqueCandidates($candidates);
    }

    protected function findVariantsByDescription(string $description): array
    {
        $variant = new Variante();
        $results = $variant->codeModelSearch('%' . mb_strtolower(trim($description), 'UTF-8') . '%', 'referencia');
        return $this->uniqueCandidates($results);
    }

    protected function loadVariants(array $where, array $orderBy = [], int $limit = 20): array
    {
        $variant = new Variante();
        return $variant->all($where, $orderBy, 0, $limit);
    }

    private function rankCandidates(array $candidates, string $normalizedDescription, string $normalizedSku): array
    {
        $ranked = [];
        foreach ($this->uniqueCandidates($candidates) as $candidate) {
            $score = $this->scoreCandidate($candidate, $normalizedDescription, $normalizedSku);
            if ($score <= 0) {
                continue;
            }

            $ranked[] = [
                'reference' => $candidate->code ?? $candidate->referencia ?? null,
                'score' => $score,
            ];
        }

        usort($ranked, static function (array $left, array $right): int {
            if ($left['score'] === $right['score']) {
                return strcmp((string) $left['reference'], (string) $right['reference']);
            }

            return $right['score'] <=> $left['score'];
        });

        return $ranked;
    }

    private function resolveReference(array $rankedCandidates): ?string
    {
        if (empty($rankedCandidates)) {
            return null;
        }

        $topCandidate = $rankedCandidates[0];
        $secondScore = $rankedCandidates[1]['score'] ?? 0;
        if ($topCandidate['score'] < self::AUTO_MATCH_MIN_SCORE
            || ($topCandidate['score'] - $secondScore) < self::SCORE_DELTA) {
            return null;
        }

        return empty($topCandidate['reference']) ? null : (string) $topCandidate['reference'];
    }

    private function scoreCandidate(object $candidate, string $normalizedDescription, string $normalizedSku): int
    {
        $score = 0;
        $reference = $this->normalizeCode((string) ($candidate->code ?? $candidate->referencia ?? ''));
        $barcode = $this->normalizeCode((string) ($candidate->codbarras ?? ''));
        $description = $this->normalizeText((string) ($candidate->description ?? $candidate->descripcion ?? ''));

        if ($normalizedSku !== '') {
            if ($reference === $normalizedSku || $barcode === $normalizedSku) {
                $score += 220;
            } elseif (($reference !== '' && str_contains($reference, $normalizedSku))
                || ($barcode !== '' && str_contains($barcode, $normalizedSku))) {
                $score += 120;
            }
        }

        if ($normalizedDescription !== '' && $description !== '') {
            if ($description === $normalizedDescription) {
                $score += 140;
            } elseif (str_starts_with($description, $normalizedDescription)
                || str_starts_with($normalizedDescription, $description)) {
                $score += 110;
            } elseif (str_contains($description, $normalizedDescription)
                || str_contains($normalizedDescription, $description)) {
                $score += 80;
            }
        }

        return $score;
    }

    private function buildCodeTerms(string $value): array
    {
        $trimmed = trim($value);
        return array_values(array_filter(array_unique([
            $trimmed,
            mb_strtoupper($trimmed, 'UTF-8'),
            $this->normalizeCode($trimmed),
        ])));
    }

    private function normalizeCode(string $value): string
    {
        return preg_replace('/[^A-Z0-9]+/', '', mb_strtoupper(trim($value), 'UTF-8')) ?? '';
    }

    private function normalizeText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return mb_strtolower($value, 'UTF-8');
    }

    private function uniqueCandidates(array $candidates): array
    {
        $unique = [];
        foreach ($candidates as $candidate) {
            $reference = (string) ($candidate->code ?? $candidate->referencia ?? spl_object_hash($candidate));
            $unique[$reference] = $candidate;
        }

        return array_values($unique);
    }
}
