<?php

namespace Sd1\IamSsoClient\Console;

use Illuminate\Console\Command;
use Sd1\IamSsoClient\Exceptions\IamApiException;
use Sd1\IamSsoClient\IamManager;

/**
 * Mendorong daftar menu di config('iam-sso.menus') ke OMI-IAM lewat
 * POST /api/v1/client/menus/sync (scope "menu.sync"). Cocok dijalankan
 * sebagai bagian dari langkah deploy, supaya menu baru yang ditambahkan
 * di kode aplikasi otomatis muncul di OMI-IAM tanpa input manual.
 *
 * Idempotent & non-destruktif di sisi IAM: menu yang tidak disebutkan
 * tidak dihapus, hanya dilaporkan sebagai "missing" untuk ditinjau.
 */
class SyncMenusCommand extends Command
{
    protected $signature = 'iam:sync-menus {--dry-run : Simulasikan saja, jangan simpan perubahan}';

    protected $description = 'Sinkronkan menu aplikasi ini (config/iam-sso.php > menus) ke OMI-IAM';

    public function handle(IamManager $iam)
    {
        $menus = config('iam-sso.menus', []);

        if (empty($menus)) {
            $this->warn('config("iam-sso.menus") kosong — tidak ada yang disinkronkan.');

            return 0;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $iam->syncMenus($menus, $dryRun);
        } catch (IamApiException $e) {
            $this->error('Gagal sinkronisasi menu: ' . $e->getMessage());

            return 1;
        }

        $created = count($result['created'] ?? []);
        $updated = count($result['updated'] ?? []);
        $missing = count($result['missing'] ?? []);
        $conflicts = count($result['conflicts'] ?? []);

        $this->info(($dryRun ? '[dry-run] ' : '') . "Dibuat: {$created}, diperbarui: {$updated}, hilang: {$missing}, konflik: {$conflicts}");

        if ($missing > 0) {
            $this->comment('Menu berikut ada di OMI-IAM tapi tidak disebutkan di config lokal (tidak dihapus otomatis):');
            foreach (($result['missing'] ?? []) as $code) {
                $this->line(' - ' . $code);
            }
        }

        if ($conflicts > 0) {
            $this->comment('Konflik kode menu (tinjau di menu Menus pada OMI-IAM):');
            foreach (($result['conflicts'] ?? []) as $conflict) {
                $this->line(' - ' . json_encode($conflict));
            }
        }

        return 0;
    }
}
