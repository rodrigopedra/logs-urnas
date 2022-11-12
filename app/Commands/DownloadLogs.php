<?php

namespace App\Commands;

use App\UF;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;

class DownloadLogs extends Command
{
    private const USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 10_3 like Mac OS X) AppleWebKit/603.1.23 (KHTML, like Gecko) Version/10.0 Mobile/14E5239e Safari/602.1';

    private const URL = 'https://cdn.tse.jus.br/estatistica/sead/eleicoes/eleicoes2022/arqurnatot/bu_imgbu_logjez_rdv_vscmr_2022_2t_%s.zip';

    protected $signature = 'logs:download {uf} {minutos=60}';

    protected $description = 'Baixa logs do TSE';

    public function handle(): int
    {
        try {
            [$uf, $minutos] = $this->argumentos();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return Command::INVALID;
        }

        if (Storage::exists("zips/{$uf->value}.zip")) {
            $this->info("[{$uf->value}] Arquivo ZIP já baixado");

            return Command::SUCCESS;
        }

        $url = \sprintf(self::URL, $uf->value);

        $this->info("[{$uf->value}] Baixando " . $url);

        $milestone = 0.05;

        $resposta = Http::withOptions([
            'progress' => function ($total, $bytes) use (&$milestone, $uf) {
                if (! $total) {
                    return;
                }

                $progresso = $bytes / $total;

                if ($progresso >= $milestone) {
                    $this->comment(\sprintf('[%s] %.2f%% baixados', $uf->value, $milestone * 100.0));
                    $milestone += 0.05;
                }
            },
        ])
            ->throw(fn () => $this->error("[{$uf->value}] Falha ao baixar arquivo ZIP"))
            ->timeout($minutos * 60) // 60 minutos, alguns arquivos são muito grandes
            ->accept('application/zip')
            ->withUserAgent(self::USER_AGENT)
            ->get($url);

        if (! Storage::put("zips/{$uf->value}.zip", $resposta->toPsrResponse()->getBody())) {
            $this->error("[{$uf->value}] Falha ao salvar arquivo ZIP");

            if (Storage::exists("zips/{$uf->value}.zip")) {
                Storage::delete("zips/{$uf->value}.zip");
            }

            return Command::FAILURE;
        }

        $this->info("[{$uf->value}] arquivo ZIP gravado");

        return Command::SUCCESS;
    }

    private function argumentos(): array
    {
        $uf = UF::tryFrom($this->argument('uf'));

        if (\is_null($uf)) {
            throw new \InvalidArgumentException('UF inválida');
        }

        $minutos = $this->argument('minutos');

        if (\preg_match('/^[1-9]\d*$/', $minutos) !== 1) {
            throw new \InvalidArgumentException('Minutos deve ser um número inteiro maior ou igual a um');
        }

        return [$uf, \intval($minutos)];
    }
}
