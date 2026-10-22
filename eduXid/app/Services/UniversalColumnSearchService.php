<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

final class UniversalColumnSearchService
{
    /**
     * Terapkan pencarian ke seluruh kolom aktual dari tabel yang diberikan.
     *
     * Kolom dibaca dari schema pada runtime agar perubahan struktur Dapodik
     * berikutnya otomatis ikut tercakup tanpa daftar kolom hard-code.
     */
    public function apply(
        Builder $query,
        mixed $search,
        array $tables
    ): void {
        $term = trim((string) $search);

        if ($term === '') {
            return;
        }

        $terms = $this->expandedTerms($term);
        $columns = $this->qualifiedColumns($tables);

        if ($columns === []) {
            return;
        }

        $query->where(function (Builder $where) use ($columns, $terms) {
            $first = true;

            foreach ($terms as $termValue) {
                $like = '%' . $this->escapeLike($termValue) . '%';

                foreach ($columns as $column) {
                    $method = $first ? 'where' : 'orWhere';

                    $where->{$method}(
                        $column,
                        'like',
                        $like
                    );

                    $first = false;
                }
            }
        });
    }

    /**
     * Daftar kolom yang aman dan benar-benar ada pada DB.
     */
    public function qualifiedColumns(array $tables): array
    {
        $result = [];

        foreach ($tables as $table) {
            $table = trim((string) $table);

            if ($table === '' || !Schema::hasTable($table)) {
                continue;
            }

            foreach (Schema::getColumnListing($table) as $column) {
                $column = trim((string) $column);

                if ($column === '') {
                    continue;
                }

                $result[] = $table . '.' . $column;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Alias untuk nilai yang di UI ditampilkan dalam bentuk manusiawi.
     * Contoh: DB menyimpan L/P tetapi pengguna mengetik Laki-laki/Perempuan.
     */
    private function expandedTerms(string $term): array
    {
        $terms = [$term];
        $normalized = mb_strtolower(trim($term), 'UTF-8');

        $aliases = [
            'laki-laki' => 'L',
            'laki laki' => 'L',
            'pria' => 'L',
            'perempuan' => 'P',
            'wanita' => 'P',
        ];

        if (array_key_exists($normalized, $aliases)) {
            $terms[] = $aliases[$normalized];
        }

        return array_values(array_unique($terms));
    }

    private function escapeLike(string $value): string
    {
        /*
         * Backslash dipakai sebagai escape karakter wildcard umum.
         * SQLite/MySQL tetap aman dengan binding Query Builder.
         */
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );
    }
}
