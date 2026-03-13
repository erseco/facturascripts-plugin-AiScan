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
use FacturaScripts\Dinamic\Model\Proveedor;

class SupplierMatcher
{
    // Legal form suffixes to strip for normalized name matching
    private const LEGAL_FORM_PATTERN =
        '/\s*\b(S\.?R\.?L\.?|S\.?L\.?U\.?|S\.?A\.?U\.?'
        . '|S\.?L\.?L\.?|S\.?L\.?|S\.?A\.?|S\.?C\.?'
        . '|L\.?T\.?D\.?|L\.?L\.?C\.?|I\.?N\.?C\.?'
        . '|LIMITED|CORPORATION|GMBH|SARL)\.?\b\s*/iu';
    private const AUTO_MATCH_MIN_SCORE = 140;
    private const AMBIGUOUS_MIN_SCORE = 60;
    private const SCORE_DELTA = 15;

    public function findMatch(array $supplierData): array
    {
        $result = [
            'match_status' => 'not_found',
            'supplier' => null,
            'candidates' => [],
        ];

        $normalizedName = $this->normalizeName((string) ($supplierData['name'] ?? ''));
        $normalizedTaxId = $this->normalizeTaxId((string) ($supplierData['tax_id'] ?? ''));
        if ($normalizedName === '' && $normalizedTaxId === '') {
            return $result;
        }

        if ($normalizedTaxId !== '') {
            $match = $this->buildMatchResult(
                $this->rankCandidates($this->findSuppliersByTaxId((string) ($supplierData['tax_id'] ?? '')), $normalizedName, $normalizedTaxId)
            );
            if ($match['match_status'] !== 'not_found') {
                return $match;
            }
        }

        if ($normalizedName !== '') {
            return $this->buildMatchResult(
                $this->rankCandidates($this->findSuppliersByName((string) ($supplierData['name'] ?? '')), $normalizedName, $normalizedTaxId)
            );
        }

        return $result;
    }

    public function search(string $query, int $limit = 20): array
    {
        $normalizedName = $this->normalizeName($query);
        $normalizedTaxId = $this->normalizeTaxId($query);
        if ($normalizedName === '' && $normalizedTaxId === '') {
            return [];
        }

        $candidates = [];
        if ($normalizedTaxId !== '') {
            $candidates = array_merge($candidates, $this->findSuppliersByTaxId($query, $limit));
        }
        if ($normalizedName !== '') {
            $candidates = array_merge($candidates, $this->findSuppliersByName($query, $limit));
        }

        $ranked = $this->rankCandidates($candidates, $normalizedName, $normalizedTaxId);
        return array_map(
            static fn (array $item) => $item['supplier'],
            array_slice($ranked, 0, $limit)
        );
    }

    protected function findSuppliersByTaxId(string $taxId, int $limit = 20): array
    {
        $candidates = [];
        foreach ($this->buildTaxIdTerms($taxId) as $term) {
            if (strlen($term) < 3) {
                continue;
            }

            $candidates = array_merge(
                $candidates,
                $this->loadSuppliers([new Where('cifnif', '=', $term)], [], $limit),
                $this->loadSuppliers([new Where('cifnif', 'LIKE', '%' . $term . '%')], [], $limit)
            );
        }

        return $this->uniqueSuppliers($candidates);
    }

    protected function findSuppliersByName(string $name, int $limit = 20): array
    {
        $candidates = [];
        foreach ($this->buildNameTerms($name) as $term) {
            if (mb_strlen($term) < 2) {
                continue;
            }

            $candidates = array_merge(
                $candidates,
                $this->loadSuppliers([new Where('nombre', 'LIKE', '%' . $term . '%')], ['nombre' => 'ASC'], $limit)
            );
        }

        return $this->uniqueSuppliers($candidates);
    }

    protected function loadSuppliers(array $where, array $orderBy = [], int $limit = 20): array
    {
        $supplier = new Proveedor();
        return $supplier->all($where, $orderBy, 0, $limit);
    }

    private function normalizeName(string $name): string
    {
        $name = $this->foldText($name);
        $name = preg_replace(self::LEGAL_FORM_PATTERN, ' ', $name);
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);
        return $this->collapseWhitespace(mb_strtolower((string) $name, 'UTF-8'));
    }

    private function normalizeTaxId(string $taxId): string
    {
        $taxId = mb_strtoupper($this->foldText($taxId), 'UTF-8');
        return preg_replace('/[^A-Z0-9]/', '', $taxId) ?? '';
    }

    private function buildMatchResult(array $rankedCandidates): array
    {
        $result = [
            'match_status' => 'not_found',
            'supplier' => null,
            'candidates' => [],
        ];

        if (empty($rankedCandidates)) {
            return $result;
        }

        $topCandidate = $rankedCandidates[0];
        $secondScore = $rankedCandidates[1]['score'] ?? 0;
        if ($topCandidate['score'] >= self::AUTO_MATCH_MIN_SCORE
            && ($topCandidate['score'] - $secondScore) >= self::SCORE_DELTA) {
            $result['match_status'] = 'matched';
            $result['supplier'] = $topCandidate['supplier'];
            return $result;
        }

        if ($topCandidate['score'] < self::AMBIGUOUS_MIN_SCORE) {
            return $result;
        }

        $result['match_status'] = 'ambiguous';
        $result['candidates'] = array_map(
            static fn (array $item) => $item['supplier'],
            array_filter(
                $rankedCandidates,
                static fn (array $item) => $item['score'] >= max(
                    self::AMBIGUOUS_MIN_SCORE,
                    $topCandidate['score'] - self::SCORE_DELTA
                )
            )
        );
        return $result;
    }

    private function rankCandidates(array $candidates, string $normalizedName, string $normalizedTaxId): array
    {
        $ranked = [];
        foreach ($this->uniqueSuppliers($candidates) as $supplier) {
            $score = $this->scoreCandidate($supplier, $normalizedName, $normalizedTaxId);
            if ($score <= 0) {
                continue;
            }

            $ranked[] = [
                'supplier' => $supplier,
                'score' => $score,
            ];
        }

        usort($ranked, function (array $left, array $right): int {
            if ($left['score'] === $right['score']) {
                return strcmp(
                    $this->normalizeName((string) ($left['supplier']->nombre ?? '')),
                    $this->normalizeName((string) ($right['supplier']->nombre ?? ''))
                );
            }

            return $right['score'] <=> $left['score'];
        });

        return $ranked;
    }

    private function scoreCandidate(object $supplier, string $normalizedName, string $normalizedTaxId): int
    {
        $score = 0;
        $candidateName = $this->normalizeName((string) ($supplier->nombre ?? ''));
        $candidateTaxId = $this->normalizeTaxId((string) ($supplier->cifnif ?? ''));

        if ($normalizedTaxId !== '' && $candidateTaxId !== '') {
            if ($candidateTaxId === $normalizedTaxId) {
                $score += 220;
            } elseif ($this->stripTaxCountryPrefix($candidateTaxId) === $this->stripTaxCountryPrefix($normalizedTaxId)) {
                $score += 200;
            } elseif ($this->digitsOnly($candidateTaxId) !== ''
                && $this->digitsOnly($candidateTaxId) === $this->digitsOnly($normalizedTaxId)) {
                $score += 160;
            } elseif (str_contains($candidateTaxId, $normalizedTaxId) || str_contains($normalizedTaxId, $candidateTaxId)) {
                $score += 90;
            }
        }

        if ($normalizedName !== '' && $candidateName !== '') {
            if ($candidateName === $normalizedName) {
                $score += 140;
            } elseif (str_starts_with($candidateName, $normalizedName) || str_starts_with($normalizedName, $candidateName)) {
                $score += 100;
            } elseif (str_contains($candidateName, $normalizedName) || str_contains($normalizedName, $candidateName)) {
                $score += 70;
            }

            $score += 10 * $this->sharedTokenCount($candidateName, $normalizedName);
        }

        return $score;
    }

    private function buildTaxIdTerms(string $taxId): array
    {
        $normalized = $this->normalizeTaxId($taxId);
        return array_values(array_filter(array_unique([
            trim($taxId),
            mb_strtoupper(trim($taxId), 'UTF-8'),
            $normalized,
            $this->stripTaxCountryPrefix($normalized),
            $this->digitsOnly($normalized),
        ])));
    }

    private function buildNameTerms(string $name): array
    {
        $normalized = $this->normalizeName($name);
        $folded = $this->collapseWhitespace($this->foldText(trim($name)));
        return array_values(array_filter(array_unique([
            trim($name),
            $folded,
            $normalized,
        ])));
    }

    private function uniqueSuppliers(array $suppliers): array
    {
        $unique = [];
        foreach ($suppliers as $supplier) {
            $key = isset($supplier->codproveedor) ? (string) $supplier->codproveedor : spl_object_hash($supplier);
            $unique[$key] = $supplier;
        }

        return array_values($unique);
    }

    private function sharedTokenCount(string $left, string $right): int
    {
        $leftTokens = array_filter(explode(' ', $left));
        $rightTokens = array_filter(explode(' ', $right));
        return count(array_intersect($leftTokens, $rightTokens));
    }

    private function stripTaxCountryPrefix(string $taxId): string
    {
        return preg_replace('/^[A-Z]{2}(?=[A-Z0-9]{6,})/', '', $taxId) ?? '';
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function collapseWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function foldText(string $value): string
    {
        if (function_exists('transliterator_transliterate')) {
            $folded = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $value);
            if (is_string($folded)) {
                return $folded;
            }
        }

        $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return is_string($folded) ? $folded : $value;
    }
}
