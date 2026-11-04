<?php

namespace App\Console\Commands;

use App\Models\Categories;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckAiLabelsCommand extends Command
{
    protected $signature = 'ai:check-labels';

    protected $description = 'Cek kecocokan label model AI dengan kategori di database';

    public function handle(): int
    {
        $this->info('Memeriksa kontrak label antara AI Microservice dan database...');
        $this->newLine();

        $modelLabels = $this->fetchModelLabels();

        if ($modelLabels === null) {
            $this->error('Tidak bisa menghubungi AI Microservice.');
            $this->line('  URL  : '.config('services.ai_bert.url'));
            $this->line('  Cek  : apakah service AI sedang berjalan? (lihat ai-service/README.md)');
            $this->newLine();
            $this->warn('Lanjut sajalah - sistem tetap jalan, laporan akan masuk antrean verifikasi manual.');

            return self::FAILURE;
        }

        $categories = Categories::orderBy('ai_label_id')->get();

        $matched = 0;
        $mismatch = [];
        $missingDb = [];

        foreach ($modelLabels as $labelId => $labelName) {
            $category = $categories->firstWhere('ai_label_id', (int) $labelId);

            if (! $category) {
                $missingDb[] = "label {$labelId} (\"{$labelName}\") tidak ada di database";

                continue;
            }

            if ($this->normalize($category->name) === $this->normalize($labelName)) {
                $matched++;
            } else {
                $mismatch[] = "label {$labelId}: model=\"{$labelName}\" vs database=\"{$category->name}\"";
            }
        }

        $orphan = $categories->filter(
            fn (Categories $c) => ! array_key_exists((int) $c->ai_label_id, $modelLabels)
        )->pluck('name');

        $this->line('  Label model   : '.count($modelLabels).' kategori');
        $this->line('  Label database: '.$categories->count().' kategori');
        $this->line("  Cocok         : {$matched}");
        $this->line('  Tidak cocok   : '.count($mismatch));
        $this->newLine();

        foreach ($mismatch as $item) {
            $this->warn('  ! '.$item);
        }

        foreach ($missingDb as $item) {
            $this->warn('  ! '.$item);
        }

        foreach ($orphan as $name) {
            $this->warn('  ! kategori database "'.$name.'" tidak punya label model');
        }

        $problems = count($mismatch) + count($missingDb) + $orphan->count();

        if ($problems === 0) {
            $this->info('  -> KONTRAK SESUAI, aman untuk demo.');

            return self::SUCCESS;
        }

        $this->error("  -> Ada {$problems} ketidaksesuaian. Perbaiki sebelum demo.");
        $this->line('     Tabel categories.ai_label_id harus 1:1 dengan id2label model.');
        $this->line('     Lihat docs/06-ai-bert-dua-model.md bagian "Pemetaan Label".');

        return self::FAILURE;
    }

    protected function fetchModelLabels(): ?array
    {
        try {
            $response = Http::timeout((int) config('services.ai_bert.timeout', 3))
                ->acceptJson()
                ->get(config('services.ai_bert.health_url'));
        } catch (\Throwable $e) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $labels = $response->json('labels.category');

        return is_array($labels) ? $labels : null;
    }

    protected function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?? $value;
    }
}
