<?php

namespace App\Commands;

use App\UF;
use Archive7z\Archive7z;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class ProcessaLogs extends Command
{
    protected $signature = 'logs:processa {uf} {turno}';

    protected $description = 'Processa os arquivos ZIP e importa os logs';

    public function handle(): int
    {
        try {
            /**
             * @var \App\UF $uf
             * @var int $turno
             */
            [$uf, $turno] = $this->argumentos();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return Command::INVALID;
        }

        $this->info('Extraindo ZIP');

        $arquivoZip = "zips/{$uf->value}.zip";

        if (Storage::exists("tmp/{$uf->value}")) {
            Storage::deleteDirectory("tmp/{$uf->value}");
        }

        Storage::makeDirectory("tmp/{$uf->value}");

        if (! $this->extraiZip($arquivoZip, $uf)) {
            $this->error('Falha ao extrair o arquivo ZIP');

            return Command::INVALID;
        }

        $this->info('Processando arquivos');

        $tabela = $this->criaTabela($uf, $turno);

        $arquivos = Storage::files("tmp/{$uf->value}");

        foreach ($arquivos as $original) {
            foreach ($this->processaArquivo($original, $uf) as $entrada) {
                $arquivo = $entrada[0];
                $log = $entrada[1];

                $this->comment('Processando log: ' . $log);

                $linha = 0;
                foreach ($this->processaLog($arquivo, $log, $turno) as $linha => $registro) {
                    DB::table($tabela)->insert($registro);

                    if (($linha + 1) % 1000 === 0) {
                        $this->comment(\number_format($linha + 1) . ' linhas processadas');
                    }
                }

                $this->comment(\number_format($linha + 1) . ' linhas processadas');
            }
        }

        $this->info('Removendo arquivos');

        if (Storage::exists("tmp/{$uf->value}")) {
            Storage::deleteDirectory("tmp/{$uf->value}");
        }

        return Command::SUCCESS;
    }

    private function criaTabela(UF $uf, int $turno): string
    {
        $tabela = Str::lower("logs_{$uf->value}_{$turno}t");

        Schema::dropIfExists($tabela);

        Schema::create($tabela, static function (Blueprint $blueprint) use ($tabela) {
            $blueprint->id();
            $blueprint->dateTime('data_hora');
            $blueprint->string('tipo', 10);
            $blueprint->char('pleito', 8);
            $blueprint->string('evento', 15);
            $blueprint->string('mensagem');
            $blueprint->char('hash', 16)->nullable();
            $blueprint->boolean('voto_computado')->default(false);
            $blueprint->string('arquivo');

            $blueprint->index(['voto_computado', 'arquivo', 'data_hora'], $tabela . '_index');
        });

        return $tabela;
    }

    private function extraiZip(string $arquivo, UF $uf): bool
    {
        $zip = new \ZipArchive();

        $resultado = $zip->open(\storage_path("app/{$arquivo}"), \ZipArchive::RDONLY);

        if ($resultado !== true) {
            $this->info('Erro ao abrir ZIP: ' . $resultado);

            return false;
        }

        $zip->extractTo(\storage_path("app/tmp/{$uf->value}"));

        $zip->close();

        return true;
    }

    private function processaArquivo(string $arquivo, UF $uf)
    {
        if (Str::endsWith($arquivo, ['.pdf', '.bu', '.busa', '.imgbu', '.imgbusa', '.rdv', '.vscmr', '.vscsa'])) {
            return;
        }

        $this->info('Processando arquivo: ' . $arquivo);

        try {
            $log = new Archive7z(\storage_path("app/{$arquivo}"));
        } catch (\Throwable) {
            return;
        }

        if (! $log->isValid()) {
            return;
        }

        foreach ($log->getEntries() as $entry) {
            try {
                $entry->extractTo(\storage_path("app/tmp/{$uf->value}"));
            } catch (\Throwable) {
                return;
            }

            if (Str::endsWith($entry->getPath(), ['.dat'])) {
                yield [$arquivo, "tmp/{$uf->value}/{$entry->getPath()}"];
            } else {
                yield from $this->processaArquivo("tmp/{$uf->value}/{$entry->getPath()}", $uf);
            }
        }
    }

    private function processaLog(string $arquivo, string $log, int $turno)
    {
        $log = \storage_path("app/{$log}");

        if (($handle = \fopen($log, 'r')) === false) {
            return;
        }

        $linha = 0;

        while (($registro = \fgetcsv($handle, 4096, "\t")) !== false) {
            $linha++;

            foreach ($registro as $index => $value) {
                $value = \mb_convert_encoding($value, 'UTF-8', 'ISO-8859-15');

                try {
                    $registro[$index] = match ($index) {
                        0 => CarbonImmutable::createFromFormat('d/m/Y H:i:s', $value),
                        default => \trim((string) $value),
                    };
                } catch (\Throwable $exception) {
                    logger()->error('[ERRO] Erro ao processar registro', [
                        'arquivo' => $arquivo,
                        'linha' => $linha,
                        'registro' => $registro,
                        'erro' => $exception->getMessage(),
                    ]);

                    continue 2;
                }
            }

            $turnoEsperado = match ($turno) {
                1 => $registro[0]->isSameAs('Y-m-d', '2022-10-02'),
                2 => $registro[0]->isSameAs('Y-m-d', '2022-10-30'),
                default => false,
            };

            if (! $turnoEsperado) {
                continue;
            }

            yield [
                'data_hora' => $registro[0]->toDateTimeString(),
                'tipo' => $registro[1],
                'pleito' => $registro[2],
                'evento' => $registro[3],
                'mensagem' => $registro[4],
                'hash' => $registro[5] ?? null,
                'voto_computado' => $registro[3] === 'VOTA'
                    && $registro[4] === 'O voto do eleitor foi computado',
                'arquivo' => $arquivo,
            ];
        }

        \fclose($handle);
    }

    private function argumentos(): array
    {
        $uf = UF::tryFrom($this->argument('uf'));

        if (\is_null($uf)) {
            throw new \InvalidArgumentException('UF inválida');
        }

        $turno = $this->argument('turno');

        if (! \in_array($turno, ['1', '2'])) {
            throw new \InvalidArgumentException('Turno deve ser 1 ou 2');
        }

        return [$uf, \intval($turno)];
    }
}
