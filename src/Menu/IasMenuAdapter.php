<?php

namespace Sd1\IamSsoClient\Menu;

use stdClass;

/**
 * IAS lama merender navbar dengan melooping Session::get('menu') sebagai
 * daftar DATAR berisi objek {acc_group, acc_subgroup1, acc_subgroup2,
 * acc_subgroup3, acc_name, acc_url}, sudah terurut, dan bergantung pada
 * PERUBAHAN nilai antar baris untuk tahu kapan membuka/menutup <ul> baru
 * (lihat resources/views/layouts/app.blade.php pada bagian navbar).
 *
 * OMI-IAM justru mengembalikan pohon menu (menus[].children[]). Kelas ini
 * meratakannya kembali ke bentuk lama supaya Blade navbar IAS BISA DIPAKAI
 * TANPA DIUBAH sama sekali selama migrasi — cukup ganti sumber
 * `Session::get('menu')` menjadi `Iam::user()->menusAsLegacyIasFlat()`.
 *
 * Menu diimpor dari `tbmaster_access_migrasi` (lihat MenuMappingPresets
 * preset "ias" di OMI-IAM) sehingga struktur groupnya memang dibentuk
 * persis dari acc_group/acc_subgroup1..3, jadi flatten ini adalah
 * kebalikan yang tepat dari proses importnya.
 */
class IasMenuAdapter
{
    /**
     * @param array $tree Hasil Iam::menus() / IamUser::menus() — pohon menu.
     * @return stdClass[] Daftar datar siap di-loop persis seperti session('menu') lama.
     */
    public function flatten(array $tree): array
    {
        $rows = [];
        $this->walk($tree, [], $rows);

        return $rows;
    }

    /**
     * @param array $nodes    Anak-anak menu pada level ini.
     * @param array $ancestry Label GROUP/MODULE leluhur, terurut dari terluar.
     * @param array $rows     Akumulator hasil (dilewatkan by reference).
     */
    protected function walk(array $nodes, array $ancestry, array &$rows): void
    {
        foreach ($nodes as $node) {
            $hasUrl = ! empty($node['path']);
            $children = $node['children'] ?? [];

            if ($hasUrl) {
                $rows[] = $this->toRow($ancestry, $node);
            }

            if (! empty($children)) {
                // Node ini jadi label group untuk level di bawahnya, baik dia
                // sendiri punya URL (MODULE dengan halaman + anak) atau tidak
                // (GROUP murni).
                $this->walk($children, array_merge($ancestry, [$node['name']]), $rows);
            }
        }
    }

    protected function toRow(array $ancestry, array $leaf): stdClass
    {
        $row = new stdClass();
        $row->acc_group = $ancestry[0] ?? null;
        $row->acc_subgroup1 = $ancestry[1] ?? null;
        $row->acc_subgroup2 = $ancestry[2] ?? null;
        // Leluhur ke-4 dan seterusnya (jarang terjadi di data IAS) digabung
        // ke acc_subgroup3 supaya tidak ada label yang hilang.
        $row->acc_subgroup3 = isset($ancestry[3]) ? implode(' / ', array_slice($ancestry, 3)) : null;
        $row->acc_name = $leaf['name'] ?? null;
        $row->acc_url = $leaf['path'] ?? null;
        $row->acc_id = $leaf['metadata']['acc_id'] ?? $leaf['code'] ?? null;
        $row->code = $leaf['code'] ?? null;
        $row->actions = $leaf['actions'] ?? [];

        return $row;
    }

    /**
     * Daftar seluruh path menu yang boleh dilihat user (leaf saja), untuk
     * dipakai menggantikan pengecekan `AccessController::isAccessible()`
     * berbasis tabel `tbmaster_access` di IAS lama.
     *
     * @param array $tree
     * @return string[]
     */
    public function allowedPaths(array $tree): array
    {
        $paths = [];
        $this->collectPaths($tree, $paths);

        return $paths;
    }

    protected function collectPaths(array $nodes, array &$paths): void
    {
        foreach ($nodes as $node) {
            if (! empty($node['path'])) {
                $paths[] = rtrim($node['path'], '/');
            }

            if (! empty($node['children'])) {
                $this->collectPaths($node['children'], $paths);
            }
        }
    }
}
